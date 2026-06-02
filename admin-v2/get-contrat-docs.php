<?php
/**
 * Récupère tous les documents liés à un contrat (PDFs et photos).
 * My Invest Immobilier
 *
 * Retourne un JSON structuré :
 * {
 *   pdfs: [ { label, url }, … ],
 *   photos: [ { url, label }, … ]
 * }
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$contratId = isset($_GET['contrat_id']) ? (int)$_GET['contrat_id'] : 0;

if ($contratId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de contrat invalide.']);
    exit;
}

// Vérifier que le contrat existe
$stmt = $pdo->prepare("SELECT id, reference_unique, statut FROM contrats WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$stmt->execute([$contratId]);
$contrat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contrat) {
    http_response_code(404);
    echo json_encode(['error' => 'Contrat introuvable.']);
    exit;
}

$siteUrl = rtrim($config['SITE_URL'] ?? '', '/');
$pdfs   = [];
$photos = [];

// ── 1. Contrat (bail) PDF ────────────────────────────────────────────────────
if (in_array($contrat['statut'], ['signe', 'valide', 'actif', 'expire', 'termine'])) {
    // Le lien génère le PDF à la volée si le fichier n'existe pas encore
    $pdfs[] = [
        'label' => 'Contrat (bail)',
        'url'   => $siteUrl . '/pdf/download.php?contrat_id=' . $contratId . '&view=1',
    ];
}

// ── 2. États des lieux ────────────────────────────────────────────────────────
$stmtEdl = $pdo->prepare("SELECT id, type FROM etats_lieux WHERE contrat_id = ? ORDER BY type, created_at");
$stmtEdl->execute([$contratId]);
$etatsLieux = $stmtEdl->fetchAll(PDO::FETCH_ASSOC);

foreach ($etatsLieux as $edl) {
    $typeLabel = $edl['type'] === 'entree' ? 'Entrée' : 'Sortie';
    $pdfs[] = [
        'label' => 'État des lieux (' . $typeLabel . ')',
        'url'   => $siteUrl . '/admin-v2/download-etat-lieux.php?id=' . $edl['id'],
    ];

    // Photos de l'état des lieux
    $stmtPhotos = $pdo->prepare("
        SELECT chemin_fichier, nom_fichier, categorie
        FROM etat_lieux_photos
        WHERE etat_lieux_id = ? AND deleted_at IS NULL
        ORDER BY categorie, ordre ASC
    ");
    $stmtPhotos->execute([$edl['id']]);
    foreach ($stmtPhotos->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $photos[] = [
            'url'   => $siteUrl . '/' . ltrim($p['chemin_fichier'], '/'),
            'label' => 'État des lieux (' . $typeLabel . ') — ' . ($p['categorie'] ?? ''),
        ];
    }
}

// ── 3. Inventaires ────────────────────────────────────────────────────────────
$stmtInv = $pdo->prepare("SELECT id, type, reference_unique FROM inventaires WHERE contrat_id = ? ORDER BY type, created_at");
$stmtInv->execute([$contratId]);
$inventaires = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

foreach ($inventaires as $inv) {
    $typeLabel = $inv['type'] === 'entree' ? 'Entrée' : 'Sortie';
    $pdfs[] = [
        'label' => 'Inventaire (' . $typeLabel . ')',
        'url'   => $siteUrl . '/admin-v2/download-inventaire.php?id=' . $inv['id'],
    ];

    // Photos de l'inventaire
    $stmtIp = $pdo->prepare("
        SELECT fichier, description, categorie
        FROM inventaire_photos
        WHERE inventaire_id = ?
        ORDER BY categorie, ordre ASC
    ");
    $stmtIp->execute([$inv['id']]);
    foreach ($stmtIp->fetchAll(PDO::FETCH_ASSOC) as $ip) {
        $photos[] = [
            'url'   => $siteUrl . '/' . ltrim($ip['fichier'], '/'),
            'label' => 'Inventaire (' . $typeLabel . ') — ' . ($ip['categorie'] ?? ''),
        ];
    }
}

// ── 4. Signalements (photos) ──────────────────────────────────────────────────
$stmtSig = $pdo->prepare("SELECT id, reference, titre FROM signalements WHERE contrat_id = ? ORDER BY date_signalement");
$stmtSig->execute([$contratId]);
$signalements = $stmtSig->fetchAll(PDO::FETCH_ASSOC);

foreach ($signalements as $sig) {
    $stmtSp = $pdo->prepare("SELECT filename, original_name FROM signalements_photos WHERE signalement_id = ? ORDER BY uploaded_at");
    $stmtSp->execute([$sig['id']]);
    foreach ($stmtSp->fetchAll(PDO::FETCH_ASSOC) as $sp) {
        $photos[] = [
            'url'   => $siteUrl . '/uploads/signalements/' . rawurlencode($sp['filename']),
            'label' => 'Signalement ' . $sig['reference'] . ' — ' . $sig['titre'],
        ];
    }
}

// ── 5. Bilan de logement PDF ──────────────────────────────────────────────────
// Vérifie l'existence d'un état des lieux de sortie (prérequis du bilan)
$stmtBilan = $pdo->prepare("SELECT id FROM etats_lieux WHERE contrat_id = ? AND type = 'sortie' LIMIT 1");
$stmtBilan->execute([$contratId]);
if ($stmtBilan->fetch()) {
    $pdfs[] = [
        'label' => 'Bilan de logement',
        'url'   => $siteUrl . '/admin-v2/download-bilan-logement.php?contrat_id=' . $contratId,
    ];
}

// ── 6. Quittances PDF ─────────────────────────────────────────────────────────
$stmtQ = $pdo->prepare("
    SELECT mois, annee, fichier_pdf
    FROM quittances
    WHERE contrat_id = ? AND fichier_pdf IS NOT NULL
    ORDER BY annee, mois
");
$stmtQ->execute([$contratId]);
foreach ($stmtQ->fetchAll(PDO::FETCH_ASSOC) as $q) {
    $pdfBasename = basename($q['fichier_pdf']);
    $pdfFsPath   = dirname(__DIR__) . '/pdf/quittances/' . $pdfBasename;
    if (file_exists($pdfFsPath)) {
        $pdfs[] = [
            'label' => 'Quittance ' . sprintf('%02d', $q['mois']) . '/' . $q['annee'],
            'url'   => $siteUrl . '/pdf/quittances/' . rawurlencode($pdfBasename),
        ];
    }
}

// ── 7. Demandes & Documents (fichiers joints) ─────────────────────────────────
$stmtDem = $pdo->prepare("
    SELECT objet, fichier_path, fichier_nom
    FROM demandes_documents
    WHERE contrat_id = ? AND fichier_path IS NOT NULL
    ORDER BY created_at
");
$stmtDem->execute([$contratId]);
foreach ($stmtDem->fetchAll(PDO::FETCH_ASSOC) as $dem) {
    $ficPath = ltrim($dem['fichier_path'], '/');
    $ficExt  = strtolower(pathinfo($ficPath, PATHINFO_EXTENSION));
    $item = [
        'label' => 'Demande : ' . $dem['objet'],
        'url'   => $siteUrl . '/' . $ficPath,
    ];
    if (in_array($ficExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $photos[] = $item;
    } else {
        $pdfs[] = $item;
    }
}

echo json_encode([
    'pdfs'   => $pdfs,
    'photos' => $photos,
]);
