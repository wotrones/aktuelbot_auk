<?php

declare(strict_types=1);

/**
 * ocr.php — broşür sayfası OCR endpoint'i (uygulamadaki ürün araması için).
 *
 * addimage.php'nin yanına, sunucu köküne yükle; URL şöyle olur:
 *   https://kampanyacebimde.com/ocr.php
 *
 * Gereksinim: sunucuda tesseract + Türkçe dil paketi kurulu olmalı ve PHP'de
 * exec() açık olmalı. Kurulum (Debian/Ubuntu):
 *   apt-get install tesseract-ocr tesseract-ocr-tur
 *
 * Sağlık kontrolü (tesseract çalışıyor mu?):
 *   GET https://kampanyacebimde.com/ocr.php?health=1
 *   -> {"ok":true,"tesseract":"tesseract 5.x"} veya {"ok":false,...}
 *
 * OCR isteği — cloud-bot POST atar; iki mod:
 *   1) source (dosya, multipart)          — görseli yükleyerek
 *   2) path   (metin, uploads-göreli yol) — görsel ZATEN bu sunucudaysa
 *      yeniden yüklemeden yerinden okur (backfill bunun için).
 *      Örn: path=uploads/aktuel/2026/07/ab12.jpg (tam URL de kabul edilir).
 *   (opsiyonel) token (OCR_TOKEN ayarlıysa zorunlu)
 * Yanıt: {"ok":true,"text":"..."} | {"ok":false,"error":"..."}
 */

// ============================ AYARLAR ============================

/** Boş bırakılırsa kimlik doğrulama YAPILMAZ. Güvenlik için bir değer ata ve
 *  cloud-bot tarafında OCR_TOKEN secret'ı ile eşle. */
const OCR_TOKEN = '';

/** Tesseract komutu (PATH'te değilse tam yol yaz, örn. /usr/bin/tesseract). */
const TESSERACT_BIN = 'tesseract';

/** OCR dili. */
const TESSERACT_LANG = 'tur';

/** Maksimum dosya boyutu (bayt). */
const MAX_BYTES = 20 * 1024 * 1024; // 20 MB

/** Yanıtta metnin kırpılacağı üst sınır (karakter). */
const MAX_TEXT_CHARS = 4000;

/** 'path' modunda okumaya izin verilen kök (bu dosyaya göre). */
const UPLOAD_SUBDIR_OCR = 'uploads/aktuel';

// ================================================================

@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

header('Content-Type: application/json; charset=utf-8');

/** @param array<string,mixed> $data */
function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** tesseract çalıştırılabilir mi? Sürüm satırını döndürür, yoksa null. */
function tesseract_version(): ?string
{
    if (!function_exists('exec')) {
        return null;
    }
    $out = [];
    $code = 1;
    @exec(escapeshellcmd(TESSERACT_BIN) . ' --version 2>&1', $out, $code);
    if ($code !== 0 || $out === []) {
        return null;
    }
    return trim((string) $out[0]);
}

// --- Sağlık kontrolü ---
if (isset($_GET['health'])) {
    $ver = tesseract_version();
    if ($ver === null) {
        respond(200, [
            'ok' => false,
            'error' => function_exists('exec')
                ? 'tesseract bulunamadı (TESSERACT_BIN=' . TESSERACT_BIN . ')'
                : 'PHP exec() kapalı; bu sunucuda OCR çalıştırılamaz',
        ]);
    }
    respond(200, ['ok' => true, 'tesseract' => $ver]);
}

// --- OCR isteği ---
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'POST bekleniyor (sağlık için ?health=1)']);
}

if (OCR_TOKEN !== '') {
    $token = (string) ($_POST['token'] ?? '');
    if (!hash_equals(OCR_TOKEN, $token)) {
        respond(403, ['ok' => false, 'error' => 'Geçersiz token']);
    }
}

if (tesseract_version() === null) {
    respond(500, ['ok' => false, 'error' => 'tesseract kullanılamıyor (kurulum/exec kontrol et)']);
}

