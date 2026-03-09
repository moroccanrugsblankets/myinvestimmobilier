<?php
/**
 * Détail complet d'un logement — Interface admin
 * My Invest Immobilier
 *
 * Page complète (non-modal) pour gérer toutes les informations d'un logement :
 * description, équipements, prix, localisation, lien de candidature.
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

$logement_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$logement_id) {
    header('Location: logements.php');
    exit;
}

// CSRF
$csrfToken = generateCsrfToken();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Token CSRF invalide.';
        header("Location: logement-detail.php?id=$logement_id");
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_infos') {
        $uploadErrors = [];
        try {
            $stmt = $pdo->prepare("
                UPDATE logements SET
                    reference           = ?,
                    adresse             = ?,
                    type                = ?,
                    surface             = ?,
                    loyer               = ?,
                    charges             = ?,
                    depot_garantie      = ?,
                    parking             = ?,
                    statut              = ?,
                    date_disponibilite  = ?,
                    description         = ?,
                    equipements         = ?,
                    commodites          = ?,
                    conditions_visite   = ?,
                    video_youtube       = ?,
                    lien_externe        = ?,
                    updated_at          = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                trim($_POST['reference']),
                trim($_POST['adresse']),
                trim($_POST['type']),
                (float)$_POST['surface'],
                (float)$_POST['loyer'],
                (float)$_POST['charges'],
                (float)$_POST['depot_garantie'],
                $_POST['parking'],
                $_POST['statut'],
                !empty($_POST['date_disponibilite']) ? $_POST['date_disponibilite'] : null,
                $_POST['description'] ?? '',
                $_POST['equipements'] ?? '',
                $_POST['commodites'] ?? '',
                $_POST['conditions_visite'] ?? '',
                !empty($_POST['video_youtube']) ? trim($_POST['video_youtube']) : null,
                !empty($_POST['lien_externe']) ? trim($_POST['lien_externe']) : null,
                $logement_id,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $_SESSION['error'] = 'Cette référence est déjà utilisée par un autre logement.';
                header("Location: logement-detail.php?id=$logement_id");
                exit;
            } else {
                $_SESSION['error'] = 'Erreur lors de la mise à jour.';
                error_log('logement-detail update: ' . $e->getMessage());
                header("Location: logement-detail.php?id=$logement_id");
                exit;
            }
        }

        // Handle photo deletions submitted via hidden inputs
        $deleteIds = $_POST['delete_photo_ids'] ?? [];
        if (is_array($deleteIds)) {
            foreach ($deleteIds as $pid) {
                $pid = (int)$pid;
                if ($pid <= 0) continue;
                $row = $pdo->prepare("SELECT filename FROM logements_photos WHERE id = ? AND logement_id = ?");
                $row->execute([$pid, $logement_id]);
                $photo = $row->fetch(PDO::FETCH_ASSOC);
                if ($photo) {
                    $fp = __DIR__ . '/../uploads/logements/' . $photo['filename'];
                    if (file_exists($fp)) { @unlink($fp); }
                    $pdo->prepare("DELETE FROM logements_photos WHERE id = ? AND logement_id = ?")
                        ->execute([$pid, $logement_id]);
                }
            }
        }

        // Handle photo reordering
        $photoOrder = json_decode($_POST['photo_order'] ?? '[]', true);
        if (is_array($photoOrder)) {
            foreach ($photoOrder as $pos => $pid) {
                $pdo->prepare("UPDATE logements_photos SET ordre = ? WHERE id = ? AND logement_id = ?")
                    ->execute([(int)$pos, (int)$pid, $logement_id]);
            }
        }

        // Handle new photo uploads (integrated into main form)
        $uploadDir = __DIR__ . '/../uploads/logements/';
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
        $mimeToExt = [
            'image/jpeg'     => 'jpg',
            'image/png'      => 'png',
            'image/gif'      => 'gif',
            'image/webp'     => 'webp',
            'video/mp4'      => 'mp4',
            'video/quicktime'=> 'mov',
            'video/mpeg'     => 'mpg',
        ];
        $uploaded = 0;
        if (!empty($_FILES['photos']['name'][0])) {
            $fileCount = count($_FILES['photos']['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                if (empty($_FILES['photos']['tmp_name'][$i]) || $_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $origName = basename($_FILES['photos']['name'][$i]);
                $tmpName  = $_FILES['photos']['tmp_name'][$i];
                $mimeType = mime_content_type($tmpName) ?: 'application/octet-stream';
                if (!isset($mimeToExt[$mimeType])) {
                    $uploadErrors[] = 'Type non autorisé : ' . htmlspecialchars($origName);
                    continue;
                }
                if ($_FILES['photos']['size'][$i] > 50 * 1024 * 1024) {
                    $uploadErrors[] = 'Fichier trop volumineux : ' . htmlspecialchars($origName);
                    continue;
                }
                $ext      = $mimeToExt[$mimeType];
                $filename = 'log_' . $logement_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($tmpName, $uploadDir . $filename)) {
                    $pdo->prepare("INSERT INTO logements_photos (logement_id, filename, original_name, mime_type, taille) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$logement_id, $filename, $origName, $mimeType, (int)$_FILES['photos']['size'][$i]]);
                    $uploaded++;
                }
            }
        }

        $msg = 'Logement enregistré avec succès.';
        if ($uploaded > 0) $msg .= ' ' . $uploaded . ' photo(s)/vidéo(s) ajoutée(s).';
        $_SESSION['success'] = $msg;
        if (!empty($uploadErrors)) {
            $_SESSION['error'] = implode(' | ', $uploadErrors);
        }
        header("Location: logement-detail.php?id=$logement_id");
        exit;
    }

    if ($action === 'delete_photo') {
        $photoId = (int)($_POST['photo_id'] ?? 0);
        $isAjax  = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
        if ($photoId > 0) {
            $row = $pdo->prepare("SELECT filename FROM logements_photos WHERE id = ? AND logement_id = ?");
            $row->execute([$photoId, $logement_id]);
            $photo = $row->fetch(PDO::FETCH_ASSOC);
            if ($photo) {
                $filePath = __DIR__ . '/../uploads/logements/' . $photo['filename'];
                if (file_exists($filePath)) { @unlink($filePath); }
                $pdo->prepare("DELETE FROM logements_photos WHERE id = ? AND logement_id = ?")
                    ->execute([$photoId, $logement_id]);
            }
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        $_SESSION['success'] = 'Photo supprimée.';
        header("Location: logement-detail.php?id=$logement_id");
        exit;
    }

    if ($action === 'reorder_photos') {
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (is_array($order)) {
            foreach ($order as $pos => $pid) {
                $pdo->prepare("UPDATE logements_photos SET ordre = ? WHERE id = ? AND logement_id = ?")
                    ->execute([(int)$pos, (int)$pid, $logement_id]);
            }
        }
        $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        header("Location: logement-detail.php?id=$logement_id");
        exit;
    }
}

// Load logement
$stmt = $pdo->prepare("
    SELECT l.*,
        (SELECT c.id FROM contrats c
         WHERE c.logement_id = l.id
           AND c.statut IN ('actif','signe','valide')
           AND (c.date_prise_effet IS NULL OR c.date_prise_effet <= CURDATE())
         ORDER BY c.date_creation DESC LIMIT 1) AS dernier_contrat_id,
        (SELECT COUNT(*) FROM candidatures ca
         WHERE ca.logement_id = l.id) AS total_candidatures,
        (SELECT COUNT(*) FROM candidatures ca
         WHERE ca.logement_id = l.id AND ca.statut IN ('en_cours','en_attente')) AS candidatures_en_cours
    FROM logements l
    WHERE l.id = ? AND l.deleted_at IS NULL
");
$stmt->execute([$logement_id]);
$logement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$logement) {
    $_SESSION['error'] = 'Logement introuvable.';
    header('Location: logements.php');
    exit;
}

// Build candidature link (md5 of reference, same as candidature/index.php)
$lienCandidature = rtrim($config['SITE_URL'], '/') . '/candidature/?ref=' . md5($logement['reference']);
// Build front office public link with MD5-encrypted reference
$lienPublic = rtrim($config['SITE_URL'], '/') . '/logement.php?ref=' . md5($logement['reference']);

// Status labels
$statutLabels = [
    'disponible'   => ['Disponible',   'success'],
    'en_location'  => ['Loué',         'warning'],
    'maintenance'  => ['Maintenance',  'danger'],
    'indisponible' => ['Indisponible', 'secondary'],
];
$statutLabel = $statutLabels[$logement['statut']] ?? [$logement['statut'], 'secondary'];

// Load inventory equipment groups
try {
    $stmtEq = $pdo->prepare("
        SELECT e.*, ic.nom AS categorie_nom
        FROM inventaire_equipements e
        LEFT JOIN inventaire_categories ic ON e.categorie_id = ic.id
        WHERE e.logement_id = ? AND e.deleted_at IS NULL
        ORDER BY ic.ordre ASC, e.ordre ASC, e.nom ASC
    ");
    $stmtEq->execute([$logement_id]);
    $equipements = $stmtEq->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $equipements = [];
}

// Group by category
$equipByCategory = [];
foreach ($equipements as $eq) {
    $cat = $eq['categorie_nom'] ?: ($eq['categorie'] ?: 'Autres');
    if (!isset($equipByCategory[$cat])) {
        $equipByCategory[$cat] = [];
    }
    $equipByCategory[$cat][] = $eq;
}

// Load logement photos
try {
    $stmtPhotos = $pdo->prepare("SELECT * FROM logements_photos WHERE logement_id = ? ORDER BY ordre ASC, id ASC");
    $stmtPhotos->execute([$logement_id]);
    $logementPhotos = $stmtPhotos->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $logementPhotos = [];
}

$successMsg = $_SESSION['success'] ?? null;
$errorMsg   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logement <?php echo htmlspecialchars($logement['reference']); ?> — Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once 'includes/sidebar-styles.php'; ?>
    <style>
        .section-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            padding: 1.5rem;
            margin-bottom: 1.25rem;
            border-bottom: 3px solid #0d6efd;
        }
        .info-badge {
            font-size: .75rem;
            padding: .3em .6em;
        }
        .copy-btn-feedback {
            transition: all .2s;
        }
        .stat-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
        }
        .stat-box .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
        }
        /* Photo upload area */
        .drop-zone {
            border: 2px dashed #ced4da;
            border-radius: 8px;
            padding: 20px 16px;
            text-align: center;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            background: #fafafa;
        }
        .drop-zone.drag-over { border-color: #0d6efd; background: #f0f4ff; }
        .drop-zone input[type=file] { display: none; }
        /* Photo gallery grid */
        .photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            gap: 10px;
        }
        .photo-thumb {
            position: relative;
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid #dee2e6;
            aspect-ratio: 4/3;
            background: #000;
        }
        .photo-thumb img,
        .photo-thumb video {
            width: 100%; height: 100%;
            object-fit: cover;
        }
        .photo-thumb .thumb-actions {
            position: absolute;
            top: 4px; right: 4px;
            display: flex; gap: 4px;
            opacity: 0;
            transition: opacity .2s;
        }
        .photo-thumb:hover .thumb-actions { opacity: 1; }
        .thumb-del-btn {
            background: rgba(220,53,69,.85);
            color: #fff; border: none;
            border-radius: 4px;
            width: 26px; height: 26px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: .75rem;
        }
        .thumb-del-btn:hover { background: #dc3545; }
        /* Drag-and-drop reorder */
        .photo-thumb { cursor: grab; }
        .photo-thumb.dragging { opacity: .45; cursor: grabbing; }
        .photo-thumb.drag-over-target { box-shadow: 0 0 0 3px #0d6efd; }
        /* New file preview list (before upload) */
        .new-file-preview-list { list-style: none; padding: 0; margin: 0; }
        .new-file-preview-item {
            display: flex; align-items: center; gap: 10px;
            padding: 6px 10px; border: 1px solid #dee2e6;
            border-radius: 6px; margin-bottom: 5px; background: #fff;
        }
        .new-file-thumb {
            width: 52px; height: 42px; object-fit: cover;
            border-radius: 4px; border: 1px solid #dee2e6; flex-shrink: 0;
        }
        .new-file-video-icon {
            width: 52px; height: 42px; display: flex;
            align-items: center; justify-content: center;
            background: #212529; border-radius: 4px; flex-shrink: 0;
        }
        .new-file-info { flex: 1; min-width: 0; }
        .new-file-name { font-size:.875rem; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .new-file-size { font-size:.75rem; color:#6c757d; }
        .btn-remove-new-photo { flex-shrink:0; background:none; border:none; color:#dc3545; padding:4px 8px; cursor:pointer; border-radius:4px; }
        .btn-remove-new-photo:hover { background:#f8d7da; }
    </style>
</head>
<body>
<?php require_once 'includes/menu.php'; ?>

<div class="main-content">
    <div class="container-fluid py-4">

        <!-- Breadcrumb & Title -->
        <div class="d-flex align-items-center mb-4 gap-3">
            <a href="logements.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Logements
            </a>
            <div>
                <h1 class="h4 mb-0">
                    <i class="bi bi-building me-2 text-primary"></i>
                    <?php echo htmlspecialchars($logement['reference']); ?>
                </h1>
                <small class="text-muted"><?php echo htmlspecialchars($logement['adresse']); ?></small>
            </div>
            <span class="badge bg-<?php echo $statutLabel[1]; ?> ms-auto"><?php echo $statutLabel[0]; ?></span>
        </div>

        <?php if ($successMsg): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($successMsg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($errorMsg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Left Column: Edit form -->
            <div class="col-lg-8">

                <!-- Informations principales -->
                <div class="section-card">
                    <h5 class="fw-semibold mb-4"><i class="bi bi-pencil-square me-2 text-primary"></i>Informations du logement</h5>
                    <form method="POST" id="formInfos" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="update_infos">
                        <input type="hidden" name="photo_order" id="photoOrderInput" value="[]">
                        <div id="deletePhotoInputs"></div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Référence <span class="text-danger">*</span></label>
                                <input type="text" name="reference" class="form-control"
                                       value="<?php echo htmlspecialchars($logement['reference']); ?>" required maxlength="50">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-semibold">Adresse <span class="text-danger">*</span></label>
                                <input type="text" name="adresse" class="form-control"
                                       value="<?php echo htmlspecialchars($logement['adresse']); ?>" required maxlength="255">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Type</label>
                                <input type="text" name="type" class="form-control"
                                       value="<?php echo htmlspecialchars($logement['type']); ?>" placeholder="T1, T2, Studio…" maxlength="50">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Surface (m²)</label>
                                <input type="number" name="surface" class="form-control" step="0.01" min="0"
                                       value="<?php echo htmlspecialchars($logement['surface']); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Parking</label>
                                <select name="parking" class="form-select">
                                    <option value="Aucun" <?php echo $logement['parking'] === 'Aucun' ? 'selected' : ''; ?>>Aucun</option>
                                    <option value="1 place" <?php echo $logement['parking'] === '1 place' ? 'selected' : ''; ?>>1 place</option>
                                </select>
                            </div>

                            <!-- Prix -->
                            <div class="col-12">
                                <hr class="my-1">
                                <h6 class="text-muted small text-uppercase mb-3">Prix</h6>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Loyer (€/mois) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="number" name="loyer" class="form-control" step="0.01" min="0" required
                                           value="<?php echo htmlspecialchars($logement['loyer']); ?>">
                                    <span class="input-group-text">€</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Charges (€/mois)</label>
                                <div class="input-group">
                                    <input type="number" name="charges" class="form-control" step="0.01" min="0"
                                           value="<?php echo htmlspecialchars($logement['charges']); ?>">
                                    <span class="input-group-text">€</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Dépôt de garantie (€)</label>
                                <div class="input-group">
                                    <input type="number" name="depot_garantie" class="form-control" step="0.01" min="0"
                                           value="<?php echo htmlspecialchars($logement['depot_garantie']); ?>">
                                    <span class="input-group-text">€</span>
                                </div>
                            </div>

                            <!-- Statut & Disponibilité -->
                            <div class="col-12">
                                <hr class="my-1">
                                <h6 class="text-muted small text-uppercase mb-3">Disponibilité</h6>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Statut</label>
                                <select name="statut" class="form-select">
                                    <option value="disponible"   <?php echo $logement['statut'] === 'disponible'   ? 'selected' : ''; ?>>Disponible</option>
                                    <option value="en_location"  <?php echo $logement['statut'] === 'en_location'  ? 'selected' : ''; ?>>Loué</option>
                                    <option value="maintenance"  <?php echo $logement['statut'] === 'maintenance'  ? 'selected' : ''; ?>>Maintenance</option>
                                    <option value="indisponible" <?php echo $logement['statut'] === 'indisponible' ? 'selected' : ''; ?>>Indisponible</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Date de disponibilité</label>
                                <input type="date" name="date_disponibilite" class="form-control"
                                       value="<?php echo htmlspecialchars($logement['date_disponibilite'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Lien externe (annonce)</label>
                                <input type="url" name="lien_externe" class="form-control" placeholder="https://..."
                                       value="<?php echo htmlspecialchars($logement['lien_externe'] ?? ''); ?>">
                            </div>

                            <!-- Description -->
                            <div class="col-12">
                                <hr class="my-1">
                                <h6 class="text-muted small text-uppercase mb-3">Présentation</h6>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Description du logement</label>
                                <textarea name="description" id="editor_description" class="form-control tinymce-editor" rows="6"
                                          placeholder="Décrivez le logement : emplacement, luminosité, travaux récents, atouts…"><?php echo htmlspecialchars($logement['description'] ?? ''); ?></textarea>
                                <div class="form-text">Visible sur la page publique du logement.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Équipements inclus</label>
                                <textarea name="equipements" id="editor_equipements" class="form-control tinymce-editor" rows="4"
                                          placeholder="Cuisine équipée, parquet, double vitrage, digicode…"><?php echo htmlspecialchars($logement['equipements'] ?? ''); ?></textarea>
                                <div class="form-text">Résumé libre des équipements. Pour la liste détaillée, utilisez <a href="manage-inventory-equipements.php?logement_id=<?php echo $logement_id; ?>">l'inventaire</a>.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Commodités à proximité</label>
                                <textarea name="commodites" id="editor_commodites" class="form-control tinymce-editor" rows="4"
                                          placeholder="Transports, commerces, écoles, parcs…"><?php echo htmlspecialchars($logement['commodites'] ?? ''); ?></textarea>
                                <div class="form-text">Informations sur le quartier et les commodités proches.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Conditions de visite et de candidature</label>
                                <textarea name="conditions_visite" id="editor_conditions_visite" class="form-control tinymce-editor" rows="4"
                                          placeholder="Comment organiser une visite, documents requis pour candidater…"><?php echo htmlspecialchars($logement['conditions_visite'] ?? ''); ?></textarea>
                                <div class="form-text">Affiché sur la page publique avec le bouton de candidature.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold"><i class="bi bi-youtube text-danger me-1"></i>Vidéo YouTube</label>
                                <input type="url" name="video_youtube" class="form-control" placeholder="https://www.youtube.com/watch?v=..."
                                       value="<?php echo htmlspecialchars($logement['video_youtube'] ?? ''); ?>">
                                <div class="form-text">URL de la vidéo YouTube à afficher sur la page publique (facultatif).</div>
                            </div>

                            <!-- ── Photos & Vidéos (intégrées au formulaire principal) ── -->
                            <div class="col-12">
                                <hr class="my-1">
                                <h6 class="text-muted small text-uppercase mb-3"><i class="bi bi-images me-1"></i>Photos et Vidéos</h6>
                            </div>

                            <?php if (!empty($logementPhotos)): ?>
                            <div class="col-12">
                                <p class="small text-muted mb-2">
                                    <i class="bi bi-arrows-move me-1"></i>Glissez les miniatures pour réorganiser l'ordre. Cliquez <i class="bi bi-trash"></i> pour supprimer.
                                </p>
                                <div class="photo-grid mb-3" id="photoGrid">
                                    <?php foreach ($logementPhotos as $photo): ?>
                                    <?php $isVid = strpos($photo['mime_type'], 'video/') === 0; ?>
                                    <div class="photo-thumb" data-photo-id="<?php echo $photo['id']; ?>" draggable="true">
                                        <?php if ($isVid): ?>
                                        <video src="<?php echo htmlspecialchars(rtrim($config['SITE_URL'], '/') . '/uploads/logements/' . $photo['filename']); ?>"
                                               preload="metadata" muted></video>
                                        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.4);color:#fff;pointer-events:none;">
                                            <i class="bi bi-play-circle-fill" style="font-size:1.5rem;"></i>
                                        </div>
                                        <?php else: ?>
                                        <img src="<?php echo htmlspecialchars(rtrim($config['SITE_URL'], '/') . '/uploads/logements/' . $photo['filename']); ?>"
                                             alt="<?php echo htmlspecialchars($photo['original_name']); ?>" loading="lazy">
                                        <?php endif; ?>
                                        <div class="thumb-actions">
                                            <button type="button" class="thumb-del-btn btn-del-existing-photo"
                                                    data-photo-id="<?php echo $photo['id']; ?>"
                                                    data-csrf="<?php echo htmlspecialchars($csrfToken); ?>"
                                                    title="Supprimer">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="col-12">
                                <!-- Drop zone for new photos -->
                                <div class="drop-zone" id="photoDropZone">
                                    <input type="file" id="photoFileInput" name="photos[]" multiple accept="image/*,video/mp4,video/quicktime">
                                    <i class="bi bi-cloud-upload fs-3 text-muted d-block mb-1"></i>
                                    <p class="mb-1 fw-semibold small">Glissez vos photos/vidéos ici</p>
                                    <p class="mb-2 text-muted" style="font-size:.75rem;">ou</p>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="photoBtnBrowse">
                                        <i class="bi bi-folder2-open me-1"></i>Parcourir
                                    </button>
                                    <p class="mt-2 mb-0 text-muted" style="font-size:.7rem;">JPEG, PNG, GIF, WEBP, MP4, MOV — Max 50 Mo</p>
                                </div>
                                <!-- Preview of newly selected files -->
                                <div id="newPhotosWrapper" class="mt-3" style="display:none;">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="small fw-semibold">Nouveaux fichiers à ajouter (<span id="newPhotosCount">0</span>)</span>
                                        <button type="button" class="btn btn-outline-danger btn-sm" id="photoBtnClearAll">
                                            <i class="bi bi-trash me-1"></i>Tout effacer
                                        </button>
                                    </div>
                                    <ul class="new-file-preview-list" id="newPhotoPreviewList"></ul>
                                </div>
                            </div>

                            <div class="col-12 d-flex justify-content-end gap-2">
                                <a href="logements.php" class="btn btn-outline-secondary">Annuler</a>
                                <button type="submit" class="btn btn-primary" id="btnSaveLogement">
                                    <i class="bi bi-save me-1"></i>Enregistrer
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Inventaire équipements (read-only summary) -->
                <?php if (!empty($equipByCategory)): ?>
                <div class="section-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-semibold mb-0"><i class="bi bi-box-seam me-2 text-primary"></i>Inventaire des équipements</h5>
                        <a href="manage-inventory-equipements.php?logement_id=<?php echo $logement_id; ?>"
                           class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-pencil me-1"></i>Modifier
                        </a>
                    </div>
                    <div class="row g-3">
                        <?php foreach ($equipByCategory as $catNom => $items): ?>
                        <div class="col-md-6">
                            <h6 class="text-muted small text-uppercase mb-2"><?php echo htmlspecialchars($catNom); ?></h6>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($items as $item): ?>
                                <li class="d-flex align-items-center gap-2 mb-1 small">
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                    <?php echo htmlspecialchars($item['nom']); ?>
                                    <?php if ((int)$item['quantite'] > 1): ?>
                                    <span class="badge bg-light text-dark border"><?php echo (int)$item['quantite']; ?>x</span>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="section-card">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="fw-semibold mb-0"><i class="bi bi-box-seam me-2 text-primary"></i>Inventaire des équipements</h5>
                        <a href="manage-inventory-equipements.php?logement_id=<?php echo $logement_id; ?>"
                           class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-plus me-1"></i>Ajouter des équipements
                        </a>
                    </div>
                    <p class="text-muted small mb-0">Aucun équipement enregistré pour ce logement.</p>
                </div>
                <?php endif; ?>

            </div><!-- /col-lg-8 -->

            <!-- Right Column: Links & Stats -->
            <div class="col-lg-4">

                <!-- Statistiques -->
                <div class="section-card">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-bar-chart me-2"></i>Vue d'ensemble</h6>
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="stat-box">
                                <div class="stat-value text-primary"><?php echo (int)$logement['total_candidatures']; ?></div>
                                <div class="small text-muted">Candidatures</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-box">
                                <div class="stat-value text-warning"><?php echo (int)$logement['candidatures_en_cours']; ?></div>
                                <div class="small text-muted">En cours</div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-muted">Loyer + charges</span>
                            <strong><?php echo number_format((float)$logement['loyer'] + (float)$logement['charges'], 2, ',', ' '); ?> €/mois</strong>
                        </div>
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-muted">Dépôt de garantie</span>
                            <strong><?php echo number_format((float)$logement['depot_garantie'], 2, ',', ' '); ?> €</strong>
                        </div>
                        <?php if ($logement['surface']): ?>
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">Surface</span>
                            <strong><?php echo htmlspecialchars($logement['surface']); ?> m²</strong>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Lien de candidature -->
                <div class="section-card border border-success">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-person-plus me-2 text-success"></i>Lien de candidature</h6>
                    <p class="text-muted small mb-2">Partagez ce lien pour recevoir des candidatures directement pour ce logement.</p>
                    <div class="input-group mb-2">
                        <input type="text" id="lienCandidatureInput" class="form-control form-control-sm font-monospace"
                               value="<?php echo htmlspecialchars($lienCandidature); ?>" readonly>
                        <button class="btn btn-outline-success btn-sm" type="button"
                                onclick="copyLink('lienCandidatureInput', this)" title="Copier le lien">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="candidatures.php?logement_id=<?php echo $logement_id; ?>"
                           class="btn btn-outline-secondary btn-sm flex-grow-1">
                            <i class="bi bi-list-ul me-1"></i>Voir les candidatures
                        </a>
                        <a href="<?php echo htmlspecialchars($lienCandidature); ?>" target="_blank"
                           class="btn btn-outline-success btn-sm" title="Tester le lien">
                            <i class="bi bi-box-arrow-up-right"></i>
                        </a>
                    </div>
                </div>

                <!-- Lien Front Office -->
                <div class="section-card border border-info">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-globe me-2 text-info"></i>Page publique</h6>
                    <p class="text-muted small mb-2">Lien vers la fiche publique de ce logement.</p>
                    <div class="input-group mb-2">
                        <input type="text" id="lienPublicInput" class="form-control form-control-sm font-monospace"
                               value="<?php echo htmlspecialchars($lienPublic); ?>" readonly>
                        <button class="btn btn-outline-info btn-sm" type="button"
                                onclick="copyLink('lienPublicInput', this)" title="Copier le lien">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                    <a href="<?php echo htmlspecialchars($lienPublic); ?>" target="_blank"
                       class="btn btn-outline-info btn-sm w-100">
                        <i class="bi bi-eye me-1"></i>Voir la page publique
                    </a>
                </div>

                <!-- Actions rapides -->
                <div class="section-card">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-lightning me-2"></i>Actions rapides</h6>
                    <div class="d-grid gap-2">
                        <a href="manage-inventory-equipements.php?logement_id=<?php echo $logement_id; ?>"
                           class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-box-seam me-1"></i>Gérer l'inventaire
                        </a>
                        <?php if ($logement['dernier_contrat_id']): ?>
                        <a href="contrat-detail.php?id=<?php echo $logement['dernier_contrat_id']; ?>"
                           class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-file-text me-1"></i>Voir le contrat actif
                        </a>
                        <a href="gestion-loyers.php?contrat_id=<?php echo $logement['dernier_contrat_id']; ?>"
                           class="btn btn-outline-warning btn-sm">
                            <i class="bi bi-cash-stack me-1"></i>Gestion des loyers
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($logement['lien_externe'])): ?>
                        <a href="<?php echo htmlspecialchars($logement['lien_externe']); ?>" target="_blank"
                           rel="noopener noreferrer" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Voir l'annonce externe
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

            </div><!-- /col-lg-4 -->
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- TinyMCE Cloud - API key is public and domain-restricted -->
<script src="https://cdn.tiny.cloud/1/odjqanpgdv2zolpduplee65ntoou1b56hg6gvgxvrt8dreh0/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
    selector: '.tinymce-editor',
    language: 'fr_FR',
    menubar: false,
    plugins: 'lists link autolink',
    toolbar: 'bold italic underline | bullist numlist | link | removeformat',
    branding: false,
    height: 200,
    content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 14px; }',
    setup: function (editor) {
        // Sync TinyMCE content back to textarea before form submit
        editor.on('change', function () { editor.save(); });
    }
});
</script>
<script>
function copyLink(inputId, btn) {
    var input = document.getElementById(inputId);
    if (!input) return;
    navigator.clipboard.writeText(input.value).then(function() {
        var icon = btn.querySelector('i');
        if (icon) {
            var prev = icon.className;
            icon.className = 'bi bi-check-lg text-success';
            setTimeout(function() { icon.className = prev; }, 2000);
        }
    }).catch(function() {
        input.select();
        document.execCommand('copy');
    });
}

// ── Photo management (new file preview + existing drag-reorder + AJAX delete) ──
(function () {
    'use strict';

    var dropZone     = document.getElementById('photoDropZone');
    var fileInput    = document.getElementById('photoFileInput');
    var btnBrowse    = document.getElementById('photoBtnBrowse');
    var btnClearAll  = document.getElementById('photoBtnClearAll');
    var previewList  = document.getElementById('newPhotoPreviewList');
    var wrapper      = document.getElementById('newPhotosWrapper');
    var countEl      = document.getElementById('newPhotosCount');
    var photoOrderEl = document.getElementById('photoOrderInput');
    var photoGrid    = document.getElementById('photoGrid');
    var form         = document.getElementById('formInfos');
    var saveBtn      = document.getElementById('btnSaveLogement');

    var fileDt      = new DataTransfer();
    var ignoreChg   = false;
    var MAX_SIZE    = 50 * 1024 * 1024;

    var logementId  = <?php echo (int)$logement_id; ?>;
    var csrfToken   = <?php echo json_encode($csrfToken); ?>;

    // ── Helpers ──────────────────────────────────────────────────────────────
    function formatBytes(b) {
        if (b < 1024) return b + ' o';
        if (b < 1048576) return (b/1024).toFixed(1) + ' Ko';
        return (b/1048576).toFixed(1) + ' Mo';
    }
    function escHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── New file preview ──────────────────────────────────────────────────────
    function refreshPreview() {
        var n = fileDt.files.length;
        if (countEl) countEl.textContent = n;
        if (wrapper) wrapper.style.display = n > 0 ? '' : 'none';
    }

    function addFilesToPreview(files) {
        var existing = new Set();
        for (var j = 0; j < fileDt.files.length; j++) {
            existing.add(fileDt.files[j].name + '|' + fileDt.files[j].size);
        }
        for (var i = 0; i < files.length; i++) {
            var f = files[i];
            if (f.size > MAX_SIZE) { alert('Fichier trop volumineux (max 50 Mo) : ' + f.name); continue; }
            var key = f.name + '|' + f.size;
            if (existing.has(key)) continue;
            fileDt.items.add(f);
            existing.add(key);

            var li = document.createElement('li');
            li.className = 'new-file-preview-item';
            li.dataset.index = fileDt.files.length - 1;
            var isVideo = f.type.startsWith('video/');
            var thumbHtml = isVideo
                ? '<div class="new-file-video-icon"><i class="bi bi-play-circle-fill text-white fs-5"></i></div>'
                : '<img class="new-file-thumb" src="" alt="">';
            li.innerHTML = thumbHtml
                + '<div class="new-file-info">'
                +   '<div class="new-file-name">' + escHtml(f.name) + '</div>'
                +   '<div class="new-file-size">' + formatBytes(f.size) + '</div>'
                + '</div>'
                + '<button type="button" class="btn-remove-new-photo" data-li-idx="' + (fileDt.files.length - 1) + '" title="Retirer">'
                +   '<i class="bi bi-x-lg"></i>'
                + '</button>';
            previewList.appendChild(li);

            if (!isVideo) {
                (function(img, file) {
                    var r = new FileReader();
                    r.onload = function(e) { img.src = e.target.result; };
                    r.readAsDataURL(file);
                })(li.querySelector('img'), f);
            }
        }
        refreshPreview();
    }

    function rebuildIndices() {
        previewList.querySelectorAll('.new-file-preview-item').forEach(function(li, i) {
            li.dataset.index = i;
            var btn = li.querySelector('.btn-remove-new-photo');
            if (btn) btn.dataset.liIdx = i;
        });
    }

    if (dropZone) {
        btnBrowse && btnBrowse.addEventListener('click', function() { fileInput.click(); });
        dropZone.addEventListener('click', function(e) { if (e.target === dropZone) fileInput.click(); });
        dropZone.addEventListener('dragover', function(e) { e.preventDefault(); dropZone.classList.add('drag-over'); });
        dropZone.addEventListener('dragleave', function() { dropZone.classList.remove('drag-over'); });
        dropZone.addEventListener('drop', function(e) {
            e.preventDefault(); dropZone.classList.remove('drag-over');
            if (e.dataTransfer.files.length > 0) addFilesToPreview(e.dataTransfer.files);
        });
        fileInput && fileInput.addEventListener('change', function() {
            if (ignoreChg) return;
            if (fileInput.files.length > 0) {
                addFilesToPreview(fileInput.files);
                ignoreChg = true; fileInput.value = ''; ignoreChg = false;
            }
        });
    }

    previewList && previewList.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-remove-new-photo');
        if (!btn) return;
        var idx = parseInt(btn.dataset.liIdx, 10);
        var newDt = new DataTransfer();
        for (var i = 0; i < fileDt.files.length; i++) {
            if (i !== idx) newDt.items.add(fileDt.files[i]);
        }
        fileDt = newDt;
        var li = btn.closest('.new-file-preview-item');
        if (li) li.remove();
        rebuildIndices(); refreshPreview();
    });

    btnClearAll && btnClearAll.addEventListener('click', function() {
        fileDt = new DataTransfer();
        previewList.innerHTML = '';
        refreshPreview();
    });

    // ── Existing photos: drag-to-reorder ─────────────────────────────────────
    var dragSrc = null;

    function updateOrderInput() {
        if (!photoGrid || !photoOrderEl) return;
        var ids = [];
        photoGrid.querySelectorAll('.photo-thumb[data-photo-id]').forEach(function(el) {
            ids.push(parseInt(el.dataset.photoId, 10));
        });
        photoOrderEl.value = JSON.stringify(ids);
    }

    if (photoGrid) {
        photoGrid.addEventListener('dragstart', function(e) {
            var thumb = e.target.closest('.photo-thumb');
            if (!thumb) return;
            dragSrc = thumb;
            thumb.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        photoGrid.addEventListener('dragend', function(e) {
            var thumb = e.target.closest('.photo-thumb');
            if (thumb) thumb.classList.remove('dragging');
            photoGrid.querySelectorAll('.photo-thumb').forEach(function(t) { t.classList.remove('drag-over-target'); });
            dragSrc = null;
        });
        photoGrid.addEventListener('dragover', function(e) {
            e.preventDefault();
            var thumb = e.target.closest('.photo-thumb');
            if (!thumb || thumb === dragSrc) return;
            photoGrid.querySelectorAll('.photo-thumb').forEach(function(t) { t.classList.remove('drag-over-target'); });
            thumb.classList.add('drag-over-target');
        });
        photoGrid.addEventListener('drop', function(e) {
            e.preventDefault();
            var target = e.target.closest('.photo-thumb');
            if (!target || target === dragSrc || !dragSrc) return;
            target.classList.remove('drag-over-target');
            // Insert dragSrc before or after target based on position
            var rect = target.getBoundingClientRect();
            var midX = rect.left + rect.width / 2;
            if (e.clientX < midX) {
                photoGrid.insertBefore(dragSrc, target);
            } else {
                photoGrid.insertBefore(dragSrc, target.nextSibling);
            }
            updateOrderInput();
        });
        updateOrderInput(); // initialize on page load
    }

    // ── Existing photos: AJAX delete ─────────────────────────────────────────
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-del-existing-photo');
        if (!btn) return;
        if (!confirm('Supprimer cette photo ?')) return;
        var pid    = btn.dataset.photoId;
        var thumb  = btn.closest('.photo-thumb');
        var fd     = new FormData();
        fd.append('csrf_token', btn.dataset.csrf || csrfToken);
        fd.append('action', 'delete_photo');
        fd.append('photo_id', pid);
        fetch('logement-detail.php?id=' + logementId, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        }).then(function(r) { return r.json(); })
          .then(function(data) {
              if (data.success) {
                  if (thumb) thumb.remove();
                  updateOrderInput();
              } else {
                  alert('Erreur lors de la suppression.');
              }
          })
          .catch(function() { alert('Erreur réseau.'); });
    });

    // ── Form submit: sync file DataTransfer to file input ────────────────────
    form && form.addEventListener('submit', function() {
        updateOrderInput();
        try {
            ignoreChg = true;
            fileInput.files = fileDt.files;
            ignoreChg = false;
        } catch(ex) { ignoreChg = false; }
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enregistrement…';
        }
    });

}());
</script>
</body>
</html>
