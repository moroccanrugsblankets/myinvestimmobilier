<?php
/**
 * Page d'action collaborateur — Workflow signalement
 *
 * URL: /signalement/collab-action.php?token=xxx&action=pris_en_charge
 *
 * Cette page gère les 4 boutons d'action présents dans les emails envoyés aux collaborateurs :
 *   - pris_en_charge : Confirme la prise en charge
 *   - sur_place       : Confirme la présence sur place (+ upload photos avant travaux)
 *   - termine         : Intervention terminée (+ upload photos après travaux + heures/coût)
 *   - impossible      : Intervention impossible / reportée
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail-templates.php';

$token  = trim($_GET['token'] ?? '');
$action = trim($_GET['action'] ?? '');

$validActions = ['pris_en_charge', 'sur_place', 'termine', 'impossible'];

// ── Validation des paramètres ──────────────────────────────────────────────────
if (empty($token) || !in_array($action, $validActions, true)) {
    http_response_code(400);
    die('Lien invalide ou expiré.');
}

// ── Charger le collaborateur via le token ─────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT sc.*,
               sig.id           AS sig_id,
               sig.reference    AS sig_reference,
               sig.titre        AS sig_titre,
               sig.statut       AS sig_statut,
               sig.description  AS sig_description,
               sig.locataire_id AS sig_locataire_id,
               sig.logement_id  AS sig_logement_id,
               sig.contrat_id   AS sig_contrat_id,
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
    error_log('collab-action DB error: ' . $e->getMessage());
    http_response_code(400);
    die('Lien invalide ou expiré.');
}

if (!$row) {
    http_response_code(404);
    die('Lien invalide ou expiré.');
}

$sigId   = (int)$row['sig_id'];
$isClos  = in_array($row['sig_statut'], ['clos', 'resolu'], true);

// Mapping action → statut signalement
$actionStatutMap = [
    'pris_en_charge' => 'pris_en_charge',
    'sur_place'      => 'sur_place',
    'termine'        => 'resolu',
    'impossible'     => 'reporte',
];

$actionLabels = [
    'pris_en_charge' => 'Pris en charge',
    'sur_place'      => 'Sur place',
    'termine'        => 'Intervention terminée',
    'impossible'     => 'Impossible / Reporté',
];

$actionColors = [
    'pris_en_charge' => '#3498db',
    'sur_place'      => '#e67e22',
    'termine'        => '#27ae60',
    'impossible'     => '#e74c3c',
];

$errors     = [];
$successMsg = '';
$done       = false;

// ── Traitement du formulaire POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['collab_action'] ?? $action;

    if (!in_array($postAction, $validActions, true)) {
        $errors[] = 'Action invalide.';
    } elseif ($isClos && !in_array($postAction, ['pris_en_charge', 'sur_place'])) {
        // 'pris_en_charge' and 'sur_place' are allowed even when the status is 'resolu'
        // (which counts as $isClos here) because an admin may re-open a signalement
        // by changing its status back. For 'termine' and 'impossible', we block on closed dossiers.
        $errors[] = 'Ce signalement est déjà clôturé.';
    } else {
        // ── Uploader les photos ────────────────────────────────────────────────
        $uploadedPhotos = [];
        if (!empty($_FILES['photos']['name'][0])) {
            $uploadDir = __DIR__ . '/../uploads/signalements/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $photoType = ($postAction === 'sur_place') ? 'avant_travaux' : 'apres_travaux';
            $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/quicktime', 'video/mpeg'];

            foreach ($_FILES['photos']['tmp_name'] as $idx => $tmpName) {
                if (empty($tmpName) || $_FILES['photos']['error'][$idx] !== UPLOAD_ERR_OK) {
                    continue;
                }
                $origName = basename($_FILES['photos']['name'][$idx]);
                $mimeType = mime_content_type($tmpName) ?: 'application/octet-stream';

                if (!in_array($mimeType, $allowedMime, true)) {
                    $errors[] = 'Type de fichier non autorisé : ' . htmlspecialchars($origName);
                    continue;
                }
                if ($_FILES['photos']['size'][$idx] > 50 * 1024 * 1024) {
                    $errors[] = 'Fichier trop volumineux (max 50 Mo) : ' . htmlspecialchars($origName);
                    continue;
                }

                $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $filename = 'sig_' . $sigId . '_' . $photoType . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($tmpName, $uploadDir . $filename)) {
                    $uploadedPhotos[] = [
                        'filename'      => $filename,
                        'original_name' => $origName,
                        'mime_type'     => $mimeType,
                        'taille'        => (int)$_FILES['photos']['size'][$idx],
                        'photo_type'    => $photoType,
                        'uploaded_by'   => $row['collaborateur_nom'],
                    ];
                }
            }
        }

        if (empty($errors)) {
            $newStatut   = $actionStatutMap[$postAction];
            $nbHeures    = ($postAction === 'termine') ? (floatval($_POST['nb_heures'] ?? 0) ?: null) : null;
            $coutMat     = ($postAction === 'termine') ? (floatval($_POST['cout_materiaux'] ?? 0) ?: null) : null;
            $notesInter  = ($postAction === 'termine' || $postAction === 'impossible')
                ? trim($_POST['notes_intervention'] ?? '')
                : '';
            $motif       = ($postAction === 'impossible') ? trim($_POST['motif'] ?? '') : '';

            // ── Mettre à jour le statut du signalement ─────────────────────────
            try {
                $fields  = ['statut = ?', 'updated_at = NOW()'];
                $params  = [$newStatut];

                if ($postAction === 'termine') {
                    $fields[] = 'date_resolution = COALESCE(date_resolution, NOW())';
                    if ($nbHeures !== null)  { $fields[] = 'nb_heures = ?';         $params[] = $nbHeures; }
                    if ($coutMat  !== null)  { $fields[] = 'cout_materiaux = ?';    $params[] = $coutMat; }
                    if ($notesInter !== '')  { $fields[] = 'notes_intervention = ?'; $params[] = $notesInter; }
                } elseif ($postAction === 'impossible' && $notesInter !== '') {
                    $fields[] = 'notes_intervention = ?';
                    $params[] = $notesInter;
                } elseif ($postAction === 'sur_place' && empty($row['date_intervention'])) {
                    $fields[] = 'date_intervention = NOW()';
                }

                $params[] = $sigId;
                $pdo->prepare('UPDATE signalements SET ' . implode(', ', $fields) . ' WHERE id = ?')
                    ->execute($params);
            } catch (Exception $e) {
                error_log('collab-action UPDATE signalement error: ' . $e->getMessage());
            }

            // ── Mettre à jour le statut du collaborateur ───────────────────────
            try {
                $collabStatutMap = [
                    'pris_en_charge' => 'pris_en_charge',
                    'sur_place'      => 'sur_place',
                    'termine'        => 'termine',
                    'impossible'     => 'impossible',
                ];
                $tsField = [
                    'pris_en_charge' => 'date_prise_en_charge',
                    'sur_place'      => 'date_sur_place',
                    'termine'        => 'date_fin_intervention',
                    'impossible'     => null,
                ];
                $tsCol = $tsField[$postAction] ?? null;
                $tsSQL = $tsCol ? ", $tsCol = COALESCE($tsCol, NOW())" : '';
                $pdo->prepare("
                    UPDATE signalements_collaborateurs
                    SET statut_collab = ? $tsSQL
                    WHERE action_token = ?
                ")->execute([$collabStatutMap[$postAction], $token]);
            } catch (Exception $e) {
                error_log('collab-action UPDATE collaborateur error: ' . $e->getMessage());
            }

            // ── Sauvegarder les photos ─────────────────────────────────────────
            foreach ($uploadedPhotos as $ph) {
                try {
                    $pdo->prepare("
                        INSERT INTO signalements_photos
                            (signalement_id, filename, original_name, mime_type, taille, photo_type, uploaded_by, collaborateur_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $sigId, $ph['filename'], $ph['original_name'],
                        $ph['mime_type'], $ph['taille'], $ph['photo_type'],
                        $ph['uploaded_by'], $row['collaborateur_id'],
                    ]);
                } catch (Exception $e) {
                    // Fallback without new columns
                    try {
                        $pdo->prepare("
                            INSERT INTO signalements_photos (signalement_id, filename, original_name, mime_type, taille)
                            VALUES (?, ?, ?, ?, ?)
                        ")->execute([$sigId, $ph['filename'], $ph['original_name'], $ph['mime_type'], $ph['taille']]);
                    } catch (Exception $e2) {
                        error_log('collab-action INSERT photo error: ' . $e2->getMessage());
                    }
                }
            }

            // ── Enregistrer dans la timeline ───────────────────────────────────
            $descriptions = [
                'pris_en_charge' => "Pris en charge par {$row['collaborateur_nom']} (action email)",
                'sur_place'      => "Sur place — {$row['collaborateur_nom']} (action email)" . (!empty($uploadedPhotos) ? ' + ' . count($uploadedPhotos) . ' photo(s) avant travaux' : ''),
                'termine'        => "Intervention terminée par {$row['collaborateur_nom']} (action email)" . (!empty($uploadedPhotos) ? ' + ' . count($uploadedPhotos) . ' photo(s) après travaux' : ''),
                'impossible'     => "Intervention impossible/reportée — {$row['collaborateur_nom']} (action email)" . (!empty($motif) ? " : $motif" : ''),
            ];
            try {
                $pdo->prepare("
                    INSERT INTO signalements_actions
                        (signalement_id, type_action, description, acteur, nouvelle_valeur, ip_address)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([
                    $sigId,
                    'collab_' . $postAction,
                    $descriptions[$postAction],
                    $row['collaborateur_nom'],
                    $newStatut,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
            } catch (Exception $e) {
                error_log('collab-action INSERT action error: ' . $e->getMessage());
            }

            // ── Notifier les admins ────────────────────────────────────────────
            $templateAdminMap = [
                'pris_en_charge' => 'signalement_pris_en_charge_admin',
                'sur_place'      => 'signalement_sur_place_admin',
                'termine'        => 'signalement_intervention_terminee_admin',
                'impossible'     => 'signalement_impossible_admin',
            ];
            $siteUrl   = rtrim($config['SITE_URL'] ?? '', '/');
            $lienAdmin = $siteUrl . '/admin-v2/signalement-detail.php?id=' . $sigId;
            $companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';

            $adminVars = [
                'reference'          => $row['sig_reference'],
                'titre'              => $row['sig_titre'],
                'adresse'            => $row['sig_adresse'],
                'logement_reference' => $row['logement_reference'] ?? '',
                'collab_nom'         => $row['collaborateur_nom'],
                'date_action'        => date('d/m/Y à H:i'),
                'lien_admin'         => $lienAdmin,
                'company'            => $companyName,
            ];
            if ($postAction === 'termine') {
                $adminVars['nb_heures_html']        = $nbHeures ? '<p style="margin:5px 0;"><strong>Heures :</strong> ' . number_format((float)$nbHeures, 2, ',', ' ') . ' h</p>' : '';
                $adminVars['cout_materiaux_html']   = $coutMat  ? '<p style="margin:5px 0;"><strong>Matériaux :</strong> ' . number_format((float)$coutMat, 2, ',', ' ') . ' €</p>' : '';
                $adminVars['notes_intervention_html'] = $notesInter ? '<div style="background:#f8f9fa;padding:10px;border-radius:4px;margin-top:10px;"><strong>Notes :</strong><br>' . nl2br(htmlspecialchars($notesInter)) . '</div>' : '';
            }
            if ($postAction === 'impossible') {
                $adminVars['motif_html'] = $motif ? '<p style="margin:5px 0;"><strong>Motif :</strong> ' . htmlspecialchars($motif) . '</p>' : '';
            }

            $adminEmail = $config['ADMIN_EMAIL'] ?? '';
            if ($adminEmail) {
                sendTemplatedEmail(
                    $templateAdminMap[$postAction],
                    $adminEmail,
                    $adminVars,
                    null, false, false,
                    ['contexte' => 'collab_action_' . $postAction . ';sig_id=' . $sigId]
                );
            }
            // Notify service technique as well
            $stEmail = getServiceTechniqueEmail();
            if ($stEmail && $stEmail !== $adminEmail && $stEmail !== $row['collaborateur_email']) {
                sendTemplatedEmail(
                    $templateAdminMap[$postAction],
                    $stEmail,
                    $adminVars,
                    null, false, false,
                    ['contexte' => 'collab_action_' . $postAction . '_st;sig_id=' . $sigId]
                );
            }

            // ── Envoyer mail au locataire si intervention terminée ─────────────
            if ($postAction === 'termine' && !empty($row['locataire_email'])) {
                // Build confirmation link using tenant token
                $tenantToken    = $row['token_signalement'] ?? '';
                $lienConfirmation = $tenantToken
                    ? $siteUrl . '/signalement/confirmer-intervention.php?sig=' . $sigId . '&token=' . urlencode($tenantToken)
                    : '';

                $tenantVars = [
                    'prenom'             => $row['locataire_prenom'] ?? '',
                    'nom'                => $row['locataire_nom'] ?? '',
                    'reference'          => $row['sig_reference'],
                    'titre'              => $row['sig_titre'],
                    'adresse'            => $row['sig_adresse'],
                    'logement_reference' => $row['logement_reference'] ?? '',
                    'lien_confirmation'  => $lienConfirmation,
                    'company'            => $companyName,
                ];
                sendTemplatedEmail(
                    'signalement_intervention_terminee_locataire',
                    $row['locataire_email'],
                    $tenantVars,
                    null, false, true,
                    ['contexte' => 'collab_termine_locataire;sig_id=' . $sigId]
                );
            }

            $done = true;
            switch ($postAction) {
                case 'pris_en_charge':
                    $successMsg = 'Prise en charge confirmée. Les administrateurs ont été notifiés.';
                    break;
                case 'sur_place':
                    $successMsg = 'Présence sur place confirmée. Les administrateurs ont été notifiés.';
                    break;
                case 'termine':
                    $successMsg = 'Intervention marquée comme terminée. Les administrateurs et le locataire ont été notifiés.';
                    break;
                case 'impossible':
                    $successMsg = 'Rapport d\'impossibilité envoyé. Les administrateurs ont été notifiés.';
                    break;
                default:
                    $successMsg = 'Action enregistrée.';
                    break;
            }
        }
    }
}

$actionLabel = $actionLabels[$action] ?? $action;
$actionColor = $actionColors[$action] ?? '#333';
$needsPhotos = in_array($action, ['sur_place', 'termine'], true);
$needsDetails = $action === 'termine';
$needsMotif  = $action === 'impossible';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Action collaborateur — <?php echo htmlspecialchars($row['sig_reference']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f4f6f9; }
        .action-card { max-width: 640px; margin: 40px auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.12); overflow: hidden; }
        .action-header { padding: 30px; text-align: center; color: #fff; }
        .action-body { padding: 30px; }
        .sig-info { background: #f8f9fa; border-radius: 8px; padding: 16px; margin-bottom: 24px; }
        .btn-submit { font-size: 1.05rem; padding: 12px 28px; border-radius: 8px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="action-card">
        <div class="action-header" style="background: linear-gradient(135deg, <?php echo $actionColor; ?> 0%, <?php echo $actionColor; ?>cc 100%);">
            <h1 class="h3 mb-1">
                <?php
                $icons = [
                    'pris_en_charge' => '🔵',
                    'sur_place'      => '🟠',
                    'termine'        => '🟢',
                    'impossible'     => '🔴',
                ];
                echo ($icons[$action] ?? '📋') . ' ' . htmlspecialchars($actionLabel);
                ?>
            </h1>
            <p class="mb-0 opacity-75"><?php echo htmlspecialchars($config['COMPANY_NAME'] ?? 'My Invest Immobilier'); ?></p>
        </div>

        <div class="action-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $e): ?>
                        <div><i class="bi bi-exclamation-circle me-1"></i><?php echo htmlspecialchars($e); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($done): ?>
                <div class="alert alert-success text-center py-4">
                    <i class="bi bi-check-circle-fill" style="font-size:2.5rem;"></i>
                    <h4 class="mt-3"><?php echo htmlspecialchars($successMsg); ?></h4>
                </div>
            <?php elseif ($isClos && !in_array($action, ['pris_en_charge', 'sur_place'])): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>Ce signalement est déjà résolu ou clôturé.
                </div>
            <?php else: ?>

                <!-- Informations du signalement -->
                <div class="sig-info">
                    <div class="row g-2">
                        <div class="col-12">
                            <small class="text-muted d-block">Référence</small>
                            <strong class="font-monospace"><?php echo htmlspecialchars($row['sig_reference']); ?></strong>
                        </div>
                        <div class="col-12">
                            <small class="text-muted d-block">Titre</small>
                            <strong><?php echo htmlspecialchars($row['sig_titre']); ?></strong>
                        </div>
                        <div class="col-12">
                            <small class="text-muted d-block">Logement</small>
                            <?php echo htmlspecialchars($row['sig_adresse']); ?>
                            <?php if (!empty($row['logement_reference'])): ?>
                                &nbsp;<span class="badge bg-secondary font-monospace"><?php echo htmlspecialchars($row['logement_reference']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($row['locataire_nom'])): ?>
                        <div class="col-12">
                            <small class="text-muted d-block">Locataire</small>
                            <?php echo htmlspecialchars($row['locataire_nom']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="collab_action" value="<?php echo htmlspecialchars($action); ?>">

                    <!-- Photos -->
                    <?php if ($needsPhotos): ?>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-camera me-1"></i>
                            <?php echo $action === 'sur_place' ? 'Photos avant travaux (optionnel)' : 'Photos après travaux (optionnel)'; ?>
                        </label>
                        <input type="file" class="form-control" name="photos[]" multiple
                               accept="image/*,video/*">
                        <div class="form-text">Formats acceptés : images et vidéos. Max 50 Mo par fichier.</div>
                    </div>
                    <?php endif; ?>

                    <!-- Détails d'intervention terminée -->
                    <?php if ($needsDetails): ?>
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-clock me-1"></i>Heures d'intervention
                            </label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="nb_heures"
                                       min="0" max="999" step="0.5" placeholder="Ex: 2">
                                <span class="input-group-text">h</span>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-currency-euro me-1"></i>Coût des matériaux
                            </label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="cout_materiaux"
                                       min="0" max="99999" step="0.01" placeholder="Ex: 45.50">
                                <span class="input-group-text">€</span>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes d'intervention</label>
                            <textarea class="form-control" name="notes_intervention" rows="3"
                                      placeholder="Décrire le travail réalisé, les pièces changées..."></textarea>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Motif impossible/reporté -->
                    <?php if ($needsMotif): ?>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-chat-text me-1"></i>Motif du report / de l'impossibilité
                        </label>
                        <textarea class="form-control" name="motif" rows="3" required
                                  placeholder="Ex: Pièce manquante, accès refusé, report demandé par le locataire..."></textarea>
                        <label class="form-label fw-semibold mt-3">Notes complémentaires</label>
                        <textarea class="form-control" name="notes_intervention" rows="2"
                                  placeholder="Informations supplémentaires..."></textarea>
                    </div>
                    <?php endif; ?>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-submit text-white"
                                style="background-color:<?php echo htmlspecialchars($actionColor); ?>; border-color:<?php echo htmlspecialchars($actionColor); ?>;">
                            <i class="bi bi-check-circle me-2"></i>
                            Confirmer : <?php echo htmlspecialchars($actionLabel); ?>
                        </button>
                    </div>
                </form>

            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
