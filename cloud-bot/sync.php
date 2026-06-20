#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * cloud-bot scheduler.
 *
 * Her calistirildiginda (GitHub Actions cron ~5 dk) sunlari yapar:
 *   1) Sirada en cok gecikmis ve >= MARKET_INTERVAL_MIN dakikadir bakilmamis
 *      TEK marketi kontrol eder, yeni brosurleri kuyruga ekler.
 *      (11 market sirayla ~saatte 1 kez kontrol edilir.)
 *   2) Kuyrukta bekleyen brosur varsa ve son indirmeden bu yana
 *      >= DRAIN_INTERVAL_MIN dakika gectiyse, DRAIN_BATCH kadar brosuru
 *      indirip import API'ye yukler. (Cok brosur birikmisse 10-15 dk
 *      araliklarla, agir olmadan akitilir.)
 *
 * Durum (state) bir JSON dosyasinda tutulur; GitHub Actions bu dosyayi
 * her kosudan sonra repoya geri commit eder. Sunucu zaten source_key ile
 * mukerrer kaydi engeller; state sadece tekrar indirmeyi onleyen optimizasyon.
 *
 * Config tamamen ortam degiskenlerinden okunur (asagiya bkz.).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu script CLI icindir.\n");
    exit(1);
}

foreach (['curl', 'dom', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "PHP {$ext} extension gerekli.\n");
        exit(1);
    }
}

@ini_set('memory_limit', '768M');

require __DIR__ . '/lib.php';

$opts = getopt('', ['once-all', 'check-only', 'drain-only', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, <<<TXT
Kullanim:
  php cloud-bot/sync.php                 # 1 market kontrol + kuyruk akitma (cron icin)
  php cloud-bot/sync.php --check-only     # sadece sirasi gelen marketi kontrol et
  php cloud-bot/sync.php --drain-only     # sadece kuyrugu akit
  php cloud-bot/sync.php --once-all       # TUM marketleri kontrol + tum kuyrugu akit (manuel/ilk dolum)

  Ortam degiskenleri:
    IMPORT_API_URL       (zorunlu)  ornek: https://aktuel-market.com/api/import_brochure.php
    IMPORT_API_TOKEN     (zorunlu)
    SOURCE_BASE_URL      varsayilan https://aktuelbrosurler.com
    STATE_FILE           varsayilan cloud-bot/state.json
    MARKET_INTERVAL_MIN  varsayilan 55  (her market en fazla bu sikligla kontrol edilir)
    DRAIN_INTERVAL_MIN   varsayilan 12  (kuyruktan indirme araligi)
    DRAIN_BATCH          varsayilan 1   (her akitmada kac brosur)
    REQUEST_DELAY_MS     varsayilan 800
    TIMEOUT              varsayilan 60
    MAX_QUEUE            varsayilan 1000
    MAX_RETRIES          varsayilan 3
    DEBUG                1 ise ayrintili log

TXT);
    exit(0);
}

/** @return array<string, mixed> */
function cb_config(): array
{
    $env = static function (string $key, ?string $default = null): ?string {
        $v = getenv($key);
        if ($v === false || trim((string) $v) === '') {
            return $default;
        }
        return trim((string) $v);
    };

    $cfg = [
        'source_base_url' => rtrim((string) $env('SOURCE_BASE_URL', 'https://aktuelbrosurler.com'), '/'),
        'import_api_url' => (string) $env('IMPORT_API_URL', ''),
        'import_api_token' => (string) $env('IMPORT_API_TOKEN', ''),
        'state_file' => (string) $env('STATE_FILE', __DIR__ . '/state.json'),
        'market_interval_min' => max(1, (int) $env('MARKET_INTERVAL_MIN', '55')),
        'drain_interval_min' => max(0, (int) $env('DRAIN_INTERVAL_MIN', '12')),
        'drain_batch' => max(1, (int) $env('DRAIN_BATCH', '1')),
        'request_delay_ms' => max(0, (int) $env('REQUEST_DELAY_MS', '800')),
        'timeout' => max(10, (int) $env('TIMEOUT', '60')),
        'max_queue' => max(10, (int) $env('MAX_QUEUE', '1000')),
        'max_retries' => max(1, (int) $env('MAX_RETRIES', '3')),
    ];

    foreach (['import_api_url', 'import_api_token'] as $req) {
        if ($cfg[$req] === '') {
            throw new RuntimeException("Ortam degiskeni bos: " . strtoupper($req));
        }
    }

    return $cfg;
}

/**
 * bot.php icindeki market liste URL'leri. SADECE bu marketler cekilir.
 *
 * @return list<string>
 */
