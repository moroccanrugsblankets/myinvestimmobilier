<?php
/**
 * Page "Intervention terminée" — Rapport du collaborateur
 *
 * URL: /signalement/intervention-terminee.php?token=xxx
 *
 * Permet au collaborateur de soumettre son rapport de fin d'intervention :
 *   - Photos et/ou vidéos après travaux (avec aperçu et suppression avant envoi)
 *   - Commentaire / notes d'intervention
 *
 * Les fichiers et le commentaire sont visibles dans /admin-v2/signalement-detail.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail-templates.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    http_response_code(400);
    die('Lien invalide.');
}

// ── Charger le collaborateur via le token ──────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT sc.*,
               sig.id           AS sig_id,
               sig.reference    AS sig_reference,
               sig.titre        AS sig_titre,
               sig.statut       AS sig_statut,
               sig.description  AS sig_description,
               sig.locataire_id AS sig_locataire_id,
               l.adresse        AS sig_adresse,
               l.reference      AS logement_reference,
               CONCAT(loc.prenom, ' ', loc.nom) AS locataire_nom,
               loc.prenom       AS locataire_prenom,
               loc.email        AS locataire_email,
               loc.token_signalement
        FROM signalements_collaborateurs sc
        INNER JOIN signalements sig ON sc.signalement_id = sig.id
        INNER JOIN logements l ON sig.logement_id = l.id
        LEFT JOIN locataires loc ON sig.locataire_id = loc.id
        WHERE sc.action_token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('intervention-terminee DB error: ' . $e->getMessage());
    http_response_code(400);
    die('Lien invalide ou expiré.');
}

if (!$row) {
    http_response_code(404);
    die('Lien invalide.');
}

$sigId  = (int)$row['sig_id'];
$isClos = ($row['sig_statut'] === 'clos');

$errors     = [];
$successMsg = '';
$done       = false;

// ── Traitement du formulaire POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($isClos) {
        $errors[] = 'Ce signalement est déjà clôturé.';
    } else {
        // ── Upload des fichiers (photos/vidéos) ────────────────────────────────
        $uploadedFiles = [];
        if (!empty($_FILES['medias']['name'][0])) {
            $uploadDir = __DIR__ . '/../uploads/signalements/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $allowedMime = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'video/mp4', 'video/quicktime', 'video/mpeg',
            ];

            $fileCount = count($_FILES['medias']['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                if (empty($_FILES['medias']['tmp_name'][$i])
                    || $_FILES['medias']['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }

                $origName = basename($_FILES['medias']['name'][$i]);
                $tmpName  = $_FILES['medias']['tmp_name'][$i];
                $mimeType = mime_content_type($tmpName) ?: 'application/octet-stream';

                if (!in_array($mimeType, $allowedMime, true)) {
                    $errors[] = 'Type de fichier non autorisé : ' . htmlspecialchars($origName);
                    continue;
                }
                if ($_FILES['medias']['size'][$i] > 50 * 1024 * 1024) {
                    $errors[] = 'Fichier trop volumineux (max 50 Mo) : ' . htmlspecialchars($origName);
                    continue;
                }

                $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $filename = 'sig_' . $sigId . '_apres_' . bin2hex(random_bytes(8)) . '.' . $ext;

                if (move_uploaded_file($tmpName, $uploadDir . $filename)) {
                    $uploadedFiles[] = [
                        'filename'      => $filename,
                        'original_name' => $origName,
                        'mime_type'     => $mimeType,
                        'taille'        => (int)$_FILES['medias']['size'][$i],
                        'photo_type'    => 'apres_travaux',
                        'uploaded_by'   => $row['collaborateur_nom'],
                    ];
                }
            }
        }

        if (empty($errors)) {
            $commentaire = trim($_POST['commentaire'] ?? '');

            // ── Mettre à jour le statut et les notes du signalement ────────────
            try {
                $fields = ['statut = ?', 'updated_at = NOW()', 'date_resolution = COALESCE(date_resolution, NOW())'];
                $params = ['resolu'];

                if ($commentaire !== '') {
                    $fields[] = 'notes_intervention = ?';
                    $params[] = $commentaire;
                }

                $params[] = $sigId;
                $pdo->prepare('UPDATE signalements SET ' . implode(', ', $fields) . ' WHERE id = ?')
                    ->execute($params);
            } catch (Exception $e) {
                error_log('intervention-terminee UPDATE signalement error: ' . $e->getMessage());
            }

            // ── Mettre à jour le statut du collaborateur ───────────────────────
            try {
                $pdo->prepare("
                    UPDATE signalements_collaborateurs
                    SET statut_collab = 'termine',
                        date_fin_intervention = COALESCE(date_fin_intervention, NOW())
                    WHERE action_token = ?
                ")->execute([$token]);
            } catch (Exception $e) {
                error_log('intervention-terminee UPDATE collab error: ' . $e->getMessage());
            }

            // ── Sauvegarder les fichiers ───────────────────────────────────────
            foreach ($uploadedFiles as $f) {
                try {
                    $pdo->prepare("
                        INSERT INTO signalements_photos
                            (signalement_id, filename, original_name, mime_type, taille,
                             photo_type, uploaded_by, collaborateur_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $sigId, $f['filename'], $f['original_name'],
                        $f['mime_type'], $f['taille'], $f['photo_type'],
                        $f['uploaded_by'], $row['collaborateur_id'],
                    ]);
                } catch (Exception $e) {
                    // Fallback sans colonnes optionnelles
                    try {
                        $pdo->prepare("
                            INSERT INTO signalements_photos
                                (signalement_id, filename, original_name, mime_type, taille)
                            VALUES (?, ?, ?, ?, ?)
                        ")->execute([
                            $sigId, $f['filename'], $f['original_name'],
                            $f['mime_type'], $f['taille'],
                        ]);
                    } catch (Exception $e2) {
                        error_log('intervention-terminee INSERT photo error: ' . $e2->getMessage());
                    }
                }
            }

            // ── Enregistrer dans la timeline ───────────────────────────────────
            $timelineDesc = 'Intervention terminée par ' . $row['collaborateur_nom'] . ' (rapport en ligne)';
            if (!empty($uploadedFiles)) {
                $timelineDesc .= ' + ' . count($uploadedFiles) . ' fichier(s) joint(s)';
            }
            $commentPreviewMax = 120;
            if ($commentaire !== '') {
                $timelineDesc .= ' — Commentaire : ' . mb_substr($commentaire, 0, $commentPreviewMax) . (mb_strlen($commentaire) > $commentPreviewMax ? '…' : '');
            }

            try {
                $pdo->prepare("
                    INSERT INTO signalements_actions
                        (signalement_id, type_action, description, acteur, nouvelle_valeur, ip_address)
                    VALUES (?, 'collab_termine', ?, ?, 'resolu', ?)
                ")->execute([
                    $sigId,
                    $timelineDesc,
                    $row['collaborateur_nom'],
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
            } catch (Exception $e) {
                error_log('intervention-terminee INSERT action error: ' . $e->getMessage());
            }

            // ── Notifier les admins ────────────────────────────────────────────
            $siteUrl     = rtrim($config['SITE_URL'] ?? '', '/');
            $lienAdmin   = $siteUrl . '/admin-v2/signalement-detail.php?id=' . $sigId;
            $companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';

            $nbHtml      = '';
            $coutHtml    = '';
            $notesHtml   = $commentaire !== ''
                ? '<div style="background:#f8f9fa;padding:10px;border-radius:4px;margin-top:10px;"><strong>Commentaire :</strong><br>' . nl2br(htmlspecialchars($commentaire)) . '</div>'
                : '';

            $adminVars = [
                'reference'               => $row['sig_reference'],
                'titre'                   => $row['sig_titre'],
                'adresse'                 => $row['sig_adresse'],
                'logement_reference'      => $row['logement_reference'] ?? '',
                'collab_nom'              => $row['collaborateur_nom'],
                'date_action'             => date('d/m/Y à H:i'),
                'lien_admin'              => $lienAdmin,
                'company'                 => $companyName,
                'nb_heures_html'          => $nbHtml,
                'cout_materiaux_html'     => $coutHtml,
                'notes_intervention_html' => $notesHtml,
            ];

            // Collect all admin emails (DB administrateurs table + config)
            $allAdminEmails = [];
            try {
                $stmtAdm = $pdo->query("SELECT email FROM administrateurs WHERE actif = 1 AND email IS NOT NULL AND email != ''");
                $allAdminEmails = $stmtAdm->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {
                error_log('intervention-terminee: could not fetch admin emails: ' . $e->getMessage());
            }
            $configAdminEmail = getAdminEmail();
            if (!empty($configAdminEmail) && !in_array(strtolower($configAdminEmail), array_map('strtolower', $allAdminEmails))) {
                array_unshift($allAdminEmails, $configAdminEmail);
            }
            foreach (array_unique($allAdminEmails) as $aEmail) {
                if (!empty($aEmail) && filter_var($aEmail, FILTER_VALIDATE_EMAIL)) {
                    sendTemplatedEmail(
                        'signalement_intervention_terminee_admin',
                        $aEmail,
                        $adminVars,
                        null, false, false,
                        ['contexte' => 'intervention_terminee_rapport;sig_id=' . $sigId]
                    );
                }
            }
            $stEmail = getServiceTechniqueEmail();
            $allAdminEmailsLower = array_map('strtolower', $allAdminEmails);
            if ($stEmail && !in_array(strtolower($stEmail), $allAdminEmailsLower) && strtolower($stEmail) !== strtolower($row['collaborateur_email'] ?? '')) {
                sendTemplatedEmail(
                    'signalement_intervention_terminee_admin',
                    $stEmail,
                    $adminVars,
                    null, false, false,
                    ['contexte' => 'intervention_terminee_rapport_st;sig_id=' . $sigId]
                );
            }

            // ── Notifier le locataire ──────────────────────────────────────────
            if (!empty($row['locataire_email'])) {
                $tenantToken      = $row['token_signalement'] ?? '';
                $lienConfirmation = $tenantToken
                    ? $siteUrl . '/signalement/confirmer-intervention.php?sig=' . $sigId . '&token=' . urlencode($tenantToken)
                    : '';

                sendTemplatedEmail(
                    'signalement_intervention_terminee_locataire',
                    $row['locataire_email'],
                    [
                        'prenom'             => $row['locataire_prenom'] ?? '',
                        'nom'                => $row['locataire_nom'] ?? '',
                        'reference'          => $row['sig_reference'],
                        'titre'              => $row['sig_titre'],
                        'adresse'            => $row['sig_adresse'],
                        'logement_reference' => $row['logement_reference'] ?? '',
                        'lien_confirmation'  => $lienConfirmation,
                        'company'            => $companyName,
                    ],
                    null, false, true,
                    ['contexte' => 'intervention_terminee_rapport_locataire;sig_id=' . $sigId]
                );
            }

            $done       = true;
            $successMsg = 'Votre rapport a été envoyé avec succès. Les administrateurs et le locataire ont été notifiés.';
        }
    }
}

$companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Intervention terminée — <?php echo htmlspecialchars($row['sig_reference']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f4f6f9; }
        .main-card {
            max-width: 680px;
            margin: 40px auto 60px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
            overflow: hidden;
        }
        .card-header-custom {
            padding: 30px;
            text-align: center;
            background: linear-gradient(135deg, #27ae60 0%, #1e8449 100%);
            color: #fff;
        }
        .card-body-custom { padding: 30px; }
        .sig-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }
        /* ── File preview list ────────────────────────────────── */
        .file-preview-list { list-style: none; padding: 0; margin: 0; }
        .file-preview-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            margin-bottom: 6px;
            background: #fff;
        }
        .file-preview-thumb {
            width: 52px;
            height: 42px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            flex-shrink: 0;
        }
        .file-preview-video-icon {
            width: 52px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #212529;
            border-radius: 4px;
            flex-shrink: 0;
        }
        .file-preview-info { flex: 1; min-width: 0; }
        .file-preview-name {
            font-size: 0.875rem;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .file-preview-size { font-size: 0.75rem; color: #6c757d; }
        .btn-remove-file {
            flex-shrink: 0;
            background: none;
            border: none;
            color: #dc3545;
            padding: 4px 8px;
            cursor: pointer;
            border-radius: 4px;
            line-height: 1;
        }
        .btn-remove-file:hover { background: #f8d7da; }
        .drop-zone {
            border: 2px dashed #ced4da;
            border-radius: 8px;
            padding: 24px 16px;
            text-align: center;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            background: #fafafa;
        }
        .drop-zone.drag-over {
            border-color: #27ae60;
            background: #f0fff4;
        }
        .drop-zone input[type=file] {
            display: none;
        }
    </style>
</head>
<body>
<div class="main-card">
    <div class="card-header-custom">
        <h1 class="h3 mb-1">🟢 Intervention terminée</h1>
        <p class="mb-0 opacity-75"><?php echo htmlspecialchars($companyName); ?></p>
    </div>

    <div class="card-body-custom">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $err): ?>
                <div><i class="bi bi-exclamation-circle me-1"></i><?php echo htmlspecialchars($err); ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($done): ?>
        <div class="alert alert-success text-center py-4">
            <i class="bi bi-check-circle-fill" style="font-size:2.5rem;"></i>
            <h4 class="mt-3"><?php echo htmlspecialchars($successMsg); ?></h4>
            <p class="mb-0 text-muted small">Vous pouvez fermer cette page.</p>
        </div>

        <?php elseif ($isClos): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>Ce signalement est déjà clôturé.
        </div>

        <?php else: ?>

        <!-- Informations du signalement -->
        <div class="sig-info">
            <div class="row g-2">
                <div class="col-sm-6">
                    <small class="text-muted d-block">Référence</small>
                    <strong class="font-monospace"><?php echo htmlspecialchars($row['sig_reference']); ?></strong>
                </div>
                <div class="col-sm-6">
                    <small class="text-muted d-block">Logement</small>
                    <span><?php echo htmlspecialchars($row['sig_adresse']); ?></span>
                    <?php if (!empty($row['logement_reference'])): ?>
                        <br><span class="badge bg-secondary font-monospace"><?php echo htmlspecialchars($row['logement_reference']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="col-12">
                    <small class="text-muted d-block">Titre</small>
                    <strong><?php echo htmlspecialchars($row['sig_titre']); ?></strong>
                </div>
                <?php if (!empty($row['locataire_nom'])): ?>
                <div class="col-12">
                    <small class="text-muted d-block">Locataire</small>
                    <span><?php echo htmlspecialchars($row['locataire_nom']); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" id="formIntervention">

            <!-- ── Zone d'ajout de fichiers ── -->
            <div class="mb-4">
                <label class="form-label fw-semibold">
                    <i class="bi bi-camera me-1"></i>Photos / Vidéos après intervention
                    <span class="text-muted fw-normal">(optionnel)</span>
                </label>

                <!-- Drop zone -->
                <div class="drop-zone" id="dropZone">
                    <input type="file" id="fileInput" name="medias[]" multiple
                           accept="image/*,video/*">
                    <i class="bi bi-cloud-upload fs-2 text-muted d-block mb-2"></i>
                    <p class="mb-1 fw-semibold">Glissez vos fichiers ici</p>
                    <p class="mb-2 text-muted small">ou</p>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnBrowse">
                        <i class="bi bi-folder2-open me-1"></i>Parcourir les fichiers
                    </button>
                    <p class="mt-2 mb-0 text-muted" style="font-size:.75rem;">
                        Formats acceptés : images (JPEG, PNG, GIF, WEBP) et vidéos (MP4, MOV).
                        Max 50 Mo par fichier.
                    </p>
                </div>

                <!-- Liste des fichiers sélectionnés -->
                <div id="fileListWrapper" class="mt-3" style="display:none;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold small">Fichiers sélectionnés (<span id="fileCount">0</span>)</span>
                        <button type="button" class="btn btn-outline-danger btn-sm" id="btnClearAll">
                            <i class="bi bi-trash me-1"></i>Tout supprimer
                        </button>
                    </div>
                    <ul class="file-preview-list" id="filePreviewList"></ul>
                </div>
            </div>

            <!-- ── Commentaire ── -->
            <div class="mb-4">
                <label for="commentaire" class="form-label fw-semibold">
                    <i class="bi bi-chat-text me-1"></i>Commentaire / Notes d'intervention
                    <span class="text-muted fw-normal">(optionnel)</span>
                </label>
                <textarea class="form-control" id="commentaire" name="commentaire" rows="4"
                          placeholder="Décrire le travail réalisé, les pièces changées, observations particulières…"></textarea>
            </div>

            <!-- ── Bouton d'envoi ── -->
            <div class="d-grid">
                <button type="submit" class="btn btn-success btn-lg fw-semibold" id="btnSubmit">
                    <i class="bi bi-check-circle me-2"></i>Envoyer le rapport
                </button>
            </div>

        </form>

        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    // DataTransfer used to rebuild the native file input
    var fileDataTransfer = new DataTransfer();

    var dropZone    = document.getElementById('dropZone');
    var fileInput   = document.getElementById('fileInput');
    var btnBrowse   = document.getElementById('btnBrowse');
    var btnClearAll = document.getElementById('btnClearAll');
    var previewList = document.getElementById('filePreviewList');
    var fileListWrapper = document.getElementById('fileListWrapper');
    var fileCountEl = document.getElementById('fileCount');
    var btnSubmit   = document.getElementById('btnSubmit');

    if (!dropZone) return; // page may show success state

    var MAX_SIZE = 50 * 1024 * 1024; // 50 MB

    // ── Helpers ──────────────────────────────────────────────────
    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' o';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' Ko';
        return (bytes / (1024 * 1024)).toFixed(1) + ' Mo';
    }

    function refreshInput() {
        // Rebuild fileInput.files from dt
        fileInput.files = fileDataTransfer.files;
        var n = fileDataTransfer.files.length;
        fileCountEl.textContent = n;
        fileListWrapper.style.display = n > 0 ? '' : 'none';
    }

    function addFilesToPreview(files) {
        var added = 0;
        for (var i = 0; i < files.length; i++) {
            var file = files[i];

            // Validate size
            if (file.size > MAX_SIZE) {
                alert('Fichier trop volumineux (max 50 Mo) : ' + file.name);
                continue;
            }

            // Avoid duplicates (name + size)
            var dup = false;
            for (var j = 0; j < fileDataTransfer.files.length; j++) {
                if (fileDataTransfer.files[j].name === file.name && fileDataTransfer.files[j].size === file.size) {
                    dup = true;
                    break;
                }
            }
            if (dup) continue;

            fileDataTransfer.items.add(file);
            added++;

            // Build list item
            var li = document.createElement('li');
            li.className = 'file-preview-item';
            li.dataset.index = fileDataTransfer.files.length - 1;

            var isVideo = file.type.startsWith('video/');

            var thumbHtml;
            if (isVideo) {
                thumbHtml = '<div class="file-preview-video-icon">'
                    + '<i class="bi bi-play-circle-fill text-white fs-4"></i>'
                    + '</div>';
            } else {
                thumbHtml = '<img class="file-preview-thumb" src="" alt="">';
            }

            li.innerHTML = thumbHtml
                + '<div class="file-preview-info">'
                +   '<div class="file-preview-name">' + escHtml(file.name) + '</div>'
                +   '<div class="file-preview-size">' + formatBytes(file.size) + '</div>'
                + '</div>'
                + '<button type="button" class="btn-remove-file" title="Supprimer" data-li-index="'  + (fileDataTransfer.files.length - 1) + '">'
                +   '<i class="bi bi-x-lg"></i>'
                + '</button>';

            previewList.appendChild(li);

            // Load image thumbnail async
            if (!isVideo) {
                (function (img, f) {
                    var reader = new FileReader();
                    reader.onload = function (e) { img.src = e.target.result; };
                    reader.readAsDataURL(f);
                })(li.querySelector('img'), file);
            }
        }

        if (added > 0) refreshInput();
    }

    function escHtml(str) {
        return str.replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;');
    }

    function rebuildIndices() {
        var items = previewList.querySelectorAll('.file-preview-item');
        items.forEach(function (li, idx) {
            li.dataset.index = idx;
            var btn = li.querySelector('.btn-remove-file');
            if (btn) btn.dataset.liIndex = idx;
        });
    }

    // ── Event: click "Parcourir" ──────────────────────────────────
    btnBrowse.addEventListener('click', function () {
        fileInput.click();
    });

    // ── Event: click drop zone itself ─────────────────────────────
    dropZone.addEventListener('click', function (e) {
        if (e.target === dropZone) fileInput.click();
    });

    // ── Event: file input change ──────────────────────────────────
    fileInput.addEventListener('change', function () {
        if (fileInput.files.length > 0) {
            addFilesToPreview(fileInput.files);
            // Reset native input (we manage files via dt)
            fileInput.value = '';
        }
    });

    // ── Event: drag & drop ────────────────────────────────────────
    dropZone.addEventListener('dragover', function (e) {
        e.preventDefault();
        dropZone.classList.add('drag-over');
    });
    dropZone.addEventListener('dragleave', function () {
        dropZone.classList.remove('drag-over');
    });
    dropZone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropZone.classList.remove('drag-over');
        if (e.dataTransfer.files.length > 0) {
            addFilesToPreview(e.dataTransfer.files);
        }
    });

    // ── Event: remove individual file ────────────────────────────
    previewList.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-remove-file');
        if (!btn) return;
        var idx = parseInt(btn.dataset.liIndex, 10);

        // Rebuild DataTransfer without this file
        var newDt = new DataTransfer();
        for (var i = 0; i < fileDataTransfer.files.length; i++) {
            if (i !== idx) newDt.items.add(fileDataTransfer.files[i]);
        }
        fileDataTransfer = newDt;

        // Remove list item
        var li = btn.closest('.file-preview-item');
        if (li) li.remove();

        rebuildIndices();
        refreshInput();
    });

    // ── Event: clear all ─────────────────────────────────────────
    btnClearAll.addEventListener('click', function () {
        fileDataTransfer = new DataTransfer();
        previewList.innerHTML = '';
        refreshInput();
    });

    // ── Event: form submit ────────────────────────────────────────
    var form = document.getElementById('formIntervention');
    if (form) {
        form.addEventListener('submit', function () {
            if (btnSubmit) {
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Envoi en cours…';
            }
        });
    }

})();
</script>
</body>
</html>
