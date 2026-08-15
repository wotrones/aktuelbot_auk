<?php

declare(strict_types=1);

/**
 * kampanya_bot.php — rivoxe.com'dan kampanya çeker, Firestore'a yazar.
 *
 * Kullanıcının sunucusunda cron ile çalışır (GitHub Actions DEĞİL). Manuel
 * kampanya ekleme yok; tek kaynak bu bot. Uygulamadaki "Kampanyalar" sekmesi
 * `kampanyalar` koleksiyonunu okur.
 *
 * Veri kaynağı: /kampanyalar?page=N listeleme sayfalarındaki kart HTML'i
 * (kategori, marka, logo, sponsor/popüler rozeti, bitiş tarihi karttadır).
 * YENİ kampanyaların detay sayfasından "Kampanyaya Git" linki (markanın
 * kendi sayfası) ve açıklama (JSON-LD Article) alınır.
 *
 * KURAL: paylaşan marka Rivoxe ise kampanya EKLENMEZ (kullanıcı kararı).
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
    'source_base' => 'https://rivoxe.com',
    'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36',
    'request_delay_ms' => 1200,   // kaynak istekleri arası bekleme
    'timeout' => 45,
    'max_new_per_run' => 40,      // bir koşuda en fazla bu kadar YENİ kampanya işlenir
    'max_pages_per_run' => 8,     // bir koşuda taranacak listeleme sayfası sayısı
    'collection' => 'kampanyalar',
];

if (is_file(__DIR__ . '/config.php')) {
    $override = require __DIR__ . '/config.php';
    if (is_array($override)) {
        $CFG = array_merge($CFG, $override);
    }
}

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

/** HTML entity'leri çözüp boşlukları sadeleştirir. */
function k_temiz(string $s): string
{
    return trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** Göreli URL'yi mutlaklaştırır. */
function k_abs(array $cfg, string $url): string
{
    if ($url === '' || str_starts_with($url, 'http')) {
        return $url;
    }
    return $cfg['source_base'] . (str_starts_with($url, '/') ? '' : '/') . $url;
}

/** Türkçe karakterli metinden URL-slug üretir (kategori_slug için). */
function k_slugify(string $s): string
{
    $map = ['ı' => 'i', 'İ' => 'i', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'Ğ' => 'g',
            'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c', '&' => ''];
    $s = strtolower(strtr($s, $map));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string) $s, '-');
}

/**
 * Listeleme sayfasındaki kampanya kartlarını çözümler.
 * Dönen kayıt: id, slug, url, baslik, resim, kategori, marka, marka_slug,
 * marka_logo, sponsorlu, populer, bitis (d.m.Y veya null).
 *
 * @return list<array<string,mixed>>
 */
function k_parse_cards(array $cfg, string $html): array
{
    $out = [];
    $parcalar = explode('<article class="kmp-card', $html);
    array_shift($parcalar); // ilk parça kart öncesi
    foreach ($parcalar as $p) {
        if (!preg_match('#href="(/kampanya/(\\d+)-([a-z0-9-]+))"#', $p, $m)) {
            continue;
        }
        $rec = [
            'id' => $m[2],
            'slug' => $m[2] . '-' . $m[3],
            'url' => k_abs($cfg, $m[1]),
        ];
        $rec['baslik'] = preg_match('#class="kmp-detail-btn" title="([^"]+)"#', $p, $t)
            ? k_temiz($t[1]) : '';
        if ($rec['baslik'] === '' && preg_match('#<img src="[^"]+"\\s+alt="([^"]+)"#', $p, $t)) {
            $rec['baslik'] = k_temiz($t[1]);
        }
        $rec['resim'] = preg_match('#<img src="([^"]+)"#', $p, $t) ? k_abs($cfg, $t[1]) : '';
        $rec['kategori'] = preg_match('#class="kmp-cat">([^<]+)<#', $p, $t)
            ? k_temiz($t[1]) : 'Diğer';
        if (preg_match('#href="/marka/([a-z0-9-]+)"[^>]*>([^<]+)</a>#', $p, $t)) {
            $rec['marka_slug'] = $t[1];
            $rec['marka'] = k_temiz($t[2]);
        } else {
            $rec['marka_slug'] = '';
            $rec['marka'] = '';
        }
        // Marka logosu: kmp-brand blogundaki ilk <img>.
        $rec['marka_logo'] = '';
        $bi = strpos($p, 'kmp-brand');
        if ($bi !== false && preg_match('#<img src="([^"]+)"#', substr($p, $bi), $t)) {
            $rec['marka_logo'] = k_abs($cfg, $t[1]);
        }
        $rec['sponsorlu'] = str_contains($p, 'rvx-sponsor');
        $rec['populer'] = str_contains($p, 'rvx-populer');
        $rec['bitis'] = preg_match('#(\\d{2}\\.\\d{2}\\.\\d{4}) tarihine kadar#', $p, $t)
            ? $t[1] : null;
        $out[] = $rec;
    }
    return $out;
}

/**
 * Detay sayfasından markanın kendi kampanya linki ("Kampanyaya Git",
 * kmpd-cta) ve açıklama (JSON-LD Article.description) alınır.
 */
