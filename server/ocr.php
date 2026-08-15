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
 * OCR isteği — cloud-bot multipart POST atar:
 *   alan adı: source  (görsel dosya)
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

if (!isset($_FILES['source']) || !is_uploaded_file($_FILES['source']['tmp_name'] ?? '')) {
    respond(400, ['ok' => false, 'error' => "Dosya yok ('source' alanı bekleniyor)"]);
}
if ((int) $_FILES['source']['size'] > MAX_BYTES) {
    respond(413, ['ok' => false, 'error' => 'Dosya çok büyük']);
}
if (tesseract_version() === null) {
    respond(500, ['ok' => false, 'error' => 'tesseract kullanılamıyor (kurulum/exec kontrol et)']);
}

$src = (string) $_FILES['source']['tmp_name'];

// tesseract çıktı dosyası: {base}.txt olarak yazar.
$base = tempnam(sys_get_temp_dir(), 'ocr_');
if ($base === false) {
    respond(500, ['ok' => false, 'error' => 'Geçici dosya oluşturulamadı']);
}

$cmd = sprintf(
    '%s %s %s -l %s --psm 6 2>/dev/null',
    escapeshellcmd(TESSERACT_BIN),
    escapeshellarg($src),
    escapeshellarg($base),
    escapeshellarg(TESSERACT_LANG)
);
$code = 1;
@exec($cmd, $_, $code);

$txtFile = $base . '.txt';
$text = is_file($txtFile) ? (string) file_get_contents($txtFile) : '';
@unlink($base);
@unlink($txtFile);

if ($code !== 0) {
    respond(500, ['ok' => false, 'error' => "tesseract hata kodu {$code}"]);
}

// Fazla boşlukları sadeleştir, üst sınırı uygula.
$text = trim((string) preg_replace('/[ \t]+/', ' ', $text));
if (mb_strlen($text, 'UTF-8') > MAX_TEXT_CHARS) {
    $text = mb_substr($text, 0, MAX_TEXT_CHARS, 'UTF-8');
}

respond(200, ['ok' => true, 'text' => $text]);
