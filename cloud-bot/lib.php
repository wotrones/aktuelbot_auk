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
 * Proxy listesini yukler (bir kez). Kaynaklar:
 *   1) PROXY_FILE dosyasi (vars. cloud-bot/proxies.txt) - her satira bir proxy
 *   2) PROXY_URL ortam degiskeni (tekil; varsa listeye eklenir)
 * Bos satirlar ve '#' ile baslayan satirlar yok sayilir.
 *
 * @return list<string>
 */
function cb_load_proxies(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $list = [];
    $file = getenv('PROXY_FILE');
    $file = is_string($file) && trim($file) !== '' ? trim($file) : __DIR__ . '/proxies.txt';
    if (is_file($file) && is_readable($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $list[] = $line;
        }
    }

    $env = getenv('PROXY_URL');
    if (is_string($env) && trim($env) !== '') {
        $list[] = trim($env);
    }

    // Tekrarlari ele.
    $cache = array_values(array_unique($list));

    return $cache;
}

/** Calisan proxy'ye yapismak icin secili indeks (process boyunca). */
function cb_proxy_idx(?int $set = null): int
{
    static $idx = 0;
    if ($set !== null) {
        $idx = $set;
    }
    return $idx;
}

/**
 * Kaynak site datacenter IP'lerini blokladigi icin istekleri proxy listesi
 * uzerinden dener: secili (calisan) proxy'ye yapisir, basarisiz olursa siradaki
 * proxy'ye gecer. Proxy yoksa dogrudan baglanir.
 *
 * @return array{body: string, url: string}
 */
function cb_http_get(string $url, array $headers, int $timeout, ?string $cookieFile = null): array
{
    $proxies = cb_load_proxies();
    if ($proxies === []) {
        return cb_http_get_once($url, $headers, $timeout, $cookieFile, null);
    }

    $n = count($proxies);
    $start = cb_proxy_idx() % $n;
    $errors = [];
    for ($i = 0; $i < $n; $i++) {
        $idx = ($start + $i) % $n;
        $proxy = $proxies[$idx];
        try {
            $res = cb_http_get_once($url, $headers, $timeout, $cookieFile, $proxy);
            cb_proxy_idx($idx); // calisan proxy'ye yapis
            if ($i > 0) {
                cb_debug('Proxy degisti -> #' . $idx . ' (' . cb_proxy_mask($proxy) . ')');
            }
            return $res;
        } catch (Throwable $e) {
            $errors[] = '#' . $idx . ' ' . cb_proxy_mask($proxy) . ': ' . $e->getMessage();
        }
    }

    throw new RuntimeException("Tum proxyler basarisiz ({$url}) -> " . implode(' | ', $errors));
}

/** Loglarda proxy kimlik bilgisini gizler. */
function cb_proxy_mask(string $proxy): string
{
    return (string) preg_replace('#://[^@/]+@#', '://***@', $proxy);
}

/**
 * @return array{body: string, url: string}
 */
function cb_http_get_once(string $url, array $headers, int $timeout, ?string $cookieFile, ?string $proxy): array
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
    if ($proxy !== null && $proxy !== '') {
        $opts[CURLOPT_PROXY] = $proxy;
    }

    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        throw new RuntimeException("baglanti hatasi ({$error})");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("HTTP {$status}");
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
 * Metinde tarih araligini/tekil tarihi arar. Bulursa
 * ['start' => DateTimeImmutable, 'end' => ?DateTimeImmutable] (tekil tarihte
 * end=null) doner; hicbir sey bulamazsa null.
 *
 * $allowSingle=false iken yalniz ACIK araliklari (guclu sinyal) kabul eder;
 * OCR gibi gurultulu metinlerde (fiyat, gramaj vb.) tek-sayi + ay yanlis
 * eslesmelerini onlemek icin kullanilir.
 *
 * @return array{start: DateTimeImmutable, end: ?DateTimeImmutable}|null
 */
