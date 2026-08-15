<?php

declare(strict_types=1);

/**
 * kampanya_bot.php — getkampania.com'dan kampanya çeker, Firestore'a yazar.
 *
 * Kullanıcının sunucusunda cron ile çalışır (GitHub Actions DEĞİL). Manuel
 * kampanya ekleme yok; tek kaynak bu bot. Uygulamadaki "Kampanyalar" sekmesi
 * `kampanyalar` koleksiyonunu okur.
 *
 * Veri kaynağı: sitenin sektör sayfalarının SSR çıktısına gömülü JSON
 * (self.__next_f flight verisi). Sayfa başına en yeni ~12 kampanya gelir;
 * bot her koşuda 14 sektörü tarar, YENİ hash_id'leri detay sayfasından
 * tarih bilgisiyle zenginleştirip yazar. Zamanla katalog kendiliğinden büyür.
 *
 * Kurulum (örnek):
 *   /var/www/vhosts/kampanyacebimde.com/kampanya-bot/kampanya_bot.php
 *   yanına config.php (aşağıdaki sabitleri override eder) ve auk.json koyun.
 *   crontab: 0 *\/4 * * *  php /path/kampanya_bot.php >> /path/bot.log 2>&1
 *
 * Proxy: PROXY_URL doluysa getkampania.com istekleri proxy üzerinden gider
 * (Firestore istekleri her zaman doğrudan).
 */

// ============================ AYARLAR ============================
// Bu sabitler yanına konan config.php ile override edilebilir.

$CFG = [
    'firebase_credentials' => __DIR__ . '/auk.json',
    'proxy_url' => '',            // örn. http://user:pass@host:port
    'source_base' => 'https://www.getkampania.com',
    'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36',
    'request_delay_ms' => 1200,   // kaynak istekleri arası bekleme
    'timeout' => 45,
    'max_new_per_run' => 40,      // bir koşuda en fazla bu kadar YENİ kampanya işlenir
    'collection' => 'kampanyalar',
];

if (is_file(__DIR__ . '/config.php')) {
    $override = require __DIR__ . '/config.php';
    if (is_array($override)) {
        $CFG = array_merge($CFG, $override);
    }
}

$SEKTORLER = [
    'akaryakit', 'arac', 'e-ticaret', 'egitim-kirtasiye', 'eglence',
    'elektronik', 'dekorasyon', 'moda-kozmetik', 'market', 'saglik',
    'seyahat', 'yeme-icme', 'yurt-disi', 'diger',
];

// ================================================================

function k_log(string $msg): void
{
    echo '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $msg . PHP_EOL;
}

function k_sleep_ms(int $ms): void
{
    if ($ms > 0) {
        usleep($ms * 1000);
    }
}

/** Kaynak sitesine HTTP GET (proxy varsa proxy üzerinden). */
function k_source_get(array $cfg, string $url): string
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => $cfg['timeout'],
        CURLOPT_USERAGENT => $cfg['user_agent'],
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: tr-TR,tr;q=0.9',
        ],
        CURLOPT_ENCODING => '',
    ];
    if (($cfg['proxy_url'] ?? '') !== '') {
        $opts[CURLOPT_PROXY] = $cfg['proxy_url'];
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException("Kaynak GET {$url} basarisiz (HTTP {$status}) {$err}");
    }
    return (string) $body;
}

/** Next.js flight verisini (self.__next_f push string'leri) tek metne çevirir. */
function k_flight_blob(string $html): string
{
    if (!preg_match_all('/self\.__next_f\.push\(\[1,"(.*?)"\]\)<\/script>/s', $html, $m)) {
        return '';
    }
    $blob = '';
    foreach ($m[1] as $chunk) {
        // JS string kaçışlarını çöz (\" \\ \n \uXXXX).
        $decoded = json_decode('"' . $chunk . '"');
        $blob .= is_string($decoded) ? $decoded : '';
    }
    return $blob;
}

/** Blob içinde $anchor'dan başlayan dengeli JSON dizisini döndürür. */
function k_extract_array(string $blob, string $anchor): ?array
{
    $i = strpos($blob, $anchor);
    if ($i === false) {
        return null;
    }
    $start = strpos($blob, '[', $i);
    if ($start === false) {
        return null;
    }
    $depth = 0;
    $inStr = false;
    $esc = false;
    $len = strlen($blob);
    for ($p = $start; $p < $len; $p++) {
        $c = $blob[$p];
        if ($inStr) {
            if ($esc) {
                $esc = false;
            } elseif ($c === '\\') {
                $esc = true;
            } elseif ($c === '"') {
                $inStr = false;
            }
            continue;
        }
        if ($c === '"') {
            $inStr = true;
        } elseif ($c === '[') {
            $depth++;
        } elseif ($c === ']') {
            $depth--;
            if ($depth === 0) {
                $json = substr($blob, $start, $p - $start + 1);
                $arr = json_decode($json, true);
                return is_array($arr) ? $arr : null;
            }
        }
    }
    return null;
}