function k_detail_info(array $cfg, string $slug): array
{
    $out = ['hedef' => '', 'aciklama' => '', 'yayin' => null];
    try {
        $html = k_source_get($cfg, $cfg['source_base'] . '/kampanya/' . $slug);
    } catch (Throwable $e) {
        k_log('Detay alinamadi (' . $slug . '): ' . $e->getMessage());
        return $out;
    }
    if (preg_match('#<a href="([^"]+)"[^>]*class="kmpd-cta"#', $html, $m)
        || preg_match('#class="kmpd-cta"[^>]*href="([^"]+)"#', $html, $m)) {
        $hedef = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        // utm vb. izleme parametrelerini temizle.
        $hedef = preg_replace('/([?&])(utm_[a-z]+|ref|source)=[^&]*/', '$1', $hedef);
        $hedef = rtrim(str_replace('?&', '?', $hedef), '?&');
        // Rivoxe'a geri donen linkleri hedef sayma.
        if (!str_contains($hedef, 'rivoxe.com')) {
            $out['hedef'] = $hedef;
        }
    }
    if (preg_match_all('#<script type="application/ld\\+json">(.*?)</script>#s', $html, $m)) {
        foreach ($m[1] as $json) {
            $d = json_decode($json, true);
            $items = isset($d['@type']) ? [$d] : (is_array($d) ? $d : []);
            foreach ($items as $it) {
                if (($it['@type'] ?? '') === 'Article') {
                    $out['aciklama'] = k_temiz((string) ($it['description'] ?? ''));
                    $out['yayin'] = $it['datePublished'] ?? null;
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

    $seen = [];
    $newQueue = [];
    $maxPages = max(1, (int) ($cfg['max_pages_per_run'] ?? 8));
    for ($page = 1; $page <= $maxPages; $page++) {
        try {
            $html = k_source_get($cfg, $cfg['source_base'] . '/kampanyalar?page=' . $page);
        } catch (Throwable $e) {
            k_log("Sayfa alinamadi (page={$page}): " . $e->getMessage());
            break;
        }
        $cards = k_parse_cards($cfg, $html);
        if ($cards === []) {
            k_log("Sayfa {$page}: kart bulunamadi (son sayfa veya yapi degisti).");
            break;
        }
        $yeni = 0;
        foreach ($cards as $c) {
            $docId = 'rv_' . $c['id'];
            if (isset($seen[$docId])) {
                continue;
            }
            $seen[$docId] = true;
            // KURAL: paylasan marka Rivoxe ise ekleme (kullanici karari).
            if ($c['marka_slug'] === 'rivoxe' || mb_strtolower($c['marka'], 'UTF-8') === 'rivoxe') {
                continue;
            }
            if (isset($existing[$docId])) {
                continue;
            }
            $newQueue[] = $c;
            $yeni++;
        }
        k_log("Sayfa {$page}: " . count($cards) . ' kart, yeni: ' . $yeni);
        // NOT: erken cikis YOK — eski kayitlar sayfalara dagilmis olabilecegi
        // icin "yeni yok" sayfasi katalogun bittigi anlamina gelmez. max_pages
        // kadar taranir (~95 istek, istekler arasi bekleme ile ~2 dk).
        k_sleep_ms($cfg['request_delay_ms']);
    }

    if ($newQueue === []) {
        k_log('Yeni kampanya yok. Bitti.');
        exit(0);
    }

    $islenen = 0;
    foreach (array_slice($newQueue, 0, $cfg['max_new_per_run']) as $c) {
        $docId = 'rv_' . $c['id'];
        $detay = k_detail_info($cfg, $c['slug']);
        k_sleep_ms($cfg['request_delay_ms']);

        $bitis = null;
        if (!empty($c['bitis'])) {
            try {
                $bitis = new DateTimeImmutable(
                    str_replace('.', '-', substr($c['bitis'], 6) . '-' . substr($c['bitis'], 3, 2) . '-' . substr($c['bitis'], 0, 2)) . ' 23:59:59',
                    new DateTimeZone('Europe/Istanbul')
                );
            } catch (Throwable $e) {
                $bitis = null;
            }
        }
        $baslangic = null;
        if (!empty($detay['yayin'])) {
            try {
                $baslangic = new DateTimeImmutable((string) $detay['yayin']);
            } catch (Throwable $e) {
                $baslangic = null;
            }
        }

        $fields = [
            'baslik' => (string) $c['baslik'],
            'slug' => (string) $c['slug'],
            'resim' => (string) $c['resim'],
            'kategori' => (string) $c['kategori'],
            'kategori_slug' => k_slugify((string) $c['kategori']),
            'marka' => (string) $c['marka'],
            'marka_logo' => (string) $c['marka_logo'],
            'kart_adi' => (string) $c['marka'],
            // Nihai link: markanin KENDI kampanya sayfasi (kmpd-cta).
            'basvuru_url' => (string) $detay['hedef'],
            'sponsorlu' => (bool) $c['sponsorlu'],
            'populer' => (bool) $c['populer'],
            'kazanc_tipi' => '',
            'aciklama' => (string) $detay['aciklama'],
            'kaynak_url' => (string) $c['url'],
            'baslangic' => $baslangic,
            'bitis' => $bitis,
            'created_at' => new DateTimeImmutable('now'),
            'aktif' => true,
        ];
        try {
            k_fb_set($cfg, $docId, $fields);
            $islenen++;
            k_log("Yazildi: {$docId} [{$fields['kategori']}] {$fields['baslik']}" . ($bitis ? ' (bitis ' . $bitis->format('Y-m-d') . ')' : ''));
        } catch (Throwable $e) {
            k_log("Yazma hatasi ({$docId}): " . $e->getMessage());
        }
    }

    $kalan = count($newQueue) - $islenen;
    k_log("Bitti. Yazilan: {$islenen}" . ($kalan > 0 ? " | siradaki kosuya kalan: {$kalan}" : ''));
} catch (Throwable $e) {
    fwrite(STDERR, 'HATA: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
