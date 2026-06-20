<?php

declare(strict_types=1);

/**
 * cloud-bot ortak fonksiyonlari.
 *
 * Bu dosya local/run.php icindeki kaynak-site parse / indirme / import
 * mantiginin GitHub Actions (veya benzeri scheduler) icin uyarlanmis,
 * config'i ortam degiskenlerinden okuyan halidir. local/run.php'ye
 * dokunmadan, ondan bagimsiz calisir.
 */

function cb_log(string $message): void
{
    fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $message . PHP_EOL);
}

function cb_debug_enabled(): bool
{
    $v = getenv('DEBUG');
    return $v !== false && $v !== '' && $v !== '0' && strtolower((string) $v) !== 'false';
}

function cb_debug(string $message): void
{
    if (cb_debug_enabled()) {
        cb_log('[debug] ' . $message);
    }
}

function cb_sleep_ms(int $ms): void
{
    if ($ms > 0) {
        usleep($ms * 1000);
    }
}

/**
 * @return list<string>
 */
function cb_browser_headers(array $extra = []): array
{
    return array_merge([
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Upgrade-Insecure-Requests: 1',
    ], $extra);
}

/**
 * @return array{body: string, url: string}
 */
function cb_http_get(string $url, array $headers, int $timeout, ?string $cookieFile = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
    ];
    if ($cookieFile !== null && $cookieFile !== '') {
        $opts[CURLOPT_COOKIEFILE] = $cookieFile;
        $opts[CURLOPT_COOKIEJAR] = $cookieFile;
    }
    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        throw new RuntimeException("GET basarisiz: {$url} ({$error})");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("GET {$url} HTTP {$status}");
    }

    return ['body' => (string) $body, 'url' => $url];
}

function cb_absolutize_asset(string $path, string $baseUrl): string
{
    $path = trim(html_entity_decode($path, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

/**
 * Brosur detay href'inden market liste URL'ini turetir.
 *   https://site/migros/brosurler/...slug..._key  =>  https://site/migros/brosurler
 */
function cb_listing_url_from_href(string $href): ?string
{
    if (!preg_match('#^(https?://[^/]+/[^/]+/brosurler)(?:/|$)#i', $href, $m)) {
        return null;
    }

    return $m[1];
}

function cb_normalize_market_name(string $name): string
{
    $map = [
        'Tarım Kredi Kooperatif Market' => 'Tarım Kooperatif',
    ];

    return $map[$name] ?? $name;
}

function cb_extract_brochure_key_from_url(string $url): ?string
{
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return null;
    }
    if (preg_match('/_([a-z0-9]{8,64})$/i', $path, $m)) {
        return strtolower($m[1]);
    }

    return null;
}

function cb_extract_listing_market_name(DOMXPath $xpath): string
{
    $heading = $xpath->query('//h1 | //h2');
    if ($heading === false) {
        return '';
    }
    foreach ($heading as $node) {
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? ''));
        if ($text === '') {
            continue;
        }
        if (preg_match('/^(.+?)\s+Broşürleri$/iu', $text, $m)) {
            return cb_normalize_market_name(trim($m[1]));
        }
    }

    return '';
}

/**
 * @return list<array{href: string, market: string, title: string, brochure_key: string}>
 */
function cb_parse_listing_brochures(string $html, string $baseUrl): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $items = [];
    $nodes = $xpath->query('//*[@id="brsrler"]//a[@href]');
    if ($nodes !== false && $nodes->length > 0) {
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $href = cb_absolutize_asset($node->getAttribute('href'), $baseUrl);
            $brochureKey = cb_extract_brochure_key_from_url($href);
            if ($brochureKey === null) {
                continue;
            }

            $market = 'Diger';
            $title = '';

            $marketNode = $xpath->query('.//article//*[contains(@class,"excerpt")]//h4//*[contains(@class,"color")]', $node);
            if ($marketNode !== false && $marketNode->length > 0) {
                $market = cb_normalize_market_name(trim($marketNode->item(0)?->textContent ?? 'Diger'));
            }

            $titleNode = $xpath->query('.//article//*[contains(@class,"excerpt")]//p', $node);
            if ($titleNode !== false && $titleNode->length > 0) {
                $title = trim($titleNode->item(0)?->textContent ?? '');
            }

            $items[] = [
                'href' => $href,
                'market' => $market !== '' ? $market : 'Diger',
                'title' => $title,
                'brochure_key' => $brochureKey,
            ];
        }
    }

    if ($items !== []) {
        return $items;
    }

    // Fallback: yapi degisirse "broşürü görüntüle" linklerinden topla.
    $listingMarket = cb_extract_listing_market_name($xpath);
    $fallbackNodes = $xpath->query('//a[@href]');
    if ($fallbackNodes === false) {
        return [];
    }

    $seen = [];
    foreach ($fallbackNodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $href = cb_absolutize_asset($node->getAttribute('href'), $baseUrl);
        $brochureKey = cb_extract_brochure_key_from_url($href);
        if ($brochureKey === null || isset($seen[$brochureKey])) {
            continue;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? ''));
        if ($text === '') {
            continue;
        }
        if (!str_contains(mb_strtolower($text, 'UTF-8'), 'broşürü görüntüle')) {
            continue;
        }

        $seen[$brochureKey] = true;
        $title = preg_replace('/^\s*broşürü görüntüle\s*/iu', '', $text) ?? $text;
        $title = trim($title);
        if ($listingMarket !== '' && preg_match('/^' . preg_quote($listingMarket, '/') . '\s+/iu', $title)) {
            $title = trim((string) preg_replace('/^' . preg_quote($listingMarket, '/') . '\s+/iu', '', $title, 1));
        }

        $items[] = [
            'href' => $href,
            'market' => $listingMarket !== '' ? $listingMarket : 'Diger',
            'title' => $title,
            'brochure_key' => $brochureKey,
        ];
    }

    return $items;
}