function cb_market_listing_urls(string $baseUrl): array
{
    $paths = [
        '/migros/brosurler',
        '/bizimtoptanmarket/brosurler',
        '/a101/brosurler',
        '/bim/brosurler',
        '/sok-market/brosurler',
        '/carrefour/brosurler',
        '/watsons/brosurler',
        '/rossmann/brosurler',
        '/tarim-kredi-kooperatif_market/brosurler',
        '/hakmar/brosurler',
        '/gratis/brosurler',
    ];

    return array_map(static fn (string $p): string => $baseUrl . $p, $paths);
}

/** @return array{markets: array<string, array{lastCheckedAt:int}>, queue: list<array<string,mixed>>, uploaded: array<string,bool>, lastDrainAt: int} */
function cb_load_state(string $path): array
{
    $empty = ['markets' => [], 'queue' => [], 'uploaded' => [], 'lastDrainAt' => 0];
    if (!is_file($path)) {
        return $empty;
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $empty;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $empty;
    }

    return [
        'markets' => is_array($decoded['markets'] ?? null) ? $decoded['markets'] : [],
        'queue' => array_values(is_array($decoded['queue'] ?? null) ? $decoded['queue'] : []),
        'uploaded' => is_array($decoded['uploaded'] ?? null) ? $decoded['uploaded'] : [],
        'lastDrainAt' => (int) ($decoded['lastDrainAt'] ?? 0),
    ];
}

function cb_save_state(string $path, array $state): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("State klasoru olusturulamadi: {$dir}");
    }
    // uploaded set'ini sinirla (eskileri buda): son ~5000 anahtar yeterli.
    if (count($state['uploaded']) > 5000) {
        $state['uploaded'] = array_slice($state['uploaded'], -5000, null, true);
    }
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($path, $json) === false) {
        throw new RuntimeException("State yazilamadi: {$path}");
    }
}

/**
 * Bir marketi kontrol eder; yeni brosurleri kuyruga ekler. Eklenen sayiyi dondurur.
 */
function cb_check_market(array $cfg, array &$state, string $listingUrl, ?string $cookieFile): int
{
    cb_log("Market kontrol: {$listingUrl}");
    $resp = cb_http_get($listingUrl, cb_browser_headers([
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
    ]), $cfg['timeout'], $cookieFile);
    cb_sleep_ms($cfg['request_delay_ms']);

    $items = cb_parse_listing_brochures($resp['body'], $cfg['source_base_url']);
    cb_log('  kart sayisi: ' . count($items));

    $queuedKeys = [];
    foreach ($state['queue'] as $q) {
        if (isset($q['source_key'])) {
            $queuedKeys[(string) $q['source_key']] = true;
        }
    }

    $added = 0;
    foreach ($items as $item) {
        $sourceKey = 'ab_' . $item['brochure_key'];
        if (isset($state['uploaded'][$sourceKey]) || isset($queuedKeys[$sourceKey])) {
            continue;
        }
        if (count($state['queue']) >= $cfg['max_queue']) {
            cb_log('  kuyruk dolu (MAX_QUEUE), kalan brosurler sonraki kontrole birakildi.');
            break;
        }
        $state['queue'][] = [
            'source_key' => $sourceKey,
            'brochure_key' => $item['brochure_key'],
            'market' => $item['market'],
            'title' => $item['title'],
            'href' => $item['href'],
            'tries' => 0,
        ];
        $queuedKeys[$sourceKey] = true;
        $added++;
    }

    $state['markets'][$listingUrl] = ['lastCheckedAt' => time()];
    cb_log("  kuyruga eklenen yeni brosur: {$added} | toplam kuyruk: " . count($state['queue']));

    return $added;
}

/**
 * Kuyruktan en fazla $batch brosur isler.
 */
function cb_drain_queue(array $cfg, array &$state, int $batch, ?string $cookieFile): int
{
    $processed = 0;
    while ($processed < $batch && $state['queue'] !== []) {
        $item = array_shift($state['queue']);
        $sourceKey = (string) ($item['source_key'] ?? '');
        if ($sourceKey === '' || isset($state['uploaded'][$sourceKey])) {
            continue;
        }

        try {
            $result = cb_process_brochure($cfg, [
                'href' => (string) $item['href'],
                'market' => (string) $item['market'],
                'title' => (string) $item['title'],
                'brochure_key' => (string) $item['brochure_key'],
                'source_key' => $sourceKey,
            ], $cookieFile);

            if (in_array($result['status'], ['created', 'exists'], true)) {
                $state['uploaded'][$sourceKey] = true;
            } else {
                // Beklenmedik durum: tekrar dene.
                $item['tries'] = (int) ($item['tries'] ?? 0) + 1;
                if ($item['tries'] < $cfg['max_retries']) {
                    $state['queue'][] = $item;
                }
            }
        } catch (Throwable $e) {
            $item['tries'] = (int) ($item['tries'] ?? 0) + 1;
            cb_log("Brosur hatasi: {$sourceKey} (deneme {$item['tries']}) - " . $e->getMessage());
            if ($item['tries'] < $cfg['max_retries']) {
                $state['queue'][] = $item; // sona ekle, bir sonraki turda tekrar denenir
            } else {
                cb_log("Brosur vazgecildi (max retry): {$sourceKey}");
            }
        }

        $processed++;
    }

    return $processed;
}

