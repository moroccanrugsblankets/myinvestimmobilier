<?php
/**
 * Upload image for GrapesJS page editor
 * My Invest Immobilier
 *
 * Accepts image files uploaded by GrapesJS assetManager and returns the
 * absolute server URL so GrapesJS can reference the file by path instead
 * of embedding it as a base64 data URI.
 *
 * Expected POST field : files[]  (multipart/form-data)
 * Response format     : { "data": ["https://…/uploads/pages/filename.jpg"] }
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/pages/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Impossible de créer le répertoire d\'upload']);
        exit;
    }
}

$allowedMimes = [
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/svg+xml',
];
$maxSize    = 5 * 1024 * 1024; // 5 MB
$siteUrl    = rtrim($config['SITE_URL'] ?? '', '/');
$uploadedUrls = [];
$errors       = [];

// GrapesJS sends files under the key "files" (single or multiple)
if (empty($_FILES['files'])) {
    echo json_encode(['data' => []]);
    exit;
}

$files = $_FILES['files'];

// Normalise single-file upload to array form
if (!is_array($files['name'])) {
    $files = [
        'name'     => [$files['name']],
        'type'     => [$files['type']],
        'tmp_name' => [$files['tmp_name']],
        'error'    => [$files['error']],
        'size'     => [$files['size']],
    ];
}

$finfo = new finfo(FILEINFO_MIME_TYPE);

for ($i = 0, $total = count($files['name']); $i < $total; $i++) {
    $origName = htmlspecialchars($files['name'][$i] ?? 'fichier');

    if ((int)$files['error'][$i] !== UPLOAD_ERR_OK) {
        $errors[] = "Erreur d'upload pour « {$origName} » (code " . (int)$files['error'][$i] . ')';
        continue;
    }

    if ((int)$files['size'][$i] > $maxSize) {
        $errors[] = "« {$origName} » dépasse la taille maximale autorisée (5 Mo)";
        continue;
    }

    // Validate MIME type using server-side detection (ignores client hint)
    $mime = $finfo->file($files['tmp_name'][$i]);
    if (!in_array($mime, $allowedMimes, true)) {
        $errors[] = "« {$origName} » : type de fichier non autorisé ({$mime})";
        continue;
    }

    // Build a safe, unique filename using random bytes only
    $mimeToExt = [
        'image/jpeg'   => 'jpg',
        'image/jpg'    => 'jpg',
        'image/png'    => 'png',
        'image/gif'    => 'gif',
        'image/webp'   => 'webp',
        'image/svg+xml'=> 'svg',
    ];
    $origExt  = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
    $ext      = $mimeToExt[$mime] ?? ($origExt ?: 'jpg');
    $filename = 'page_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $dest     = $uploadDir . $filename;

    if (!move_uploaded_file($files['tmp_name'][$i], $dest)) {
        $errors[] = "« {$origName} » : impossible de déplacer le fichier sur le serveur";
        continue;
    }

    $uploadedUrls[] = $siteUrl . '/uploads/pages/' . $filename;
}

$response = ['data' => $uploadedUrls];
if (!empty($errors)) {
    $response['errors'] = $errors;
}
echo json_encode($response);
