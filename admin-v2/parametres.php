<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update') {
        $hasError = false;

        // Handle checkbox/multi-select fields that need JSON serialization
        $jsonCheckboxFields = ['statuts_pro_acceptes', 'nb_occupants_acceptes'];
        foreach ($jsonCheckboxFields as $field) {
            if (!isset($_POST['parametres'][$field])) {
                // If the checkbox group sent nothing (empty selection), store empty JSON array
                $_POST['parametres'][$field] = '[]';
            } else if (is_array($_POST['parametres'][$field])) {
                // Convert array of checked values to JSON string
                $_POST['parametres'][$field] = json_encode(array_values($_POST['parametres'][$field]));
            }
        }

        foreach ($_POST['parametres'] as $cle => $valeur) {
            // For the SMTP password field: if submitted empty, keep the existing DB value
            if ($cle === 'smtp_password' && $valeur === '') {
                continue;
            }

            // Validate JSON parameters
            $stmt = $pdo->prepare("SELECT type FROM parametres WHERE cle = ?");
            $stmt->execute([$cle]);
            $param = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($param && $param['type'] === 'json') {
                json_decode($valeur);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $_SESSION['error'] = "Erreur JSON dans le paramètre $cle: " . json_last_error_msg();
                    $hasError = true;
                    break;
                }
            }
            
            $stmt = $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = ?");
            $stmt->execute([$valeur, $cle]);
        }
        
        if (!$hasError) {
            $_SESSION['success'] = "Paramètres mis à jour avec succès";
        }
        header('Location: parametres.php');
        exit;
    }

    // ── Upload / suppression de la signature société ──────────────────────────
    if ($_POST['action'] === 'upload_signature_societe') {
        $uploadFile = $_FILES['signature_societe_image'] ?? null;
        $enabled    = !empty($_POST['signature_societe_enabled']) ? '1' : '0';

        $doUpload = $uploadFile && $uploadFile['error'] !== UPLOAD_ERR_NO_FILE;

        if ($doUpload) {
            // Delete old file
            $oldStmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'signature_societe_image'");
            $oldStmt->execute();
            $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);
            if ($oldRow && !empty($oldRow['valeur'])) {
                $oldPath = dirname(__DIR__) . '/uploads/' . $oldRow['valeur'];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }

            // Validate file
            $allowedMime = ['image/png', 'image/jpeg', 'image/jpg'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($uploadFile['tmp_name']);
            if (!in_array($mime, $allowedMime, true)) {
                $_SESSION['error'] = "Format invalide. Utilisez PNG ou JPEG.";
                header('Location: parametres.php#signature-societe');
                exit;
            }
            if ($uploadFile['size'] > 2 * 1024 * 1024) {
                $_SESSION['error'] = "Fichier trop volumineux (max 2 MB).";
                header('Location: parametres.php#signature-societe');
                exit;
            }

            $ext      = ($mime === 'image/png') ? 'png' : 'jpg';
            $filename = 'company_signature_' . time() . '.' . $ext;
            $dest     = dirname(__DIR__) . '/uploads/' . $filename;

            if (!move_uploaded_file($uploadFile['tmp_name'], $dest)) {
                $_SESSION['error'] = "Erreur lors de l'enregistrement du fichier.";
                header('Location: parametres.php#signature-societe');
                exit;
            }

            // Save path in parametres
            $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES ('signature_societe_image', ?, 'string', 'general', 'Chemin de la signature société') ON DUPLICATE KEY UPDATE valeur = ?, updated_at = NOW()")
                ->execute([$filename, $filename]);
            // Also keep etat_lieux signature in sync (same image)
            $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES ('signature_societe_etat_lieux_image', ?, 'string', 'general', 'Chemin de la signature société pour états des lieux') ON DUPLICATE KEY UPDATE valeur = ?, updated_at = NOW()")
                ->execute([$filename, $filename]);
        }

        // Save enabled flags
        $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES ('signature_societe_enabled', ?, 'boolean', 'general', 'Activer la signature société sur les contrats') ON DUPLICATE KEY UPDATE valeur = ?, updated_at = NOW()")
            ->execute([$enabled, $enabled]);
        $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES ('signature_societe_etat_lieux_enabled', ?, 'boolean', 'general', 'Activer la signature société sur les états des lieux') ON DUPLICATE KEY UPDATE valeur = ?, updated_at = NOW()")
            ->execute([$enabled, $enabled]);

        $_SESSION['success'] = "Signature société mise à jour.";
        header('Location: parametres.php#signature-societe');
        exit;
    }

    if ($_POST['action'] === 'delete_signature_societe') {
        $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'signature_societe_image'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['valeur'])) {
            $filePath = dirname(__DIR__) . '/uploads/' . $row['valeur'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        $pdo->prepare("UPDATE parametres SET valeur = '', updated_at = NOW() WHERE cle IN ('signature_societe_image', 'signature_societe_etat_lieux_image')")->execute();
        $_SESSION['success'] = "Signature société supprimée.";
        header('Location: parametres.php#signature-societe');
        exit;
    }
}

