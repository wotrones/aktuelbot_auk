<?php

declare(strict_types=1);

/**
 * addimage.php — broşür sayfa görseli yükleme endpoint'i.
 *
 * Sunucuna (ör. kampanyacebimde.com kök dizinine) yükle; URL şöyle olur:
 *   https://kampanyacebimde.com/addimage.php
 *
 * cloud-bot bu adrese multipart POST atar:
 *   alan adı: source   (dosya)
 *   (opsiyonel) token  (ADDIMAGE_TOKEN ayarlıysa zorunlu)
 *
 * Başarılı yanıt:  {"ok":true,"image":"https://host/uploads/aktuel/2026/06/ab12...jpg"}
 * Hatalı yanıt:    {"ok":false,"error":"..."}
 *
 * Yüklenen görseller bu dosyanın yanındaki  uploads/aktuel/YYYY/MM/  altına kaydedilir.
 */

// ============================ AYARLAR ============================

/** Boş bırakılırsa kimlik doğrulama YAPILMAZ (herkes yükleyebilir).
 *  Güvenlik için bir değer ata ve cloud-bot tarafında IMAGE_UPLOAD_TOKEN ile eşle. */
const ADDIMAGE_TOKEN = '';

/** Yükleme klasörü (bu dosyaya göre). Web'den erişilebilir olmalı. */
const UPLOAD_SUBDIR = 'uploads/aktuel';

/** İzin verilen uzantılar. */
const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

/** Maksimum dosya boyutu (bayt). 0 = sınırsız. */
const MAX_BYTES = 20 * 1024 * 1024; // 20 MB

// ================================================================

// Yanıtın saf JSON kalması için uyarı/deprecation çıktısını kapat (yoksa JSON bozulur).
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Yalnızca POST.']);
}

// Token kontrolü (ayarlıysa)
if (ADDIMAGE_TOKEN !== '') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $token = '';
    if (is_string($auth) && preg_match('/^\s*Bearer\s+(.+)\s*$/i', $auth, $m)) {
        $token = trim($m[1]);
    }
    if ($token === '') {
        $token = trim((string) ($_POST['token'] ?? $_SERVER['HTTP_X_UPLOAD_TOKEN'] ?? ''));
    }
    if (!hash_equals(ADDIMAGE_TOKEN, $token)) {
        respond(401, ['ok' => false, 'error' => 'Yetkisiz.']);
    }
}

// Dosya alanı: source (yedek: image, file)
$file = null;
foreach (['source', 'image', 'file'] as $field) {
    if (isset($_FILES[$field]) && is_array($_FILES[$field])) {
        $file = $_FILES[$field];
        break;
    }
}
if ($file === null) {
    respond(422, ['ok' => false, 'error' => "Dosya yok ('source' alanı bekleniyor)."]);
}
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    respond(422, ['ok' => false, 'error' => 'Yükleme hatası (kod: ' . (int) ($file['error'] ?? -1) . ').']);
}
$tmp = (string) ($file['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    respond(422, ['ok' => false, 'error' => 'Geçersiz yükleme.']);
}

$size = (int) ($file['size'] ?? filesize($tmp) ?: 0);
if (MAX_BYTES > 0 && $size > MAX_BYTES) {
    respond(413, ['ok' => false, 'error' => 'Dosya çok büyük.']);
}

// Gerçekten görsel mi? (uzantıdan değil, içerikten)
$ext = 'jpg';
$mime = '';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string) finfo_file($finfo, $tmp);
} else {
    $info = @getimagesize($tmp);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
}
$mimeToExt = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];
if (!isset($mimeToExt[$mime])) {
    respond(415, ['ok' => false, 'error' => 'Desteklenmeyen tür: ' . ($mime ?: 'bilinmiyor')]);
}
$ext = $mimeToExt[$mime];
if (!in_array($ext, ALLOWED_EXT, true)) {
    respond(415, ['ok' => false, 'error' => 'İzin verilmeyen uzantı.']);
}

// Hedef klasör: uploads/aktuel/YYYY/MM/
$subdir = UPLOAD_SUBDIR . '/' . date('Y') . '/' . date('m');
$absDir = __DIR__ . '/' . $subdir;
if (!is_dir($absDir) && !@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
    respond(500, ['ok' => false, 'error' => 'Klasör oluşturulamadı.']);
}

// Benzersiz dosya adı
$name = bin2hex(random_bytes(12)) . '.' . $ext;
$absPath = $absDir . '/' . $name;
if (!@move_uploaded_file($tmp, $absPath)) {
    respond(500, ['ok' => false, 'error' => 'Dosya kaydedilemedi.']);
}
@chmod($absPath, 0644);

// Public URL üret (scheme + host otomatik)
$https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
$scheme = $https ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');

// Script'in web yolu (alt klasörde olabilir)
$scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$publicPath = ($scriptDir !== '' ? $scriptDir : '') . '/' . $subdir . '/' . $name;
$imageUrl = $scheme . '://' . $host . $publicPath;

respond(200, ['ok' => true, 'image' => $imageUrl]);