function cb_decode_unicode_escapes(string $value): string
{
    $out = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static function (array $m): string {
        return mb_chr(hexdec($m[1]), 'UTF-8');
    }, $value);

    return $out ?? $value;
}

/**
 * @return list<string>
 */
function cb_parse_fliphtml5_image_paths(string $html): array
{
    if (!preg_match('/var\s+fliphtml5_pages\s*=\s*(\[[\s\S]*?\])\s*;?/s', $html, $block)) {
        return [];
    }

    $inner = $block[1];
    preg_match_all("/'l':\s*'([^']*)'/", $inner, $matches);
    if (empty($matches[1] ?? [])) {
        preg_match_all('/"l":\s*"([^"]*)"/', $inner, $matches);
    }

    $paths = [];
    foreach ($matches[1] ?? [] as $value) {
        $decoded = cb_decode_unicode_escapes(trim((string) $value));
        if ($decoded !== '' && !isset($paths[$decoded])) {
            $paths[$decoded] = true;
        }
    }

    return array_keys($paths);
}

function cb_extract_embed_url(string $html): ?string
{
    if (preg_match('/<iframe[^>]+src\s*=\s*["\']([^"\']*brosur\.(?:aspx|ashx)[^"\']*)["\']/i', $html, $m)) {
        return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return null;
}

/**
 * @return array{urls: list<string>, image_referer: string}|array{}
 */
function cb_resolve_brochure_page_urls(string $detailHtml, string $detailUrl, string $baseUrl, string $brochureKey, int $timeout, ?string $cookieFile): array
{
    // Inline (detay sayfasi) sayfa URL'leri yedek olarak tutulur.
    $inlinePaths = cb_parse_fliphtml5_image_paths($detailHtml);
    cb_debug('Detay sayfasinda fliphtml5_pages sayisi: ' . count($inlinePaths));

    // Imzali gorsel URL'leri (brosur.ashx) embed (brosur.aspx) sayfasiyla
    // "acilan" oturuma bagli; embed acilmadan dogrudan inline URL'ler HTTP 500
    // donebiliyor. Bu yuzden HER zaman once embed'i ac ve sayfa URL'lerini
    // mumkunse embed'den al, embed'i referer yap.
    $embedUrl = cb_extract_embed_url($detailHtml);
    $embedUrl = $embedUrl !== null
        ? cb_absolutize_asset($embedUrl, $baseUrl)
        : rtrim($baseUrl, '/') . '/brosur.aspx?id=' . $brochureKey;

    $paths = $inlinePaths;
    $imageReferer = $detailUrl;
    try {
        $embed = cb_http_get($embedUrl, cb_browser_headers([
            'Referer: ' . $detailUrl,
            'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Dest: iframe',
            'Sec-Fetch-User: ?1',
        ]), $timeout, $cookieFile);
        $embedPaths = cb_parse_fliphtml5_image_paths($embed['body']);
        cb_debug('Embed icinde fliphtml5_pages sayisi: ' . count($embedPaths));
        if ($embedPaths !== []) {
            $paths = $embedPaths;
            $imageReferer = $embedUrl;
        }
    } catch (Throwable $e) {
        cb_debug('Embed acilamadi, inline sayfalarla devam: ' . $e->getMessage());
    }

    $urls = [];
    foreach ($paths as $path) {
        $urls[] = cb_absolutize_asset($path, $baseUrl);
    }

    return ['urls' => $urls, 'image_referer' => $imageReferer];
}

function cb_tr_lower(string $s): string
{
    $s = str_replace(['İ', 'I'], ['i', 'ı'], $s);

    return mb_strtolower($s, 'UTF-8');
}

/**
 * @return array<string, int>
 */
function cb_turkish_months(): array
{
    return [
        'ocak' => 1,
        'şubat' => 2, 'subat' => 2,
        'mart' => 3,
        'nisan' => 4,
        'mayıs' => 5, 'mayis' => 5,
        'haziran' => 6,
        'temmuz' => 7,
        'ağustos' => 8, 'agustos' => 8,
        'eylül' => 9, 'eylul' => 9,
        'ekim' => 10,
        'kasım' => 11, 'kasim' => 11,
        'aralık' => 12, 'aralik' => 12,
    ];
}

/**
 * @return array{start: string, end: string, matched: bool}
 */
function cb_parse_brochure_dates(string $title, DateTimeImmutable $today): array
{
    $fallback = [
        'start' => $today->format('Y-m-d'),
        'end' => $today->modify('+10 days')->format('Y-m-d'),
        'matched' => false,
    ];

    $text = cb_tr_lower($title);
    if (trim($text) === '') {
        return $fallback;
    }

    $months = cb_turkish_months();
    $monthAlt = implode('|', array_keys($months));
    $defaultYear = (int) $today->format('Y');

    $makeDate = static function (int $day, int $month, int $year): ?DateTimeImmutable {
        if ($month < 1 || $month > 12 || !checkdate($month, $day, $year)) {
            return null;
        }
        return DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day)) ?: null;
    };

    $rangeRe = '/(\d{1,2})\s*(' . $monthAlt . ')?\s*[-–—]\s*(\d{1,2})\s*(' . $monthAlt . ')\s*(\d{4})?/u';
    if (preg_match($rangeRe, $text, $m)) {
        $endMonth = $months[$m[4]] ?? 0;
        $startMonth = $m[2] !== '' ? ($months[$m[2]] ?? $endMonth) : $endMonth;
        $year = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : $defaultYear;
        $startYear = $year;
        if ($startMonth > $endMonth) {
            $startYear = $year - 1;
        }
        $start = $makeDate((int) $m[1], $startMonth, $startYear);
        $end = $makeDate((int) $m[3], $endMonth, $year);
        if ($start !== null && $end !== null && $end >= $start) {
            return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'matched' => true];
        }
    }

    $singleRe = '/(\d{1,2})\s*(' . $monthAlt . ')\s*(\d{4})?/u';
    if (preg_match($singleRe, $text, $m)) {
        $month = $months[$m[2]] ?? 0;
        $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $defaultYear;
        $start = $makeDate((int) $m[1], $month, $year);
        if ($start !== null) {
            return ['start' => $start->format('Y-m-d'), 'end' => $start->modify('+10 days')->format('Y-m-d'), 'matched' => true];
        }
    }

    return $fallback;
}

