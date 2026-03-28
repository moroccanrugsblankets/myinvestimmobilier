<?php
/**
 * Téléchargement du PDF du bail signé
 * My Invest Immobilier
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$contratId = (int)($_GET['contrat_id'] ?? 0);

if ($contratId === 0) {
    die('ID de contrat invalide.');
}

// Vérifier que le contrat existe et est signé ou validé
$contrat = fetchOne("SELECT * FROM contrats WHERE id = ? AND statut IN ('signe', 'valide')", [$contratId]);

if (!$contrat) {
    die('Contrat non trouvé ou non signé.');
}

// Chercher le fichier PDF généré (nouveau format avec reference_unique)
$pdfDir = dirname(__DIR__) . '/pdf/contrats/';
$filename = 'bail-' . $contrat['reference_unique'] . '.pdf';
$filepath = $pdfDir . $filename;

// Si le fichier n'existe pas, le générer
if (!file_exists($filepath)) {
    require_once __DIR__ . '/generate-bail.php';
    $filepath = generateBailPDF($contratId);
}

if (!$filepath || !file_exists($filepath)) {
    die('Erreur lors de la génération du PDF.');
}

// Déterminer le type MIME
$extension = pathinfo($filepath, PATHINFO_EXTENSION);
$mimeType = $extension === 'pdf' ? 'application/pdf' : 'text/html';
$filename = basename($filepath);

// Déterminer le mode d'affichage : inline (view=1) ou téléchargement forcé (défaut)
$viewMode = isset($_GET['view']) && $_GET['view'] === '1' ? 'inline' : 'attachment';

// Envoyer les headers
header('Content-Type: ' . $mimeType);
header('Content-Disposition: ' . $viewMode . '; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Envoyer le fichier
readfile($filepath);
exit;
