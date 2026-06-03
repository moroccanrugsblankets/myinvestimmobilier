<?php
/**
 * Récupère tous les documents liés à un contrat (PDFs et photos).
 * My Invest Immobilier
 *
 * Retourne un JSON structuré par rubriques :
 * {
 *   sections: [
 *     { rubrique: string, key: string, pdfs: [{label, url}], photos: [{label, url}] },
 *     …
 *   ]
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

// Initialiser les sections dans l'ordre d'affichage souhaité
$sections = [
    'contrat'    => ['rubrique' => 'Contrat (bail)',           'key' => 'contrat',    'pdfs' => [], 'photos' => []],
    'edl_entree' => ['rubrique' => 'État des lieux (Entrée)',  'key' => 'edl_entree', 'pdfs' => [], 'photos' => []],
    'edl_sortie' => ['rubrique' => 'État des lieux (Sortie)',  'key' => 'edl_sortie', 'pdfs' => [], 'photos' => []],
    'inv_entree' => ['rubrique' => 'Inventaire (Entrée)',      'key' => 'inv_entree', 'pdfs' => [], 'photos' => []],
    'inv_sortie' => ['rubrique' => 'Inventaire (Sortie)',      'key' => 'inv_sortie', 'pdfs' => [], 'photos' => []],
    'bilan'      => ['rubrique' => 'Bilan de logement',        'key' => 'bilan',      'pdfs' => [], 'photos' => []],
    'quittances' => ['rubrique' => 'Quittances',               'key' => 'quittances', 'pdfs' => [], 'photos' => []],
    'signalement'=> ['rubrique' => 'Signalement',              'key' => 'signalement','pdfs' => [], 'photos' => []],
    'demandes'   => ['rubrique' => 'Demandes & Documents',     'key' => 'demandes',   'pdfs' => [], 'photos' => []],
];

// ── 1. Contrat (bail) PDF ────────────────────────────────────────────────────
if (in_array($contrat['statut'], ['signe', 'valide', 'actif', 'expire', 'termine'])) {
    $sections['contrat']['pdfs'][] = [
        'label' => 'Contrat (bail)',
        'url'   => $siteUrl . '/pdf/download.php?contrat_id=' . $contratId . '&view=1',
    ];
}

// ── 2. États des lieux ────────────────────────────────────────────────────────
$stmtEdl = $pdo->prepare("SELECT id, type FROM etats_lieux WHERE contrat_id = ? ORDER BY type, created_at");
$stmtEdl->execute([$contratId]);

foreach ($stmtEdl->fetchAll(PDO::FETCH_ASSOC) as $edl) {
    $sKey      = $edl['type'] === 'entree' ? 'edl_entree' : 'edl_sortie';
    $typeLabel = $edl['type'] === 'entree' ? 'Entrée' : 'Sortie';

    $sections[$sKey]['pdfs'][] = [
        'label' => 'État des lieux (' . $typeLabel . ')',
        'url'   => $siteUrl . '/admin-v2/download-etat-lieux.php?id=' . $edl['id'],
    ];

    $stmtPhotos = $pdo->prepare("
        SELECT chemin_fichier, categorie
        FROM etat_lieux_photos
        WHERE etat_lieux_id = ? AND deleted_at IS NULL
        ORDER BY categorie, ordre ASC
    ");
    $stmtPhotos->execute([$edl['id']]);
    foreach ($stmtPhotos->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $sections[$sKey]['photos'][] = [
            'url'   => $siteUrl . '/' . ltrim($p['chemin_fichier'], '/'),
            'label' => $p['categorie'] ?? '',
        ];
    }
}

// ── 3. Inventaires ────────────────────────────────────────────────────────────
$stmtInv = $pdo->prepare("SELECT id, type FROM inventaires WHERE contrat_id = ? ORDER BY type, created_at");
$stmtInv->execute([$contratId]);

foreach ($stmtInv->fetchAll(PDO::FETCH_ASSOC) as $inv) {
    $sKey      = $inv['type'] === 'entree' ? 'inv_entree' : 'inv_sortie';
    $typeLabel = $inv['type'] === 'entree' ? 'Entrée' : 'Sortie';

    $sections[$sKey]['pdfs'][] = [
        'label' => 'Inventaire (' . $typeLabel . ')',
        'url'   => $siteUrl . '/admin-v2/download-inventaire.php?id=' . $inv['id'],
    ];

    $stmtIp = $pdo->prepare("
        SELECT fichier, categorie
        FROM inventaire_photos
        WHERE inventaire_id = ?
        ORDER BY categorie, ordre ASC
    ");
    $stmtIp->execute([$inv['id']]);
    foreach ($stmtIp->fetchAll(PDO::FETCH_ASSOC) as $ip) {
        $sections[$sKey]['photos'][] = [
            'url'   => $siteUrl . '/' . ltrim($ip['fichier'], '/'),
            'label' => $ip['categorie'] ?? '',
        ];
    }
}

// ── 4. Bilan de logement PDF ──────────────────────────────────────────────────
$stmtBilan = $pdo->prepare("SELECT id FROM etats_lieux WHERE contrat_id = ? AND type = 'sortie' LIMIT 1");
$stmtBilan->execute([$contratId]);
if ($stmtBilan->fetch()) {
    $sections['bilan']['pdfs'][] = [
        'label' => 'Bilan de logement',
        'url'   => $siteUrl . '/admin-v2/download-bilan-logement.php?contrat_id=' . $contratId,
    ];
}

// ── 5. Quittances PDF ─────────────────────────────────────────────────────────
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
        $sections['quittances']['pdfs'][] = [
            'label' => 'Quittance ' . sprintf('%02d', $q['mois']) . '/' . $q['annee'],
            'url'   => $siteUrl . '/pdf/quittances/' . rawurlencode($pdfBasename),
        ];
    }
}

// ── 6. Signalements (photos) ──────────────────────────────────────────────────
$stmtSig = $pdo->prepare("SELECT id, reference, titre FROM signalements WHERE contrat_id = ? ORDER BY date_signalement");
$stmtSig->execute([$contratId]);

foreach ($stmtSig->fetchAll(PDO::FETCH_ASSOC) as $sig) {
    $stmtSp = $pdo->prepare("SELECT filename FROM signalements_photos WHERE signalement_id = ? ORDER BY uploaded_at");
    $stmtSp->execute([$sig['id']]);
    foreach ($stmtSp->fetchAll(PDO::FETCH_ASSOC) as $sp) {
        $sections['signalement']['photos'][] = [
            'url'   => $siteUrl . '/uploads/signalements/' . rawurlencode($sp['filename']),
            'label' => $sig['reference'] . ' — ' . $sig['titre'],
        ];
    }
}

// ── 7. Demandes & Documents (fichiers joints) ─────────────────────────────────
$stmtDem = $pdo->prepare("
    SELECT objet, fichier_path
    FROM demandes_documents
    WHERE contrat_id = ? AND fichier_path IS NOT NULL
    ORDER BY created_at
");
$stmtDem->execute([$contratId]);
foreach ($stmtDem->fetchAll(PDO::FETCH_ASSOC) as $dem) {
    $ficPath = ltrim($dem['fichier_path'], '/');
    $ficExt  = strtolower(pathinfo($ficPath, PATHINFO_EXTENSION));
    $item = [
        'label' => $dem['objet'],
        'url'   => $siteUrl . '/' . $ficPath,
    ];
    if (in_array($ficExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $sections['demandes']['photos'][] = $item;
    } else {
        $sections['demandes']['pdfs'][] = $item;
    }
}

// Ne retourner que les sections contenant au moins un document
$result = array_values(array_filter($sections, function ($s) {
    return !empty($s['pdfs']) || !empty($s['photos']);
}));

echo json_encode(['sections' => $result]);
