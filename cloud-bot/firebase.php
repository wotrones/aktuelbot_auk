<?php

declare(strict_types=1);

/**
 * cloud-bot Firebase/Firestore hedef katmani.
 *
 * bot.php'deki Firestore akisinin composer'siz (raw PHP + openssl) halidir:
 *   - Service account ile JWT imzalayip OAuth access token alir (scope: datastore).
 *   - brosurler/ab_{key} dokumanini Firestore REST ile yazar.
 *   - Sayfa gorsellerini kampanyacebimde.com/aktuel/addimage.php'ye yukler, URL'leri saklar.
 *   - marketler/{md5} dokumanini garantiler.
 *   - Yeni brosurde OneSignal push gonderir.
 *
 * NOT: Firestore / addimage / OneSignal istekleri DOGRUDAN gider (proxy KULLANILMAZ);
 *      proxy yalniz kaynak site (aktuelbrosurler.com) icindir -> lib.php.
 */

/**
 * @return array{token: string, project: string, exp: int}
 */
function fb_client(array $cfg): array
{
    static $client = null;
    if ($client !== null && $client['exp'] > time() + 60) {
        return $client;
    }

    $path = $cfg['firebase_credentials'];
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("Firebase service account bulunamadi: {$path}");
    }
    $sa = json_decode((string) file_get_contents($path), true);
    if (!is_array($sa) || empty($sa['project_id']) || empty($sa['client_email']) || empty($sa['private_key'])) {
        throw new RuntimeException('Service account JSON gecersiz (project_id/client_email/private_key eksik).');
    }

    $now = time();
    $claim = [
        'iss' => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/datastore',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ];
    $segments = [
        fb_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
        fb_b64url(json_encode($claim)),
    ];
    $signingInput = implode('.', $segments);
    $signature = '';
    if (!openssl_sign($signingInput, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('JWT imzalanamadi (openssl).');
    }
    $jwt = $signingInput . '.' . fb_b64url($signature);

    [$status, $body] = fb_request('POST', 'https://oauth2.googleapis.com/token', [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]), $cfg['timeout']);

    $json = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($json) || empty($json['access_token'])) {
        throw new RuntimeException("OAuth token alinamadi (HTTP {$status}): {$body}");
    }

    $client = [
        'token' => (string) $json['access_token'],
        'project' => (string) $sa['project_id'],
        'exp' => $now + (int) ($json['expires_in'] ?? 3600),
    ];

    return $client;
}

function fb_b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Dogrudan (proxy'siz) HTTP istegi. Durum kodunu firlatmadan dondurur.
 *
 * @param array<int,string> $headers
 * @return array{0:int,1:string}
 */
function fb_request(string $method, string $url, array $headers, ?string $body, int $timeout): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => max(60, $timeout),
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        throw new RuntimeException("Istek hatasi ({$method} {$url}): {$err}");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    return [$status, (string) $resp];
}

function fb_firestore_base(array $cfg): string
{
    $c = fb_client($cfg);
    return "https://firestore.googleapis.com/v1/projects/{$c['project']}/databases/(default)/documents/";
}

/** Dokuman var mi? */
function fb_document_exists(array $cfg, string $path): bool
{
    $c = fb_client($cfg);
    [$status] = fb_request('GET', fb_firestore_base($cfg) . $path, [
        'Authorization: Bearer ' . $c['token'],
    ], null, $cfg['timeout']);
    if ($status === 404) {
        return false;
    }
    if ($status >= 200 && $status < 300) {
        return true;
    }
    throw new RuntimeException("Firestore GET {$path} HTTP {$status}");
}

/** Dokumani yazar (create/update). $fields: ham PHP degerleri. */
function fb_document_set(array $cfg, string $path, array $fields): void
{
    $c = fb_client($cfg);
    $doc = ['fields' => []];
    foreach ($fields as $k => $v) {
        $doc['fields'][$k] = fb_to_value($v);
    }
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    [$status, $body] = fb_request('PATCH', fb_firestore_base($cfg) . $path, [
        'Authorization: Bearer ' . $c['token'],
        'Content-Type: application/json',
    ], $json, $cfg['timeout']);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Firestore PATCH {$path} HTTP {$status}: {$body}");
    }
}