/** Detay sayfasının JSON-LD Offer bloğundan tarihleri çeker. */
function k_detail_dates(array $cfg, string $slug): array
{
    $out = ['start' => null, 'end' => null];
    try {
        $html = k_source_get($cfg, $cfg['source_base'] . '/kampanyalar/' . $slug);
    } catch (Throwable $e) {
        k_log('Detay alinamadi (' . $slug . '): ' . $e->getMessage());
        return $out;
    }
    if (preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $m)) {
        foreach ($m[1] as $json) {
            $d = json_decode($json, true);
            $items = isset($d['@type']) ? [$d] : (is_array($d) ? $d : []);
            foreach ($items as $it) {
                if (($it['@type'] ?? '') === 'Offer') {
                    $out['start'] = $it['validFrom'] ?? null;
                    $out['end'] = $it['validThrough'] ?? null;
                    return $out;
                }
            }
        }
    }
    return $out;
}

// ---------------------------- Firestore ----------------------------

function k_fb_client(array $cfg): array
{
    static $cache = null;
    if ($cache !== null && $cache['exp'] > time() + 60) {
        return $cache;
    }
    $sa = json_decode((string) file_get_contents($cfg['firebase_credentials']), true);
    if (!is_array($sa)) {
        throw new RuntimeException('Service account okunamadi: ' . $cfg['firebase_credentials']);
    }
    $now = time();
    $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = $b64(json_encode([
        'iss' => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/datastore',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    $sig = '';
    openssl_sign($header . '.' . $claims, $sig, $sa['private_key'], 'sha256WithRSAEncryption');
    $jwt = $header . '.' . $claims . '.' . $b64($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
    ]);
    $body = curl_exec($ch);
    $tok = json_decode((string) $body, true);
    if (empty($tok['access_token'])) {
        throw new RuntimeException('Google token alinamadi: ' . (string) $body);
    }
    $cache = [
        'token' => (string) $tok['access_token'],
        'project' => (string) $sa['project_id'],
        'exp' => $now + (int) ($tok['expires_in'] ?? 3600),
    ];
    return $cache;
}

function k_fb_request(array $cfg, string $method, string $url, ?array $payload = null): array
{
    $c = k_fb_client($cfg);
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $c['token']];
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $cfg['timeout'],
    ];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$status, (string) $body];
}

function k_fb_value($v): array
{
    if (is_null($v)) return ['nullValue' => null];
    if (is_bool($v)) return ['booleanValue' => $v];
    if (is_int($v)) return ['integerValue' => (string) $v];
    if (is_float($v)) return ['doubleValue' => $v];
    if ($v instanceof DateTimeInterface) return ['timestampValue' => $v->format(DateTimeInterface::RFC3339)];
    if (is_array($v)) {
        if ($v === [] || array_keys($v) === range(0, count($v) - 1)) {
            return ['arrayValue' => ['values' => array_map('k_fb_value', $v)]];
        }
        $f = [];
        foreach ($v as $k => $x) { $f[(string) $k] = k_fb_value($x); }
        return ['mapValue' => ['fields' => $f]];
    }
    return ['stringValue' => (string) $v];
}

/** Koleksiyondaki mevcut doküman id'lerini döndürür (varlık kontrolü için). */
function k_fb_existing_ids(array $cfg): array
{
    $c = k_fb_client($cfg);
    $ids = [];
    $pageToken = '';
    do {
        $url = "https://firestore.googleapis.com/v1/projects/{$c['project']}/databases/(default)/documents/{$cfg['collection']}?pageSize=300&mask.fieldPaths=__name__" . ($pageToken !== '' ? "&pageToken={$pageToken}" : '');
        [$status, $body] = k_fb_request($cfg, 'GET', $url);
        if ($status === 404) {
            break; // koleksiyon henuz yok
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Firestore list HTTP {$status}: {$body}");
        }
        $d = json_decode($body, true);
        foreach ($d['documents'] ?? [] as $doc) {
            $ids[basename((string) $doc['name'])] = true;
        }
        $pageToken = (string) ($d['nextPageToken'] ?? '');
    } while ($pageToken !== '');
    return $ids;
}

function k_fb_set(array $cfg, string $docId, array $fields): void
{
    $c = k_fb_client($cfg);
    $doc = ['fields' => []];
    foreach ($fields as $k => $v) {
        $doc['fields'][$k] = k_fb_value($v);
    }
    $url = "https://firestore.googleapis.com/v1/projects/{$c['project']}/databases/(default)/documents/{$cfg['collection']}/{$docId}";
    [$status, $body] = k_fb_request($cfg, 'PATCH', $url, $doc);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Firestore PATCH {$docId} HTTP {$status}: {$body}");
    }
}