function cb_match_dates_in_text(string $text, DateTimeImmutable $today, bool $allowSingle = true): ?array
{
    $text = cb_tr_lower($text);
    if (trim($text) === '') {
        return null;
    }

    $months = cb_turkish_months();
    $monthAlt = implode('|', array_keys($months));
    $defaultYear = (int) $today->format('Y');

    $normYear = static fn (int $y): int => $y < 100 ? 2000 + $y : $y;
    $makeDate = static function (int $day, int $month, int $year): ?DateTimeImmutable {
        if ($month < 1 || $month > 12 || !checkdate($month, $day, $year)) {
            return null;
        }
        return DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day)) ?: null;
    };

    // 1) Sayisal aralik: "16.07.2026 - 29.07.2026", "16/07 - 29/07/2026"
    //    (ilk tarihte yil opsiyonel, bitis tarihinde yil zorunlu).
    $numRange = '/(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4}))?\s*[-–—]\s*(\d{1,2})[.\/](\d{1,2})[.\/](\d{2,4})/u';
    if (preg_match($numRange, $text, $m)) {
        $endYear = $normYear((int) $m[6]);
        if (isset($m[3]) && $m[3] !== '') {
            $startYear = $normYear((int) $m[3]);
        } else {
            $startYear = $endYear;
            if ((int) $m[2] > (int) $m[5]) {
                $startYear = $endYear - 1; // yil sonu -> yil basi (Aralik -> Ocak)
            }
        }
        $start = $makeDate((int) $m[1], (int) $m[2], $startYear);
        $end = $makeDate((int) $m[4], (int) $m[5], $endYear);
        if ($start !== null && $end !== null && $end >= $start) {
            return ['start' => $start, 'end' => $end];
        }
    }

    // 2) Turkce ay araligi: "11-14 temmuz", "16 temmuz - 29 temmuz 2026".
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
            return ['start' => $start, 'end' => $end];
        }
    }

    if (!$allowSingle) {
        return null;
    }

    // 3) Tekil Turkce tarih: "16 temmuz 2026" (bitis bilinmiyor).
    $singleRe = '/(\d{1,2})\s*(' . $monthAlt . ')\s*(\d{4})?/u';
    if (preg_match($singleRe, $text, $m)) {
        $month = $months[$m[2]] ?? 0;
        $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $defaultYear;
        $start = $makeDate((int) $m[1], $month, $year);
        if ($start !== null) {
            return ['start' => $start, 'end' => null];
        }
    }

    return null;
}

/**
 * Brosur basligindan tarihleri cikarir. Bulamazsa bugun -> bugun+10
 * (matched=false; cagiran OCR yedegine dusebilir).
 *
 * @return array{start: string, end: string, matched: bool}
 */
function cb_parse_brochure_dates(string $title, DateTimeImmutable $today): array
{
    $hit = cb_match_dates_in_text($title, $today, true);
    if ($hit === null) {
        return [
            'start' => $today->format('Y-m-d'),
            'end' => $today->modify('+10 days')->format('Y-m-d'),
            'matched' => false,
        ];
    }
    $start = $hit['start'];
    $end = $hit['end'] ?? $start->modify('+10 days');

    return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'matched' => true];
}

/**
 * Tarih dizisindeki bitis tarihi bugunden once mi (suresi gecmis mi)?
 * Bitis yok/cozulemezse false (varsayilan aralik aktif kabul edilir).
 */
function cb_dates_expired(array $dates, DateTimeImmutable $today): bool
{
    $endStr = (string) ($dates['end'] ?? '');
    if ($endStr === '') {
        return false;
    }
    try {
        $end = new DateTimeImmutable($endStr);
    } catch (Throwable $e) {
        return false;
    }

    return $end < $today->setTime(0, 0, 0);
}

/**
 * Gorsel baytlarini tesseract ile metne cevirir (best-effort). tesseract yoksa
 * veya OCR_ENABLED=0 ise bos string doner. tesseract varligi ilk cagride
 * kontrol edilip onbelleklenir.
 */