/**
 * Dokumanin YALNIZCA verilen alanlarini gunceller (updateMask). fb_document_set
 * maskesiz PATCH attigi icin tum dokumani degistirir; mevcut dokumana alan
 * eklemek/guncellemek icin bu kullanilmali (backfill boyle yapar).
 */
function fb_document_update_fields(array $cfg, string $path, array $fields): void
{
    $c = fb_client($cfg);
    $doc = ['fields' => []];
    $mask = [];
    foreach ($fields as $k => $v) {
        $doc['fields'][$k] = fb_to_value($v);
        $mask[] = 'updateMask.fieldPaths=' . rawurlencode($k);
    }
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $url = fb_firestore_base($cfg) . $path . '?' . implode('&', $mask);
    [$status, $body] = fb_request('PATCH', $url, [
        'Authorization: Bearer ' . $c['token'],
        'Content-Type: application/json',
    ], $json, $cfg['timeout']);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Firestore PATCH(mask) {$path} HTTP {$status}: {$body}");
    }
}

/**
 * Aktif (bitis tarihi bugunden ileri) brosurleri dondurur:
 * list<{id, gorseller: list<string>, has_ocr: bool}>. Backfill icin.
 */
function fb_list_active_brochures(array $cfg): array
{
    $c = fb_client($cfg);
    $today = (new DateTimeImmutable('today'))->format(DateTimeInterface::RFC3339);
    $q = [
        'structuredQuery' => [
            'from' => [['collectionId' => 'brosurler']],
            'where' => [
                'fieldFilter' => [
                    'field' => ['fieldPath' => 'end_date'],
                    'op' => 'GREATER_THAN_OR_EQUAL',
                    'value' => ['timestampValue' => $today],
                ],
            ],
            'limit' => 300,
        ],
    ];
    $base = "https://firestore.googleapis.com/v1/projects/{$c['project']}/databases/(default)/documents:runQuery";
    [$status, $body] = fb_request('POST', $base, [
        'Authorization: Bearer ' . $c['token'],
        'Content-Type: application/json',
    ], json_encode($q), $cfg['timeout']);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Firestore runQuery HTTP {$status}: {$body}");
    }
    $rows = json_decode($body, true);
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $doc = $row['document'] ?? null;
        if (!is_array($doc)) {
            continue;
        }
        $fields = $doc['fields'] ?? [];
        $gorseller = [];
        foreach ($fields['gorseller']['arrayValue']['values'] ?? [] as $v) {
            $u = (string) ($v['stringValue'] ?? '');
            if ($u !== '') {
                $gorseller[] = $u;
            }
        }
        $out[] = [
            'id' => basename((string) $doc['name']),
            'gorseller' => $gorseller,
            'has_ocr' => isset($fields['ocr_sayfalar']),
        ];
    }
    return $out;
}

/**
 * Sunucudaki ocr.php'nin 'path' modu: gorsel zaten sunucudaysa (uploads URL'i),
 * baytlari tekrar gondermeden yerinden OCR'lat. Sunucu OCR'i kapali/hataliysa
 * bos string doner (backfill sirada bekler, import akisini etkilemez).
 */
function fb_ocr_remote_path(array $cfg, string $imageUrl): string
{
    $endpoint = (string) ($cfg['ocr_url'] ?? '');
    if ($endpoint === '') {
        return '';
    }
    $fields = ['path' => $imageUrl];
    if (($cfg['ocr_token'] ?? '') !== '') {
        $fields['token'] = $cfg['ocr_token'];
    }
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => max(60, (int) $cfg['timeout']),
        CURLOPT_POSTFIELDS => $fields,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    if ($status >= 200 && $status < 300 && is_array($json) && !empty($json['ok'])) {
        return (string) ($json['text'] ?? '');
    }
    cb_debug("Backfill OCR basarisiz (HTTP {$status}): {$imageUrl}");
    return '';
}