/**
 * Tek bir brosuru kaynak siteden indirir ve import API'ye yukler.
 *
 * @param array{href: string, market: string, title: string, brochure_key: string, source_key: string} $item
 * @return array{status: string}
 */
function cb_process_brochure(array $cfg, array $item, ?string $cookieFile): array
{
    $sourceKey = $item['source_key'];
    cb_log("Brosur isleniyor: {$sourceKey} | {$item['market']}");
    cb_debug('Detay URL: ' . $item['href']);

    // Imzali gorsel URL'leri (brosur.ashx) oturum cerezine bagli; cerez ancak
    // bir site sayfasi (market listesi) ziyaret edilince kuruluyor. drain kosusu
    // check'ten ayri/temiz cerezle baslar, bu yuzden once listeyi ziyaret ederek
    // oturumu isit. (Aksi halde gorseller HTTP 500 doner.)
    $listingUrl = cb_listing_url_from_href($item['href']);
    if ($listingUrl !== null && $cookieFile !== null) {
        try {
            cb_http_get($listingUrl, cb_browser_headers([
                'Sec-Fetch-Site: none',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-User: ?1',
            ]), $cfg['timeout'], $cookieFile);
            cb_debug('Oturum isitildi: ' . $listingUrl);
            cb_sleep_ms($cfg['request_delay_ms']);
        } catch (Throwable $e) {
            cb_debug('Oturum isitma basarisiz, yine de devam: ' . $e->getMessage());
        }
    }

    $detail = cb_http_get($item['href'], cb_browser_headers([
        'Referer: ' . ($listingUrl ?? $cfg['source_base_url']),
        'Sec-Fetch-Site: same-origin',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-User: ?1',
    ]), $cfg['timeout'], $cookieFile);
    cb_sleep_ms($cfg['request_delay_ms']);

    $pageSource = cb_resolve_brochure_page_urls(
        $detail['body'],
        $item['href'],
        $cfg['source_base_url'],
        $item['brochure_key'],
        $cfg['timeout'],
        $cookieFile
    );
    $pageUrls = $pageSource['urls'] ?? [];
    $imageReferer = $pageSource['image_referer'] ?? $item['href'];

    if ($pageUrls === []) {
        throw new RuntimeException('Sayfa URL bulunamadi.');
    }
    cb_debug('Sayfa sayisi: ' . count($pageUrls));

    $pages = [];
    foreach ($pageUrls as $index => $pageUrl) {
        cb_debug('Sayfa indiriliyor [' . ($index + 1) . '/' . count($pageUrls) . ']: ' . $pageUrl);

        // Imzali gorsel URL'leri ara sira (rate limit / oturum) 500 donebiliyor;
        // birkac kez artan beklemeyle tekrar dene.
        $resp = null;
        $lastErr = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $resp = cb_http_get($pageUrl, cb_browser_headers([
                    'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                    'Referer: ' . $imageReferer,
                    'Sec-Fetch-Site: same-origin',
                    'Sec-Fetch-Mode: no-cors',
                    'Sec-Fetch-Dest: image',
                ]), $cfg['timeout'], $cookieFile);
                break;
            } catch (Throwable $e) {
                $lastErr = $e;
                cb_debug('Sayfa indirme denemesi ' . $attempt . ' basarisiz: ' . $e->getMessage());
                cb_sleep_ms(1000 * $attempt);
            }
        }
        if ($resp === null) {
            throw new RuntimeException('Sayfa indirilemedi: ' . $pageUrl . ' (' . ($lastErr?->getMessage() ?? 'bilinmiyor') . ')');
        }

        $path = parse_url($pageUrl, PHP_URL_PATH);
        $name = is_string($path) && $path !== '' ? basename($path) : '';
        $pages[] = [
            'name' => $name !== '' ? $name : sprintf('page-%02d.bin', $index + 1),
            'bytes' => $resp['body'],
        ];
        cb_sleep_ms($cfg['request_delay_ms']);
    }

    $today = new DateTimeImmutable('now');
    $dates = cb_parse_brochure_dates($item['title'], $today);
    if (!$dates['matched']) {
        cb_log("Tarih basliktan okunamadi, eklenme +10 gun: {$dates['start']} -> {$dates['end']}");
    }

    $payload = [
        'market' => $item['market'],
        'title' => $item['title'] !== '' ? $item['title'] : ($item['market'] . ' Broşür'),
        'source_key' => $sourceKey,
        'source_detail_url' => $item['href'],
        'start_date' => $dates['start'],
        'end_date' => $dates['end'],
    ];

    $result = cb_upload_brochure($cfg, $payload, $pages);
    $status = (string) ($result['result']['status'] ?? 'unknown');
    cb_log("Upload sonucu: {$status} ({$sourceKey})");

    return ['status' => $status];
}