function cb_ocr_image_to_text(string $imageBytes): string
{
    /** @var string|false|null $bin false=mevcut degil */
    static $bin = null;
    if ($bin === null) {
        if (getenv('OCR_ENABLED') === '0') {
            $bin = false;
        } else {
            $candidate = getenv('TESSERACT_BIN');
            $candidate = is_string($candidate) && trim($candidate) !== '' ? trim($candidate) : 'tesseract';
            $v = @shell_exec(escapeshellcmd($candidate) . ' --version 2>/dev/null');
            $bin = (is_string($v) && $v !== '') ? $candidate : false;
            if ($bin === false) {
                cb_debug('OCR: tesseract bulunamadi, tarih OCR yedegi devre disi.');
            }
        }
    }
    if ($bin === false || $imageBytes === '') {
        return '';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'cb_ocr_');
    if ($tmp === false) {
        return '';
    }
    if (@file_put_contents($tmp, $imageBytes) === false) {
        @unlink($tmp);
        return '';
    }

    $lang = getenv('TESSERACT_LANG');
    $lang = is_string($lang) && trim($lang) !== '' ? trim($lang) : 'tur+eng';
    $cmd = escapeshellcmd($bin) . ' ' . escapeshellarg($tmp) . ' stdout -l ' . escapeshellarg($lang)
        . ' --oem 1 --psm 6 2>/dev/null';
    $out = @shell_exec($cmd);
    @unlink($tmp);

    return is_string($out) ? $out : '';
}

/**
 * Brosur sayfa gorsellerinde (once kapak) tarih araligini OCR ile arar. Kapakta
 * genelde "11-14 TEMMUZ TARIHLERI ARASINDA" gibi acik aralik yazar. Gurultuye
 * karsi yalniz acik araliklari kabul eder (allowSingle=false).
 *
 * @param list<array{name:string, bytes:string}> $pages
 * @return array{start: string, end: string, matched: bool}|null
 */
function cb_ocr_dates_from_pages(array $pages, DateTimeImmutable $today, int $maxPages = 2): ?array
{
    $scanned = 0;
    foreach ($pages as $page) {
        if ($scanned >= $maxPages) {
            break;
        }
        $scanned++;
        $text = cb_ocr_image_to_text($page['bytes'] ?? '');
        if (trim($text) === '') {
            continue;
        }
        $hit = cb_match_dates_in_text($text, $today, false);
        if ($hit !== null && $hit['end'] !== null) {
            return [
                'start' => $hit['start']->format('Y-m-d'),
                'end' => $hit['end']->format('Y-m-d'),
                'matched' => true,
            ];
        }
    }

    return null;
}

/**
 * Tek bir brosurun tum sayfa gorsellerini kaynak siteden indirir.
 * Hedef (Firestore vb.) bilmez; sadece ham sayfa baytlarini + tarihleri dondurur.
 *
 * @param array{href: string, market: string, title: string, brochure_key: string, source_key: string} $item
 * @return array{pages: list<array{name:string, bytes:string}>, dates: array{start:string,end:string,matched:bool}}
 */
function cb_fetch_brochure(array $cfg, array $item, ?string $cookieFile): array
{
    $sourceKey = $item['source_key'];
    cb_log("Brosur indiriliyor: {$sourceKey} | {$item['market']}");
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

    // Tarihi once basliktan cikar; acik ve suresi gecmis bir aralik varsa
    // sayfalari indirmeye hic gerek yok (uygulama zaten gostermez).
    $today = new DateTimeImmutable('today');
    $dates = cb_parse_brochure_dates($item['title'], $today);
    if ($dates['matched'] && cb_dates_expired($dates, $today)) {
        cb_log("Suresi gecmis brosur (baslik tarihi {$dates['end']}), indirilmeden atlandi: {$sourceKey}");
        return ['pages' => [], 'dates' => $dates, 'expired' => true];
    }

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

    // Baslikta acik tarih yoksa kapak gorsellerinden OCR ile yakalamayi dene.
    if (!$dates['matched']) {
        $ocrDates = cb_ocr_dates_from_pages($pages, $today);
        if ($ocrDates !== null) {
            cb_log("Tarih OCR ile bulundu: {$ocrDates['start']} -> {$ocrDates['end']} ({$sourceKey})");
            $dates = $ocrDates;
        } else {
            cb_debug('Tarih baslikta/OCR ile bulunamadi, varsayilan aralik kullanilacak.');
        }
    }

    // OCR ile bulunan tarih de gecmis olabilir; oyleyse yazilmayacak.
    $expired = $dates['matched'] && cb_dates_expired($dates, $today);
    if ($expired) {
        cb_log("Suresi gecmis brosur (son tarih {$dates['end']}), yazilmayacak: {$sourceKey}");
    }

    cb_log('Indirilen sayfa: ' . count($pages) . " ({$sourceKey})");

    return ['pages' => $pages, 'dates' => $dates, 'expired' => $expired];
}