$src = '';
$pathParam = trim((string) ($_POST['path'] ?? ''));
if ($pathParam !== '') {
    // Yerel dosya modu: yalnızca uploads/aktuel altındaki görseller okunabilir.
    // Tam URL geldiyse yol kısmına indirgenir; dizin kaçışı realpath ile kesilir.
    $rel = $pathParam;
    $parsed = parse_url($pathParam, PHP_URL_PATH);
    if (is_string($parsed) && $parsed !== '') {
        $rel = $parsed;
    }
    $rel = ltrim($rel, '/');
    $base = realpath(__DIR__ . '/' . UPLOAD_SUBDIR_OCR);
    $full = $base !== false ? realpath(__DIR__ . '/' . $rel) : false;
    if ($base === false || $full === false || !str_starts_with($full, $base . '/')) {
        respond(400, ['ok' => false, 'error' => 'Geçersiz path (uploads/aktuel altında olmalı)']);
    }
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        respond(400, ['ok' => false, 'error' => 'İzin verilmeyen uzantı']);
    }
    if ((int) filesize($full) > MAX_BYTES) {
        respond(413, ['ok' => false, 'error' => 'Dosya çok büyük']);
    }
    $src = $full;
} else {
    if (!isset($_FILES['source']) || !is_uploaded_file($_FILES['source']['tmp_name'] ?? '')) {
        respond(400, ['ok' => false, 'error' => "Dosya yok ('source' dosyası veya 'path' bekleniyor)"]);
    }
    if ((int) $_FILES['source']['size'] > MAX_BYTES) {
        respond(413, ['ok' => false, 'error' => 'Dosya çok büyük']);
    }
    $src = (string) $_FILES['source']['tmp_name'];
}

// tesseract çıktı dosyası: {base}.txt olarak yazar.
$base = tempnam(sys_get_temp_dir(), 'ocr_');
if ($base === false) {
    respond(500, ['ok' => false, 'error' => 'Geçici dosya oluşturulamadı']);
}

// Aynı anda TEK OCR işi: paralel istekler CPU'yu katlayıp siteyi
// düşürüyordu (2026-08-18 kesintisi). Meşgulken 429 dönülür; bot yerel
// tesseract'ına düşer / sonraki koşuda yeniden dener.
$kilit = fopen(sys_get_temp_dir() . '/ocr_gate.lock', 'c');
if ($kilit === false || !flock($kilit, LOCK_EX | LOCK_NB)) {
    respond(429, ['ok' => false, 'error' => 'OCR mesgul, sonra tekrar deneyin']);
}

// Ön işleme: gri + kontrast + keskinleştirme. Boyut SINIRLI: genişlik
// 2400px'i aşmaz (dev görselde körlemesine 2x büyütme tesseract'ı
// dakikalarca sürüyordu), 1400px altındaki küçük görseller ise büyütülür.
$pre = $base . '_pre.png';
$convCode = 1;
@exec(sprintf(
    "convert %s -resize '2400x2400>' -resize '1400x1400<' -colorspace Gray -normalize -sharpen 0x1 %s 2>/dev/null",
    escapeshellarg($src),
    escapeshellarg($pre)
), $_, $convCode);
$ocrSrc = ($convCode === 0 && is_file($pre)) ? $pre : $src;

// Tek geçiş (psm 11 — seyrek metin): kalite testinde kazanan buydu; ikinci
// geçiş süreyi ikiye katlayıp kesintiye katkı vermişti. 120 sn üst sınır.
$out = $base . '_o';
$kod = 1;
@exec(sprintf(
    'timeout 120 %s %s %s -l %s --psm 11 2>/dev/null',
    escapeshellcmd(TESSERACT_BIN),
    escapeshellarg($ocrSrc),
    escapeshellarg($out),
    escapeshellarg(TESSERACT_LANG)
), $_, $kod);
$text = is_file($out . '.txt') ? trim((string) file_get_contents($out . '.txt')) : '';
@unlink($out . '.txt');
@unlink($base);
@unlink($pre);
flock($kilit, LOCK_UN);

if ($text === '' && $kod !== 0) {
    respond(500, ['ok' => false, 'error' => "tesseract hata kodu {$kod}"]);
}

// Fazla boşlukları sadeleştir, üst sınırı uygula.
$text = trim((string) preg_replace('/[ \t]+/', ' ', $text));
if (mb_strlen($text, 'UTF-8') > MAX_TEXT_CHARS) {
    $text = mb_substr($text, 0, MAX_TEXT_CHARS, 'UTF-8');
}

respond(200, ['ok' => true, 'text' => $text]);