// Groups managed on dedicated pages — hide them from this generic settings page
$groupesExclus = [
    'contrats',       // → contrat-configuration.php
    'templates',      // → email-templates.php
    'stripe',         // → stripe-configuration.php
    'twilio',         // → paramètres Twilio (section spécialisée)
    'backup',         // → sauvegardes.php
    'signalement',    // → guide-reparations.php
    'etats_lieux',    // → etat-lieux-configuration.php
    'bilan_logement', // → bilan-logement-configuration.php
    'decomptes',      // → decompte-configuration.php
    'quittances',     // → quittance-configuration.php
    'workflow',       // → candidatures-configuration.php
    'inventaires',    // → inventaire-configuration.php
    'rappel_loyers',  // → configuration-rappels-loyers.php
    'recaptcha',      // → recaptcha-configuration.php
];

// Individual parameter keys managed on dedicated pages — hide from this generic page
$clesExclues = [
    'etat_lieux_email_subject',      // → email-templates.php
    'etat_lieux_email_template',     // → email-templates.php
    'inventaire_template_html',      // → inventaire-configuration.php
    'inventaire_sortie_template_html', // → inventaire-configuration.php
    'inventaire_items_template',     // → inventaire-configuration.php
    'rappel_loyers_actif',           // → configuration-rappels-loyers.php
    'rappel_loyers_destinataires',   // → configuration-rappels-loyers.php
    'rappel_loyers_heure_execution', // → configuration-rappels-loyers.php
    'rappel_loyers_inclure_bouton',  // → configuration-rappels-loyers.php
    'rappel_loyers_dates_envoi',     // → configuration-rappels-loyers.php
    'delai_expiration_lien_contrat', // → contrat-configuration.php
    'delai_reponse_valeur',          // → candidatures-configuration.php
    'delai_reponse_unite',           // → candidatures-configuration.php
    'jours_ouvres_debut',            // → candidatures-configuration.php
    'jours_ouvres_fin',              // → candidatures-configuration.php
];
$placeholdersCles = implode(',', array_fill(0, count($clesExclues), '?'));
$placeholders  = implode(',', array_fill(0, count($groupesExclus), '?'));
$stmt = $pdo->prepare("SELECT * FROM parametres WHERE groupe NOT IN ($placeholders) AND cle NOT IN ($placeholdersCles) ORDER BY groupe, cle");
$stmt->execute(array_merge($groupesExclus, $clesExclues));
$allParams = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group parameters by category
$parametres = [];
foreach ($allParams as $param) {
    $parametres[$param['groupe']][] = $param;
}