/**
 * PHP degerini Firestore tip-degerine cevirir (bot.php toFirestoreValue ile ayni).
 * DateTimeInterface -> timestampValue.
 */
function fb_to_value($value): array
{
    if (is_null($value)) {
        return ['nullValue' => null];
    }
    if (is_bool($value)) {
        return ['booleanValue' => $value];
    }
    if (is_int($value)) {
        return ['integerValue' => (string) $value];
    }
    if (is_float($value)) {
        return ['doubleValue' => $value];
    }
    if (is_string($value)) {
        return ['stringValue' => $value];
    }
    if ($value instanceof DateTimeInterface) {
        return ['timestampValue' => $value->format(DateTimeInterface::RFC3339)];
    }
    if (is_array($value)) {
        if ($value === [] || array_keys($value) === range(0, count($value) - 1)) {
            return ['arrayValue' => ['values' => array_map('fb_to_value', $value)]];
        }
        $fields = [];
        foreach ($value as $k => $v) {
            $fields[(string) $k] = fb_to_value($v);
        }
        return ['mapValue' => ['fields' => $fields]];
    }

    return ['stringValue' => (string) $value];
}

/** Market gorunen adindan Firestore dokuman ID'si (bot.php ile ayni: md5). */
function fb_market_doc_id(string $displayName): string
{
    static $seed = ['Tarım Kooperatif' => 'Tarım Kredi Kooperatif Market'];
    $s = $seed[$displayName] ?? $displayName;

    return md5(mb_strtolower($s, 'UTF-8'));
}

/** marketler/{id} yoksa olusturur (logo best-effort). */
function fb_ensure_market(array $cfg, string $marketName): void
{
    $marketName = trim($marketName);
    if ($marketName === '' || $marketName === 'Diger' || $marketName === 'Diğer') {
        return;
    }
    $id = fb_market_doc_id($marketName);
    if (fb_document_exists($cfg, "marketler/{$id}")) {
        return;
    }

    cb_log("Yeni market: {$marketName} -> olusturuluyor.");
    $logoUrl = null;
    try {
        $found = fb_find_logo($cfg, $marketName);
        if ($found !== null) {
            $logoUrl = fb_upload_image_url($cfg, $found);
        }
    } catch (Throwable $e) {
        cb_debug('Logo bulunamadi/yuklenemedi: ' . $e->getMessage());
    }

    fb_document_set($cfg, "marketler/{$id}", [
        'name' => $marketName,
        'logo' => $logoUrl,
    ]);
    cb_log("Market eklendi: {$marketName} ({$id})");
}

/** Google gorsellerinden logo bulmaya calisir (best-effort, basarisizsa null). */
function fb_find_logo(array $cfg, string $marketName): ?string
{
    try {
        $q = urlencode("{$marketName} market logo filetype:png");
        // Bu istek de datacenter'dan engellenebilir; lib.php proxy'sini kullan.
        $r = cb_http_get("https://www.google.com/search?q={$q}&tbm=isch", cb_browser_headers([]), $cfg['timeout'], null);
        if (preg_match_all('/<img[^>]+src="([^">]+)"/i', $r['body'], $m)) {
            foreach ($m[1] as $src) {
                if (str_starts_with($src, 'http') && !str_contains($src, 'google.com')) {
                    return $src;
                }
                if (str_contains($src, 'encrypted-tbn')) {
                    return $src;
                }
            }
        }
    } catch (Throwable $e) {
        cb_debug('Google logo arama hatasi: ' . $e->getMessage());
    }

    return null;
}

/** Verilen URL'deki gorseli indirir (proxy ile) ve addimage'e yukler; barindirilen URL doner. */
function fb_upload_image_url(array $cfg, string $imageUrl): string
{
    $r = cb_http_get($imageUrl, cb_browser_headers([]), $cfg['timeout'], null);
    $name = basename((string) parse_url($imageUrl, PHP_URL_PATH)) ?: 'logo.png';

    return fb_addimage($cfg, $r['body'], $name);
}

