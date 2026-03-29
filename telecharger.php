<?php
/**
 * Téléchargement sécurisé de documents
 * My Invest Immobilier
 *
 * Point d'entrée public pour le téléchargement de fichiers via token sécurisé.
 * Les tokens sont générés par createDocumentToken() et ont une durée de vie limitée.
 *
 * URL: /telecharger.php?token=xxxx
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';

// Validate token format (hex, 64 characters)
if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    http_response_code(400);
    die('Lien de téléchargement invalide.');
}

try {
    $stmt = $pdo->prepare(
        "SELECT * FROM document_tokens
         WHERE token = ? AND expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('telecharger.php DB error: ' . $e->getMessage());
    http_response_code(500);
    die('Erreur interne. Veuillez réessayer plus tard.');
}

if (!$doc) {
    http_response_code(404);
    die('Ce lien de téléchargement est invalide ou a expiré.');
}

$filePath = $doc['file_path'];

// Security: prevent path traversal
$realPath = realpath($filePath);
$baseDir  = realpath(__DIR__);

if ($realPath === false || strpos($realPath, $baseDir . DIRECTORY_SEPARATOR) !== 0) {
    error_log("telecharger.php: path traversal attempt or file outside base dir. Path: $filePath");
    http_response_code(403);
    die('Accès refusé.');
}

if (!file_exists($realPath) || !is_file($realPath)) {
    http_response_code(404);
    die('Le fichier demandé est introuvable sur le serveur.');
}

// Determine MIME type
$extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

// Build a safe filename for Content-Disposition
$suggestedName = $doc['file_name'] ?: basename($realPath);
$safeFileName  = preg_replace('/[^\w\s\-\.àâäéèêëïîôöùûüÿçÀÂÄÉÈÊËÏÎÔÖÙÛÜŸÇ]/u', '_', $suggestedName);
$safeFileName  = str_replace(["\r", "\n"], '', $safeFileName);

// Determine disposition: inline for safe known types, attachment otherwise
$inlineMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
$disposition = in_array($mimeType, $inlineMimeTypes, true) ? 'inline' : 'attachment';

// Stream the file
header('Content-Type: ' . $mimeType);
header('Content-Disposition: ' . $disposition . '; filename="' . $safeFileName . '"');
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

while (ob_get_level()) {
    ob_end_clean();
}

readfile($realPath);
exit;