// Load company signature data for the dedicated section
$signatureSocieteImage   = '';
$signatureSocieteEnabled = false;
try {
    $stmtSig = $pdo->prepare("SELECT cle, valeur FROM parametres WHERE cle IN ('signature_societe_image','signature_societe_enabled')");
    $stmtSig->execute();
    foreach ($stmtSig->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['cle'] === 'signature_societe_image')   $signatureSocieteImage   = $row['valeur'];
        if ($row['cle'] === 'signature_societe_enabled') $signatureSocieteEnabled = in_array($row['valeur'], ['1', 'true', 'on'], true);
    }
} catch (Exception $e) { /* columns may not exist yet */ }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paramètres - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .param-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .param-card h5 {
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3498db;
        }
        .param-item {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #ecf0f1;
        }
        .param-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .param-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .param-description {
            font-size: 0.9em;
            color: #7f8c8d;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <h4>Paramètres de l'application</h4>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="parametres.php">
            <input type="hidden" name="action" value="update">

            <?php foreach ($parametres as $groupe => $params): ?>
                <div class="param-card">
                    <h5>
                        <i class="bi bi-<?php echo $groupe === 'workflow' ? 'arrow-repeat' : ($groupe === 'email' ? 'envelope-at' : 'check-circle'); ?>"></i>
                        <?php 
                        $groupeTitles = [
                            'workflow' => 'Workflow et Délais',
                            'criteres' => 'Critères d\'Acceptation',
                            'general' => 'Général',
                            'email' => 'Configuration Email & SMTP'
                        ];
                        echo $groupeTitles[$groupe] ?? ucfirst($groupe);
                        ?>
                    </h5>

                    <?php 
                    // Group delay parameters together (kept for compatibility, though workflow params are now excluded)
                    $delayParamsKeys = ['delai_reponse_valeur', 'delai_reponse_unite'];
                    $obsoleteParams = ['delai_reponse_jours', 'delai_refus_auto_heures', 'email_admin']; // Parameters to hide
                    $delayParams = [];
                    $otherParams = [];
                    foreach ($params as $param) {
                        // Skip obsolete parameters
                        if (in_array($param['cle'], $obsoleteParams)) {
                            continue;
                        }
                        
                        if (in_array($param['cle'], $delayParamsKeys)) {
                            $delayParams[$param['cle']] = $param;
                        } else {
                            $otherParams[] = $param;
                        }
                    }
                    
                    // Display delay parameters together if both exist
                    if (count($delayParams) === 2): ?>
                        <div class="param-item">
                            <label class="param-label">
                                Délai de réponse automatique
                            </label>
                            <div class="param-description">
                                Délai avant l'envoi automatique de la réponse aux candidatures
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label">Valeur</label>
                                    <input type="number" 
                                           name="parametres[delai_reponse_valeur]" 
                                           class="form-control" 
                                           value="<?php echo htmlspecialchars($delayParams['delai_reponse_valeur']['valeur']); ?>"
                                           min="1"
                                           required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Unité</label>
                                    <select name="parametres[delai_reponse_unite]" class="form-select">
                                        <option value="minutes" <?php echo $delayParams['delai_reponse_unite']['valeur'] === 'minutes' ? 'selected' : ''; ?>>Minutes</option>
                                        <option value="heures" <?php echo $delayParams['delai_reponse_unite']['valeur'] === 'heures' ? 'selected' : ''; ?>>Heures</option>
                                        <option value="jours" <?php echo $delayParams['delai_reponse_unite']['valeur'] === 'jours' ? 'selected' : ''; ?>>Jours (ouvrés)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php foreach ($otherParams as $param): ?>
                        <div class="param-item">
                            <label class="param-label">
                                <?php 
                                $labels = [
                                    'jours_ouvres_debut' => 'Premier jour ouvré (1 = Lundi)',
                                    'jours_ouvres_fin' => 'Dernier jour ouvré (5 = Vendredi)',
                                    'revenus_min_requis' => 'Revenus nets mensuels minimum requis (€)',
                                    'statuts_pro_acceptes' => 'Statuts professionnels acceptés',
                                    'type_revenus_accepte' => 'Type de revenus accepté',
                                    'nb_occupants_acceptes' => 'Nombres d\'occupants acceptés',
                                    'cdi_periode_essai_bloque' => 'Période d\'essai',
                                    'garantie_visale_requise' => 'Garantie Visale requise',
                                    'email_signature' => 'Signature des emails',
                                    'logo_societe' => 'Logo de la société',
                                    'footer_texte' => 'Texte du pied de page',
                                    'mail_from' => 'Adresse email d\'expédition',
                                    'mail_from_name' => 'Nom de l\'expéditeur',
                                    'smtp_host' => 'Serveur SMTP',
                                    'smtp_port' => 'Port SMTP',
                                    'smtp_secure' => 'Sécurité SMTP',
                                    'smtp_username' => 'Identifiant SMTP (email)',
                                    'smtp_password' => 'Mot de passe SMTP',
                                ];
                                echo $labels[$param['cle']] ?? $param['cle'];
                                ?>
                            </label>
                            <div class="param-description">
                                <?php echo htmlspecialchars($param['description']); ?>
                            </div>
                            
                            <?php if ($param['cle'] === 'garantie_visale_requise'): ?>
                                <!-- Special UI for garantie_visale_requise: Oui / Non / Je ne sais pas -->
                                <div class="d-flex gap-3 flex-wrap">
                                    <?php
                                    $gvVal  = $param['valeur'];
                                    $gvIsOui = ($gvVal === 'true' || $gvVal === 'oui');
                                    $gvIsNon = ($gvVal === 'non');
                                    $gvIsNsp = !$gvIsOui && !$gvIsNon;
                                    ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio"
                                               name="parametres[<?php echo $param['cle']; ?>]"
                                               id="gv_oui" value="oui" <?php echo $gvIsOui ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="gv_oui">
                                            <span class="badge bg-success me-1">Oui</span>
                                            Garantie Visale requise — rejet si "Non" ou "Je ne sais pas"
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio"
                                               name="parametres[<?php echo $param['cle']; ?>]"
                                               id="gv_non" value="non" <?php echo $gvIsNon ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="gv_non">
                                            <span class="badge bg-danger me-1">Non</span>
                                            Non requise — rejet automatique si le candidat répond "Non"
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio"
                                               name="parametres[<?php echo $param['cle']; ?>]"
                                               id="gv_nsp" value="je_ne_sais_pas" <?php echo $gvIsNsp ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="gv_nsp">
                                            <span class="badge bg-warning text-dark me-1">Je ne sais pas</span>
                                            Pas de prérequis strict
                                        </label>
                                    </div>
                                </div>
                            <?php elseif ($param['cle'] === 'statuts_pro_acceptes'): ?>
                                <!-- User-friendly checkboxes for professional statuses -->
                                <?php
                                $allStatuts = ['CDI', 'CDD', 'Indépendant', 'Autre'];
                                $currentStatuts = json_decode($param['valeur'], true) ?: [];
                                ?>
                                <div class="d-flex gap-3 flex-wrap">
                                    <?php foreach ($allStatuts as $statut): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox"
                                               name="parametres[statuts_pro_acceptes][]"
                                               id="statut_<?php echo htmlspecialchars($statut); ?>"
                                               value="<?php echo htmlspecialchars($statut); ?>"
                                               <?php echo in_array($statut, $currentStatuts) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="statut_<?php echo htmlspecialchars($statut); ?>">
                                            <?php echo htmlspecialchars($statut); ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted">Les candidats ayant un statut non coché seront refusés automatiquement.</small>
                            <?php elseif ($param['cle'] === 'nb_occupants_acceptes'): ?>
                                <!-- User-friendly checkboxes for number of occupants -->
                                <?php
                                $allOccupants = ['1', '2', '3', 'Autre'];
                                $currentOccupants = json_decode($param['valeur'], true) ?: [];
                                // Normalize: cast integers to string for comparison
                                $currentOccupants = array_map('strval', $currentOccupants);
                                ?>
                                <div class="d-flex gap-3 flex-wrap">
                                    <?php foreach ($allOccupants as $occ): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox"
                                               name="parametres[nb_occupants_acceptes][]"
                                               id="occ_<?php echo htmlspecialchars($occ); ?>"
                                               value="<?php echo htmlspecialchars($occ); ?>"
                                               <?php echo in_array($occ, $currentOccupants) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="occ_<?php echo htmlspecialchars($occ); ?>">
                                            <?php echo $occ === 'Autre' ? 'Autre (à préciser)' : $occ . ' occupant' . ($occ === '1' ? '' : 's'); ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted">Les candidats déclarant un nombre d'occupants non coché seront refusés automatiquement.</small>
                            <?php elseif ($param['cle'] === 'type_revenus_accepte'): ?>
                                <!-- Select for income type -->
                                <select name="parametres[<?php echo $param['cle']; ?>]" class="form-select">
                                    <?php
                                    $typesRevenus = ['Salaires', 'Indépendant', 'Retraite/rente', 'Autres'];
                                    foreach ($typesRevenus as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>"
                                        <?php echo $param['valeur'] === $type ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($param['type'] === 'boolean'): ?>
                                <select name="parametres[<?php echo $param['cle']; ?>]" class="form-select">
                                    <option value="true" <?php echo $param['valeur'] === 'true' ? 'selected' : ''; ?>>Oui</option>
                                    <option value="false" <?php echo $param['valeur'] === 'false' ? 'selected' : ''; ?>>Non</option>
                                </select>
                            <?php elseif ($param['type'] === 'integer'): ?>
                                <input type="number" 
                                       name="parametres[<?php echo $param['cle']; ?>]" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($param['valeur']); ?>"
                                       required>
                            <?php elseif ($param['type'] === 'json'): ?>
                                <textarea name="parametres[<?php echo $param['cle']; ?>]" 
                                          class="form-control" 
                                          rows="2"
                                          required><?php echo htmlspecialchars($param['valeur']); ?></textarea>
                                <small class="text-muted">Format JSON, ex: ["CDI", "CDD"]</small>
                            <?php elseif ($param['cle'] === 'email_signature'): ?>
                                <textarea name="parametres[<?php echo $param['cle']; ?>]" 
                                          class="form-control" 
                                          rows="6"
                                          required><?php echo htmlspecialchars($param['valeur']); ?></textarea>
                                <small class="text-muted">Code HTML pour la signature qui sera ajoutée à tous les emails</small>
                                <?php if (!empty($param['valeur'])): ?>
                                <div class="mt-2">
                                    <strong>Aperçu:</strong>
                                    <div class="border p-3 mt-2" style="background: #f8f9fa;">
                                        <iframe srcdoc="<?php echo htmlspecialchars($param['valeur']); ?>" 
                                                style="border: none; width: 100%; min-height: 150px;"
                                                sandbox="allow-same-origin"></iframe>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php elseif ($param['cle'] === 'logo_societe'): ?>
                                <input type="text" 
                                       name="parametres[<?php echo $param['cle']; ?>]" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($param['valeur']); ?>"
                                       placeholder="/assets/images/logo-my-invest-immobilier-carre.jpg"
                                       required>
                                <small class="text-muted">Chemin relatif vers le fichier logo (ex: /assets/images/logo.jpg ou /assets/images/logo.svg)</small>
                                <?php if (!empty($param['valeur'])): ?>
                                <div class="mt-2">
                                    <strong>Aperçu du logo:</strong>
                                    <div class="border p-3 mt-2" style="background: #f8f9fa;">
                                        <img src="<?php echo htmlspecialchars($param['valeur']); ?>" 
                                             alt="Logo société" 
                                             style="max-width: 150px; max-height: 150px;"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                        <div style="display:none; color: #dc3545;">
                                            <i class="bi bi-exclamation-triangle"></i> Logo non trouvé au chemin spécifié
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php elseif ($param['cle'] === 'smtp_password'): ?>
                                <input type="password"
                                       name="parametres[<?php echo $param['cle']; ?>]"
                                       class="form-control"
                                       value=""
                                       autocomplete="new-password"
                                       placeholder="<?php echo !empty($param['valeur']) ? '••••••••  (défini - laissez vide pour conserver)' : 'Entrez le mot de passe SMTP'; ?>">
                                <small class="text-muted">Mot de passe SMTP ou <em>App Password</em> (Gmail, Outlook…). Laissez vide pour conserver la valeur existante.</small>
                                <?php if (!empty($param['valeur'])): ?>
                                <div class="mt-1"><span class="badge bg-success"><i class="bi bi-check-circle"></i> Mot de passe SMTP défini</span></div>
                                <?php else: ?>
                                <div class="mt-1"><span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> Aucun mot de passe SMTP configuré</span></div>
                                <?php endif; ?>
                            <?php elseif ($param['cle'] === 'smtp_secure'): ?>
                                <select name="parametres[<?php echo $param['cle']; ?>]" class="form-select">
                                    <option value="tls" <?php echo $param['valeur'] === 'tls' ? 'selected' : ''; ?>>TLS (port 587 recommandé)</option>
                                    <option value="ssl" <?php echo $param['valeur'] === 'ssl' ? 'selected' : ''; ?>>SSL (port 465)</option>
                                </select>
                            <?php elseif ($param['cle'] === 'smtp_port'): ?>
                                <input type="number"
                                       name="parametres[<?php echo $param['cle']; ?>]"
                                       class="form-control"
                                       value="<?php echo htmlspecialchars($param['valeur']); ?>"
                                       min="1" max="65535"
                                       required>
                                <small class="text-muted">587 (TLS) ou 465 (SSL)</small>
                            <?php elseif ($param['cle'] === 'footer_texte'): ?>
                                <textarea name="parametres[<?php echo $param['cle']; ?>]"
                                          class="form-control"
                                          rows="2"><?php echo htmlspecialchars($param['valeur']); ?></textarea>
                                <small class="text-muted">Utilisez <code>{company}</code> pour insérer le nom de la société. Ex : <em>© {company} — Tous droits réservés</em></small>
                            <?php else: ?>
                                <input type="text" 
                                       name="parametres[<?php echo $param['cle']; ?>]" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($param['valeur']); ?>"
                                       required>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <div class="text-end mb-4">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-check-circle"></i> Enregistrer les paramètres
                </button>
            </div>

            <!-- ══ Signature Société ════════════════════════════════════════════ -->
            </form>

            <div class="param-card" id="signature-societe">
                <h5><i class="bi bi-pen"></i> Signature Électronique de la Société</h5>
                <p class="text-muted mb-3">
                    Téléchargez l'image de la signature qui sera automatiquement apposée sur les contrats et les états des lieux lors de la validation.
                </p>

                <?php if (!empty($signatureSocieteImage)): ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Signature actuelle :</label><br>
                    <img src="<?php echo htmlspecialchars('../uploads/' . $signatureSocieteImage); ?>"
                         alt="Signature société"
                         style="max-height:80px;border:1px solid #dee2e6;border-radius:6px;padding:6px;background:#fff;">
                    <form method="POST" action="parametres.php" class="d-inline ms-3">
                        <input type="hidden" name="action" value="delete_signature_societe">
                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                onclick="return confirm('Supprimer la signature ?');">
                            <i class="bi bi-trash"></i> Supprimer
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <p class="text-muted"><em>Aucune signature définie.</em></p>
                <?php endif; ?>

                <form method="POST" action="parametres.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_signature_societe">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="signature_societe_image" class="form-label">
                                <?php echo empty($signatureSocieteImage) ? 'Ajouter une image de signature' : 'Remplacer la signature'; ?>
                            </label>
                            <input type="file" class="form-control" id="signature_societe_image"
                                   name="signature_societe_image" accept="image/png,image/jpeg,image/jpg"
                                   <?php echo empty($signatureSocieteImage) ? 'required' : ''; ?>>
                            <small class="text-muted">PNG ou JPEG — max 2 MB. Fond transparent recommandé (PNG).</small>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="signature_societe_enabled"
                                       name="signature_societe_enabled" value="1"
                                       <?php echo $signatureSocieteEnabled ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="signature_societe_enabled">
                                    Activer l'apposition automatique sur les contrats et états des lieux
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-upload"></i> Enregistrer la signature
                        </button>
                    </div>
                </form>
            </div>

            <!-- Sécurité & CAPTCHA — lien vers la page dédiée -->
            <div class="param-card">
                <h5><i class="bi bi-shield-lock"></i> Sécurité &amp; CAPTCHA</h5>
                <p class="text-muted mb-3">
                    Protégez vos formulaires publics contre les spams et les robots grâce à Google reCAPTCHA.
                </p>
                <?php
                // Show current reCAPTCHA status
                try {
                    $stmtRc = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'recaptcha_enabled' LIMIT 1");
                    $stmtRc->execute();
                    $rcRow = $stmtRc->fetch(PDO::FETCH_ASSOC);
                    $rcActive = ($rcRow && ($rcRow['valeur'] === '1' || $rcRow['valeur'] === 'true'));
                } catch (Exception $e) {
                    $rcActive = false;
                }
                ?>
                <div class="d-flex align-items-center gap-3">
                    <span class="badge <?php echo $rcActive ? 'bg-success' : 'bg-secondary'; ?> fs-6">
                        <i class="bi bi-<?php echo $rcActive ? 'check-circle' : 'x-circle'; ?> me-1"></i>
                        reCAPTCHA <?php echo $rcActive ? 'Activé' : 'Désactivé'; ?>
                    </span>
                    <a href="recaptcha-configuration.php" class="btn btn-outline-primary">
                        <i class="bi bi-gear me-1"></i>Configurer le reCAPTCHA
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