/**
 * Ham sayfa baytini JPEG'e cevirip addimage'e yukler; barindirilan URL doner.
 * Cevrilemezse ham bayt yuklenir.
 */
function fb_upload_page(array $cfg, string $bytes, string $sourceName): string
{
    $jpeg = fb_to_jpeg($bytes);
    if ($jpeg !== null) {
        $name = 'page_' . substr(md5($sourceName . strlen($bytes)), 0, 16) . '.jpg';
        return fb_addimage($cfg, $jpeg, $name);
    }
    $name = $sourceName !== '' ? $sourceName : 'page.webp';

    return fb_addimage($cfg, $bytes, $name);
}

/** addimage.php'ye multipart 'source' alani ile yukler; json['image'] doner. */
function fb_addimage(array $cfg, string $bytes, string $filename): string
{
    $endpoint = $cfg['image_upload_endpoint'];
    if ($endpoint === '') {
        throw new RuntimeException('IMAGE_UPLOAD_ENDPOINT tanimli degil.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'cbimg_');
    if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
        throw new RuntimeException('Gecici gorsel dosyasi olusturulamadi.');
    }
    try {
        $fields = ['source' => new CURLFile($tmp, 'application/octet-stream', $filename)];
        $headers = [];
        if (($cfg['image_upload_token'] ?? '') !== '') {
            $fields['token'] = $cfg['image_upload_token'];
            $headers[] = 'Authorization: Bearer ' . $cfg['image_upload_token'];
        }
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => max(60, (int) $cfg['timeout']),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $fields,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            throw new RuntimeException('addimage istegi hatasi: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $json = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300 || !is_array($json) || empty($json['image'])) {
            throw new RuntimeException("addimage gecersiz yanit (HTTP {$status}): {$body}");
        }

        return (string) $json['image'];
    } finally {
        @unlink($tmp);
    }
}

/**
 * Sayfa gorselinin OCR metni (uygulamadaki urun aramasi icin).
 *
 * Oncelik sunucudaki ocr.php (OCR_URL); hata/bos yanit durumunda kosucudaki
 * tesseract'a (cb_ocr_image_to_text) dusulur. Hicbiri calismazsa bos string
 * doner — OCR eksikligi import'u ASLA durdurmaz.
 */
function fb_ocr_page_text(array $cfg, string $bytes, string $filename): string
{
    $endpoint = (string) ($cfg['ocr_url'] ?? '');
    if ($endpoint !== '') {
        $tmp = tempnam(sys_get_temp_dir(), 'cbocr_');
        if ($tmp !== false && file_put_contents($tmp, $bytes) !== false) {
            try {
                $fields = ['source' => new CURLFile($tmp, 'application/octet-stream', $filename)];
                if (($cfg['ocr_token'] ?? '') !== '') {
                    $fields['token'] = $cfg['ocr_token'];
                }
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_TIMEOUT => max(60, (int) $cfg['timeout']),
                    CURLOPT_POSTFIELDS => $fields,
                ]);
                $body = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                $json = is_string($body) ? json_decode($body, true) : null;
                if ($status >= 200 && $status < 300 && is_array($json) && !empty($json['ok'])) {
                    return (string) ($json['text'] ?? '');
                }
                cb_debug("Sunucu OCR basarisiz (HTTP {$status}), yerel tesseract'a dusuluyor.");
            } catch (Throwable $e) {
                cb_debug('Sunucu OCR hatasi: ' . $e->getMessage());
            } finally {
                @unlink($tmp);
            }
        }
    }

    // Yedek: kosucudaki tesseract (tarih OCR'i ile ayni altyapi).
    try {
        return cb_ocr_image_to_text($bytes);
    } catch (Throwable $e) {
        return '';
    }
}

