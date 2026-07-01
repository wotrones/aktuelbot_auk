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
 */
function fb_import_brochure(array $cfg, array $item, array $pages): void
{
    $docId = $item['source_key']; // ab_{key}

    fb_ensure_market($cfg, $item['market']);

    $imageUrls = [];
    foreach ($pages as $i => $page) {
        $imageUrls[] = fb_upload_page($cfg, $page['bytes'], $page['name']);
        cb_debug('Gorsel yuklendi [' . ($i + 1) . '/' . count($pages) . ']');
        cb_sleep_ms($cfg['request_delay_ms']);
    }

    $start = new DateTimeImmutable('now');
    $end = $start->modify('+' . $cfg['valid_days'] . ' days');

    fb_document_set($cfg, "brosurler/{$docId}", [
        'market_adi' => $item['market'],
        'start_date' => $start,
        'end_date' => $end,
        'gorseller' => $imageUrls,
        'clicks' => 0,
        'favs' => 0,
    ]);
    cb_log("Firestore'a yazildi: brosurler/{$docId} ({$item['market']}, " . count($imageUrls) . ' sayfa)');

    if ($cfg['onesignal_enabled']) {
        try {
            fb_onesignal_notify($cfg, $item['market'], $docId);
        } catch (Throwable $e) {
            cb_log('OneSignal bildirim hatasi: ' . $e->getMessage());
        }
    }
}

/** Yeni brosur icin OneSignal push (tag filtresi) — bot.php ile ayni. */
function fb_onesignal_notify(array $cfg, string $marketName, string $brochureDocId): void
{
    $appId = $cfg['onesignal_app_id'];
    $restKey = $cfg['onesignal_rest_api_key'];
    if ($appId === '' || $restKey === '') {
        cb_log('OneSignal: APP_ID/REST_API_KEY eksik, bildirim atlandi.');
        return;
    }

    $marketDocId = fb_market_doc_id($marketName);
    $title = 'Yeni broşür';
    $bodyText = "{$marketName} için yeni broşür eklendi.";

    $payload = [
        'app_id' => $appId,
        'target_channel' => 'push',
        'headings' => ['en' => $title, 'tr' => $title],
        'contents' => ['en' => $bodyText, 'tr' => $bodyText],
        // Tüm abonelenmiş push kullanıcılarına gönder (etiket filtresi kaldırıldı).
        'included_segments' => ['Subscribed Users'],
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