// ------------------------------ Akış ------------------------------

try {
    $cfg = $CFG;
    if (!is_file($cfg['firebase_credentials'])) {
        throw new RuntimeException('Service account bulunamadi: ' . $cfg['firebase_credentials']);
    }
    k_log('Kampanya botu basladi.' . (($cfg['proxy_url'] ?? '') !== '' ? ' (proxy aktif)' : ' (proxy YOK)'));

    $existing = k_fb_existing_ids($cfg);
    k_log('Firestore mevcut kampanya: ' . count($existing));

    global $SEKTORLER;
    $seen = [];
    $newQueue = [];
    foreach ($SEKTORLER as $sektor) {
        try {
            $html = k_source_get($cfg, $cfg['source_base'] . '/sektorler/' . $sektor);
        } catch (Throwable $e) {
            k_log("Sektor alinamadi ({$sektor}): " . $e->getMessage());
            k_sleep_ms($cfg['request_delay_ms']);
            continue;
        }
        $blob = k_flight_blob($html);
        $campaigns = k_extract_array($blob, '"campaigns":');
        if ($campaigns === null) {
            k_log("Sektor parse edilemedi ({$sektor}) — site yapisi degismis olabilir.");
            k_sleep_ms($cfg['request_delay_ms']);
            continue;
        }
        $yeni = 0;
        foreach ($campaigns as $c) {
            $hid = (string) ($c['hash_id'] ?? '');
            if ($hid === '' || isset($seen[$hid])) {
                continue;
            }
            $seen[$hid] = true;
            if (isset($existing[$hid])) {
                continue;
            }
            $newQueue[] = $c;
            $yeni++;
        }
        k_log("Sektor {$sektor}: " . count($campaigns) . ' kampanya, yeni: ' . $yeni);
        k_sleep_ms($cfg['request_delay_ms']);
    }

    if ($newQueue === []) {
        k_log('Yeni kampanya yok. Bitti.');
        exit(0);
    }

    $islenen = 0;
    foreach (array_slice($newQueue, 0, $cfg['max_new_per_run']) as $c) {
        $hid = (string) $c['hash_id'];
        $slug = (string) ($c['slug'] ?? '');
        $campaigner = $c['campaigners'][0] ?? [];
        $kategori = $c['categories'][0] ?? [];
        $marka = $c['brands'][0]['name'] ?? ($campaigner['name'] ?? '');

        $dates = $slug !== '' ? k_detail_dates($cfg, $slug) : ['start' => null, 'end' => null];
        k_sleep_ms($cfg['request_delay_ms']);

        $start = null;
        $end = null;
        try {
            $start = $dates['start'] ? new DateTimeImmutable((string) $dates['start']) : null;
            $end = $dates['end'] ? new DateTimeImmutable(((string) $dates['end']) . ' 23:59:59') : null;
        } catch (Throwable $e) {
            // tarih parse edilemezse null kalir
        }

        $fields = [
            'baslik' => (string) ($c['title'] ?? ''),
            'slug' => $slug,
            'resim' => (string) ($c['image_url'] ?? ''),
            'kategori' => (string) ($kategori['name'] ?? 'Diğer'),
            'kategori_slug' => (string) ($kategori['slug'] ?? 'diger'),
            'marka' => (string) $marka,
            'marka_logo' => (string) ($campaigner['logo_url'] ?? ''),
            'kart_adi' => (string) ($campaigner['name'] ?? ''),
            'basvuru_url' => (string) ($campaigner['apply_url'] ?? ''),
            'sponsorlu' => (bool) ($campaigner['is_sponsored'] ?? false),
            'kazanc_tipi' => (string) ($c['earning_type'] ?? ''),
            'aciklama' => (string) ($campaigner['description'] ?? ''),
            'kaynak_url' => $cfg['source_base'] . '/kampanyalar/' . $slug,
            'baslangic' => $start,
            'bitis' => $end,
            'created_at' => new DateTimeImmutable('now'),
            'aktif' => true,
        ];
        try {
            k_fb_set($cfg, $hid, $fields);
            $islenen++;
            k_log("Yazildi: {$hid} [{$fields['kategori']}] {$fields['baslik']}" . ($end ? ' (bitis ' . $end->format('Y-m-d') . ')' : ''));
        } catch (Throwable $e) {
            k_log("Yazma hatasi ({$hid}): " . $e->getMessage());
        }
    }

    $kalan = count($newQueue) - $islenen;
    k_log("Bitti. Yazilan: {$islenen}" . ($kalan > 0 ? " | siradaki kosuya kalan: {$kalan}" : ''));
} catch (Throwable $e) {
    fwrite(STDERR, 'HATA: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