/** Ham gorsel baytini JPEG'e cevirir (imagick veya gd). Olmazsa null. */
function fb_to_jpeg(string $binary, int $quality = 88): ?string
{
    if ($binary === '') {
        return null;
    }
    if (extension_loaded('imagick')) {
        try {
            $im = new Imagick();
            $im->readImageBlob($binary);
            $im->setImageBackgroundColor(new ImagickPixel('white'));
            if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
                $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            }
            $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality($quality);
            $out = $im->getImageBlob();
            $im->clear();
            if ($out !== '') {
                return $out;
            }
        } catch (Throwable $e) {
            // GD'ye dus
        }
    }
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    $src = @imagecreatefromstring($binary);
    if ($src === false) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $dst = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
    ob_start();
    imagejpeg($dst, null, $quality);
    $jpeg = ob_get_clean();

    return ($jpeg !== false && $jpeg !== '') ? $jpeg : null;
}

/**
 * Indirilen sayfalari addimage'e yukler ve Firestore'a brosur dokumani yazar.
 *
 * @param array{href:string,market:string,title:string,brochure_key:string,source_key:string} $item
 * @param list<array{name:string,bytes:string}> $pages
 * @param array{start:string,end:string,matched:bool} $dates
 */
function fb_import_brochure(array $cfg, array $item, array $pages, array $dates): bool
{
    $docId = $item['source_key']; // ab_{key}

    // Tarihler: basliktan/OCR'dan cikarilan (cb_fetch_brochure). Cozulemediyse
    // (matched=false) bugun -> bugun + BROCHURE_VALID_DAYS'e dus. Once hesapla ki
    // suresi gecmis brosuru gorsel yuklemeden/yazmadan eleyebilelim.
    try {
        $start = new DateTimeImmutable(($dates['start'] ?? '') !== '' ? $dates['start'] : 'now');
    } catch (Throwable $e) {
        $start = new DateTimeImmutable('now');
    }
    $end = null;
    if (!empty($dates['matched']) && ($dates['end'] ?? '') !== '') {
        try {
            $end = new DateTimeImmutable($dates['end']);
        } catch (Throwable $e) {
            $end = null;
        }
    }
    if ($end === null || $end < $start) {
        $end = $start->modify('+' . $cfg['valid_days'] . ' days');
    }

    // Guvenlik agi: suresi gecmis brosuru yazma/push atma (uygulama gostermez).
    if ($end < new DateTimeImmutable('today')) {
        cb_log("Suresi gecmis, yazilmadi: brosurler/{$docId} (bitis {$end->format('Y-m-d')})");
        return false;
    }

    cb_log(sprintf(
        "Tarih: %s -> %s (%s)",
        $start->format('Y-m-d'),
        $end->format('Y-m-d'),
        !empty($dates['matched']) ? 'kaynaktan' : 'varsayilan'
    ));

    fb_ensure_market($cfg, $item['market']);

    $imageUrls = [];
    $ocrTexts = [];
    $ocrHits = 0;
    foreach ($pages as $i => $page) {
        $imageUrls[] = fb_upload_page($cfg, $page['bytes'], $page['name']);
        cb_debug('Gorsel yuklendi [' . ($i + 1) . '/' . count($pages) . ']');
        // Urun aramasi icin sayfa OCR'i. Sayfa sirasi gorseller ile birebir
        // ayni tutulur; okunamayan sayfa bos string olarak yerini korur.
        $text = fb_ocr_page_text($cfg, $page['bytes'], $page['name']);
        if (mb_strlen($text, 'UTF-8') > 3500) {
            $text = mb_substr($text, 0, 3500, 'UTF-8');
        }
        $ocrTexts[] = $text;
        if ($text !== '') {
            $ocrHits++;
        }
        cb_sleep_ms($cfg['request_delay_ms']);
    }

    $doc = [
        'market_adi' => $item['market'],
        'start_date' => $start,
        'end_date' => $end,
        // Gercek eklenme ani. start_date brosurun BASILI gecerlilik baslangici
        // oldugu icin "son eklenenler" siralamasi icin guvenilir degil; uygulama
        // ileride bu alana gecebilsin diye simdiden yaziliyor.
        'created_at' => new DateTimeImmutable('now'),
        'gorseller' => $imageUrls,
        'clicks' => 0,
        'favs' => 0,
    ];
    if ($ocrHits > 0) {
        $doc['ocr_sayfalar'] = $ocrTexts;
    }
    fb_document_set($cfg, "brosurler/{$docId}", $doc);
    cb_log("OCR: {$ocrHits}/" . count($pages) . ' sayfa metni cikarildi.');
    cb_log("Firestore'a yazildi: brosurler/{$docId} ({$item['market']}, " . count($imageUrls) . ' sayfa)');

    // NOT: Bildirim burada gonderilmez. Ayni kosuda ayni marketten birden cok
    // brosur islendiginde kullaniciya ayni metinle 2-3 ayri push gitmesin diye
    // cb_drain_queue kosu sonunda market basina TEK ozet bildirim gonderir.

    return true;
}