/**
 * @param list<array{name: string, bytes: string}> $pages
 * @return array<string, mixed>
 */
function cb_upload_brochure(array $cfg, array $payload, array $pages): array
{
    $tempFiles = [];
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $payloadJson = json_encode($payload, $jsonFlags);
    if (!is_string($payloadJson) || $payloadJson === '') {
        throw new RuntimeException('Payload JSON uretilemedi: ' . json_last_error_msg());
    }

    $postFields = [
        'payload' => $payloadJson,
        'token' => $cfg['import_api_token'],
    ];
    foreach ($pages as $index => $page) {
        $tmp = tempnam(sys_get_temp_dir(), 'cb_');
        if ($tmp === false || file_put_contents($tmp, $page['bytes']) === false) {
            throw new RuntimeException('Gecici dosya olusturulamadi.');
        }
        $tempFiles[] = $tmp;
        $postFields['pages[' . $index . ']'] = new CURLFile($tmp, 'application/octet-stream', $page['name']);
    }

    try {
        $ch = curl_init($cfg['import_api_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => max(180, (int) $cfg['timeout']),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $cfg['import_api_token'],
                'X-Import-Token: ' . $cfg['import_api_token'],
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $postFields,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            throw new RuntimeException('Upload basarisiz: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("API JSON donmedi (HTTP {$status}): {$body}");
        }
        if ($status < 200 || $status >= 300 || !($decoded['ok'] ?? false)) {
            $error = (string) ($decoded['error'] ?? 'Bilinmeyen API hatasi');
            throw new RuntimeException("API hatasi (HTTP {$status}): {$error}");
        }

        return $decoded;
    } finally {
        foreach ($tempFiles as $tmp) {
            @unlink($tmp);
        }
    }
}