// ----------------------------------------------------------------------------

try {
    $cfg = cb_config();
    $now = time();

    $cookieFile = tempnam(sys_get_temp_dir(), 'cb_cookie_');
    if ($cookieFile === false) {
        $cookieFile = null;
    } else {
        register_shutdown_function(static function () use ($cookieFile): void {
            @unlink($cookieFile);
        });
    }

    $state = cb_load_state($cfg['state_file']);

    // Yapilandirilmis marketleri state'e tanit (yeni eklenenler hemen kontrol edilsin).
    $listingUrls = cb_market_listing_urls($cfg['source_base_url']);
    foreach ($listingUrls as $url) {
        if (!isset($state['markets'][$url])) {
            $state['markets'][$url] = ['lastCheckedAt' => 0];
        }
    }

    $checkOnly = isset($opts['check-only']);
    $drainOnly = isset($opts['drain-only']);
    $onceAll = isset($opts['once-all']);

    $dirty = false;

    if ($onceAll) {
        // Manuel / ilk dolum: tum marketleri kontrol et, tum kuyrugu akit.
        foreach ($listingUrls as $url) {
            try {
                cb_check_market($cfg, $state, $url, $cookieFile);
                $dirty = true;
            } catch (Throwable $e) {
                cb_log("Market kontrol hatasi: {$url} - " . $e->getMessage());
            }
        }
        cb_save_state($cfg['state_file'], $state);
        $total = 0;
        while ($state['queue'] !== []) {
            $n = cb_drain_queue($cfg, $state, $cfg['drain_batch'], $cookieFile);
            if ($n === 0) {
                break;
            }
            $total += $n;
            $state['lastDrainAt'] = time();
            cb_save_state($cfg['state_file'], $state);
        }
        cb_log("once-all bitti. Islenen brosur: {$total}");
        exit(0);
    }

    // 1) Sirasi gelen TEK marketi kontrol et (en cok gecikmis olan).
    if (!$drainOnly) {
        $dueUrl = null;
        $oldest = PHP_INT_MAX;
        foreach ($listingUrls as $url) {
            $last = (int) ($state['markets'][$url]['lastCheckedAt'] ?? 0);
            if ($now - $last >= $cfg['market_interval_min'] * 60 && $last < $oldest) {
                $oldest = $last;
                $dueUrl = $url;
            }
        }
        if ($dueUrl !== null) {
            try {
                cb_check_market($cfg, $state, $dueUrl, $cookieFile);
                $dirty = true;
            } catch (Throwable $e) {
                cb_log("Market kontrol hatasi: {$dueUrl} - " . $e->getMessage());
            }
        } else {
            cb_log('Su an kontrol sirasi gelen market yok.');
        }
    }

    // 2) Kuyrugu akit (zamani geldiyse).
    if (!$checkOnly) {
        $drainDue = ($now - (int) $state['lastDrainAt']) >= $cfg['drain_interval_min'] * 60;
        if ($state['queue'] !== [] && $drainDue) {
            $n = cb_drain_queue($cfg, $state, $cfg['drain_batch'], $cookieFile);
            if ($n > 0) {
                $state['lastDrainAt'] = time();
                $dirty = true;
                cb_log("Kuyruktan islenen: {$n} | kalan: " . count($state['queue']));
            }
        } elseif ($state['queue'] !== []) {
            $wait = $cfg['drain_interval_min'] * 60 - ($now - (int) $state['lastDrainAt']);
            cb_log('Kuyruk dolu ama indirme araligi beklenmiyor (kalan ~' . max(0, (int) ceil($wait / 60)) . ' dk).');
        }
    }

    if ($dirty) {
        cb_save_state($cfg['state_file'], $state);
    }
    cb_log('Bitti.');
} catch (Throwable $e) {
    fwrite(STDERR, 'HATA: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