/** Yeni brosur icin OneSignal push. $count > 1 ise ozet metin gonderilir. */
function fb_onesignal_notify(array $cfg, string $marketName, string $brochureDocId, int $count = 1): void
{
    $appId = $cfg['onesignal_app_id'];
    $restKey = $cfg['onesignal_rest_api_key'];
    if ($appId === '' || $restKey === '') {
        cb_log('OneSignal: APP_ID/REST_API_KEY eksik, bildirim atlandi.');
        return;
    }

    $marketDocId = fb_market_doc_id($marketName);
    $title = 'Yeni broşür';
    $bodyText = $count > 1
        ? "{$marketName} için {$count} yeni broşür eklendi."
        : "{$marketName} için yeni broşür eklendi.";

    $payload = [
        'app_id' => $appId,
        'target_channel' => 'push',
        'headings' => ['en' => $title, 'tr' => $title],
        'contents' => ['en' => $bodyText, 'tr' => $bodyText],
        // Tüm abonelenmiş push kullanıcılarına gönder (etiket filtresi kaldırıldı).
        'included_segments' => ['Total Subscriptions'],
        // Ayni marketin kisa araliklarla gelen bildirimleri tepside ust uste
        // birikmesin; yenisi eskisinin yerine gecsin.
        'collapse_id' => 'brosur_' . $marketDocId,
        'data' => [
            'type' => 'new_brosur',
            'brochure_id' => $brochureDocId,
            'market_id' => $marketDocId,
            'market_adi' => $marketName,
        ],
    ];
    $legacy = $payload;
    unset($legacy['target_channel']);

    $endpoints = [
        ['https://api.onesignal.com/notifications', 'Key ' . $restKey, $payload],
        ['https://onesignal.com/api/v1/notifications', 'Basic ' . base64_encode($restKey . ':'), $legacy],
    ];

    $lastError = '';
    foreach ($endpoints as [$url, $auth, $bodyArr]) {
        try {
            [$status, $body] = fb_request('POST', $url, [
                'Authorization: ' . $auth,
                'Content-Type: application/json; charset=utf-8',
            ], json_encode($bodyArr, JSON_UNESCAPED_UNICODE), $cfg['timeout']);
            $json = json_decode($body, true);
            if ($status >= 200 && $status < 300 && is_array($json) && !empty($json['id'])) {
                $rec = $json['recipients'] ?? $json['successful'] ?? '?';
                cb_log("OneSignal kabul etti (HTTP {$status}). id={$json['id']} alici={$rec}");
                return;
            }
            // Basarisiz yanit: OneSignal'in gercek hata govdesini sakla (teshis icin).
            $snippet = trim(mb_substr((string) $body, 0, 300));
            $lastError = "HTTP {$status} — {$snippet}";
            cb_log("OneSignal reddetti [{$url}]: {$lastError}");
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
            cb_debug('OneSignal endpoint hatasi: ' . $lastError);
        }
    }
    cb_log('OneSignal: bildirim gonderilemedi (her iki endpoint de basarisiz). Son hata: ' . $lastError);
}
