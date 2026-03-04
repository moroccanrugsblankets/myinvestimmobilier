<?php
/**
 * Page de déclaration d'anomalie — Portail locataire
 *
 * URL: /signalement/form.php
 *
 * Wizard en 3 étapes :
 *  1. Introduction + guide des réparations
 *  2. Conditions d'intervention + checklist
 *  3. Formulaire de déclaration (type, description, photos, disponibilités)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail-templates.php';

// ── Vérification de session ────────────────────────────────────────────────────
$locataire = $_SESSION['portal_locataire'] ?? null;

// Sécurité : rediriger vers index.php si le locataire n'est pas authentifié
if (!$locataire || empty($locataire['id']) || empty($locataire['contrat_id'])) {
    header('Location: /index.php');
    exit;
}

// ── État courant ──────────────────────────────────────────────────────────────
$errors = [];
$state  = $_SESSION['portal_state'] ?? 'anomalie1';

// Si l'état n'est pas dans le wizard anomalie, réinitialiser à l'étape 1
if (!in_array($state, ['anomalie1', 'anomalie2', 'anomalie3'])) {
    $state = 'anomalie1';
    $_SESSION['portal_state'] = 'anomalie1';
}

// ── Traitement des POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Anomalie étape 1 → étape 2 ────────────────────────────────────────────
    if ($action === 'next_anomalie1') {
        $_SESSION['portal_state'] = 'anomalie2';
        $state = 'anomalie2';

    // ── Anomalie étape 2 (confirmation) → étape 3 ─────────────────────────────
    } elseif ($action === 'confirm_anomalie2') {
        $checklistGuide      = !empty($_POST['checklist_guide']);
        $checklistConditions = !empty($_POST['checklist_conditions']);

        if (!$checklistGuide) {
            $errors[] = "Veuillez confirmer avoir pris connaissance du guide des réparations locatives.";
        }
        if (!$checklistConditions) {
            $errors[] = "Veuillez confirmer avoir pris connaissance des conditions d'intervention.";
        }

        if (empty($errors)) {
            $_SESSION['portal_checklist'] = true;
            $_SESSION['portal_state']     = 'anomalie3';
            $state = 'anomalie3';
        } else {
            $state = 'anomalie2';
        }

    // ── Anomalie étape 3 : soumission du formulaire ────────────────────────────
    } elseif ($action === 'submit_anomalie3') {
        if (empty($_SESSION['portal_checklist'])) {
            $errors[] = "Vous devez confirmer la checklist à l'étape précédente.";
            $state = 'anomalie2';
        } else {
            $typeProbleme  = trim($_POST['type_probleme'] ?? '');
            $description   = trim($_POST['description']  ?? '');
            $priorite      = in_array($_POST['priorite'] ?? '', ['urgent', 'normal']) ? $_POST['priorite'] : 'normal';
            $disponibilites = trim($_POST['disponibilites'] ?? '');

            $typesValides = ['Plomberie', 'Électricité', 'Serrurerie', 'Chauffage', 'Électroménager', 'Autre'];
            if (empty($typeProbleme) || !in_array($typeProbleme, $typesValides)) {
                $errors[] = 'Veuillez sélectionner un type de problème.';
            }
            if (empty($description)) {
                $errors[] = 'La description est obligatoire.';
            }

            // Validation des photos/vidéos (obligatoires)
            $uploadedPhotos = [];
            if (empty($_FILES['photos']['name'][0])) {
                $errors[] = 'Au moins une photo est obligatoire.';
            } else {
                $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'video/mp4', 'video/quicktime', 'video/x-msvideo'];
                $maxSize      = 10 * 1024 * 1024;
                $uploadDir    = __DIR__ . '/../uploads/signalements/';

                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                    $errors[] = "Erreur serveur : impossible de créer le répertoire d'upload.";
                } elseif (!is_writable($uploadDir)) {
                    $errors[] = "Erreur serveur : le répertoire d'upload n'est pas accessible en écriture.";
                }

                if (empty($errors)) {
                    foreach ($_FILES['photos']['tmp_name'] as $i => $tmpName) {
                        if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
                        if ($_FILES['photos']['size'][$i] > $maxSize) {
                            $errors[] = 'Le fichier « ' . htmlspecialchars($_FILES['photos']['name'][$i]) . ' » dépasse 10 Mo.';
                            continue;
                        }
                        $mime = mime_content_type($tmpName);
                        if (!in_array($mime, $allowedMimes)) {
                            $errors[] = 'Format non supporté pour « ' . htmlspecialchars($_FILES['photos']['name'][$i]) . ' » (JPG, PNG, WebP ou vidéo MP4/MOV).';
                            continue;
                        }
                        $ext     = pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION);
                        $newName = 'sig_' . bin2hex(random_bytes(12)) . '.' . strtolower($ext);
                        $uploadedPhotos[] = [
                            'tmp'      => $tmpName,
                            'filename' => $newName,
                            'original' => $_FILES['photos']['name'][$i],
                            'mime'     => $mime,
                            'size'     => $_FILES['photos']['size'][$i],
                        ];
                    }
                    if (empty($uploadedPhotos) && empty($errors)) {
                        $errors[] = 'Au moins un fichier valide est obligatoire.';
                    }
                }
            }

            if (empty($errors)) {
                try {
                    $pdo->beginTransaction();

                    $reference = 'SIG-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                    $titre     = $typeProbleme;

                    try {
                        $insertStmt = $pdo->prepare("
                            INSERT INTO signalements
                                (reference, contrat_id, logement_id, locataire_id, titre, description,
                                 priorite, type_probleme, checklist_confirmee, statut, disponibilites)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'nouveau', ?)
                        ");
                        $insertStmt->execute([
                            $reference,
                            $locataire['contrat_id'],
                            $locataire['logement_id'],
                            $locataire['id'],
                            $titre,
                            $description,
                            $priorite,
                            $typeProbleme,
                            $disponibilites ?: null,
                        ]);
                    } catch (Exception $e) {
                        if (strpos($e->getMessage(), 'type_probleme') === false && strpos($e->getMessage(), 'Unknown column') === false) {
                            throw $e;
                        }
                        $insertStmt = $pdo->prepare("
                            INSERT INTO signalements
                                (reference, contrat_id, logement_id, locataire_id, titre, description,
                                 priorite, checklist_confirmee, statut)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'nouveau')
                        ");
                        $insertStmt->execute([
                            $reference,
                            $locataire['contrat_id'],
                            $locataire['logement_id'],
                            $locataire['id'],
                            $titre,
                            $description,
                            $priorite,
                        ]);
                    }
                    $newSignalementId = $pdo->lastInsertId();

                    $savedFilePaths = [];
                    foreach ($uploadedPhotos as $photo) {
                        $dest = __DIR__ . '/../uploads/signalements/' . $photo['filename'];
                        if (move_uploaded_file($photo['tmp'], $dest)) {
                            $savedFilePaths[] = ['path' => $dest, 'name' => $photo['original'], 'mime' => $photo['mime']];
                            $pdo->prepare("
                                INSERT INTO signalements_photos (signalement_id, filename, original_name, mime_type, taille)
                                VALUES (?, ?, ?, ?, ?)
                            ")->execute([$newSignalementId, $photo['filename'], $photo['original'], $photo['mime'], $photo['size']]);
                        }
                    }

                    $pdo->prepare("
                        INSERT INTO signalements_actions (signalement_id, type_action, description, acteur, ip_address)
                        VALUES (?, 'creation', ?, ?, ?)
                    ")->execute([
                        $newSignalementId,
                        'Signalement créé par le locataire (portail)',
                        $locataire['prenom'] . ' ' . $locataire['nom'],
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    ]);

                    $pdo->commit();

                    // Emails de notification
                    $siteUrl = rtrim($config['SITE_URL'] ?? '', '/');

                    // Build photos/videos HTML (links) for email
                    $photosHtml = '';
                    if (!empty($uploadedPhotos)) {
                        $photosHtml = '<p style="margin-top:15px;"><strong>Photos / Vidéos jointes :</strong></p><ul>';
                        foreach ($uploadedPhotos as $up) {
                            $mediaUrl = $siteUrl . '/uploads/signalements/' . rawurlencode($up['filename']);
                            $photosHtml .= '<li><a href="' . htmlspecialchars($mediaUrl) . '">' . htmlspecialchars($up['original']) . '</a></li>';
                        }
                        $photosHtml .= '</ul>';
                    }

                    // Disponibilités HTML
                    $disponibilitesHtml = '';
                    if (!empty($disponibilites)) {
                        $disponibilitesHtml = '<p style="margin: 5px 0;"><strong>Disponibilités du locataire :</strong> ' . nl2br(htmlspecialchars($disponibilites)) . '</p>';
                    }

                    // Build array of attachments for email (uploaded files)
                    $attachments = [];
                    foreach ($savedFilePaths as $fp) {
                        $attachments[] = ['path' => $fp['path'], 'name' => $fp['name']];
                    }
                    $attachmentsArg = !empty($attachments) ? $attachments : null;

                    // Email au locataire (avec info + PJ)
                    $locataireEmail = strtolower(trim($locataire['email'] ?? ''));
                    if (!empty($locataireEmail)) {
                        sendTemplatedEmail('nouveau_signalement_locataire', $locataireEmail, [
                            'prenom'             => $locataire['prenom'],
                            'nom'                => $locataire['nom'],
                            'reference'          => $reference,
                            'titre'              => $titre,
                            'priorite'           => ucfirst($priorite),
                            'adresse'            => $locataire['adresse'],
                            'date'               => date('d/m/Y à H:i'),
                            'company'            => $config['COMPANY_NAME'] ?? '',
                            'description'        => $description,
                            'disponibilites_html' => $disponibilitesHtml,
                            'photos_html'        => $photosHtml,
                        ], $attachmentsArg, false, false, ['contexte' => "signalement_confirmation;sig_id=$newSignalementId"]);
                    }

                    // Email aux admins (depuis la table administrateurs + config)
                    $adminEmailVars = [
                        'reference'           => $reference,
                        'titre'               => $titre,
                        'priorite'            => ucfirst($priorite),
                        'adresse'             => $locataire['adresse'],
                        'locataire'           => $locataire['prenom'] . ' ' . $locataire['nom'],
                        'telephone'           => $locataire['telephone'] ?? '—',
                        'description'         => $description,
                        'date'                => date('d/m/Y à H:i'),
                        'lien_admin'          => $siteUrl . '/admin-v2/signalement-detail.php?id=' . $newSignalementId,
                        'disponibilites_html' => $disponibilitesHtml,
                        'photos_html'         => $photosHtml,
                    ];

                    // Collect all admin emails (DB + config)
                    $allAdminEmails = [];
                    try {
                        $stmtAdm = $pdo->query("SELECT email FROM administrateurs WHERE actif = 1 AND email IS NOT NULL AND email != ''");
                        $allAdminEmails = $stmtAdm->fetchAll(PDO::FETCH_COLUMN);
                    } catch (Exception $e) {}
                    $configAdminEmail = getAdminEmail();
                    if (!empty($configAdminEmail) && !in_array(strtolower($configAdminEmail), array_map('strtolower', $allAdminEmails))) {
                        array_unshift($allAdminEmails, $configAdminEmail);
                    }

                    foreach (array_unique($allAdminEmails) as $aEmail) {
                        if (!empty($aEmail) && filter_var($aEmail, FILTER_VALIDATE_EMAIL)) {
                            sendTemplatedEmail('nouveau_signalement_admin', $aEmail, $adminEmailVars,
                                $attachmentsArg, false, false,
                                ['contexte' => "signalement_admin_notification;sig_id=$newSignalementId"]);
                        }
                    }

                    // Email au service technique
                    $stEmail = getServiceTechniqueEmail();
                    if ($stEmail && !in_array(strtolower($stEmail), array_map('strtolower', $allAdminEmails)) && strtolower($stEmail) !== strtolower($locataireEmail)) {
                        sendTemplatedEmail('nouveau_signalement_service_technique', $stEmail, [
                            'reference'           => $reference,
                            'titre'               => $titre,
                            'priorite'            => ucfirst($priorite),
                            'adresse'             => $locataire['adresse'],
                            'locataire'           => $locataire['prenom'] . ' ' . $locataire['nom'],
                            'telephone'           => $locataire['telephone'] ?? '—',
                            'description'         => $description,
                            'date'                => date('d/m/Y à H:i'),
                            'disponibilites_html' => $disponibilitesHtml,
                            'photos_html'         => $photosHtml,
                        ], $attachmentsArg, false, false, ['contexte' => "signalement_st_notification;sig_id=$newSignalementId"]);
                    }

                    // Réinitialiser l'état du wizard
                    unset($_SESSION['portal_state'], $_SESSION['portal_checklist']);

                    header('Location: /signalement/confirmation.php?ref=' . urlencode($newSignalementId));
                    exit;

                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log('signalement/form.php portal signalement error: ' . $e->getMessage());
                    $errors[] = "Une erreur est survenue lors de l'enregistrement. Veuillez réessayer.";
                    $state    = 'anomalie3';
                }
            } else {
                $state = 'anomalie3';
            }
        }

    // ── Déconnexion ────────────────────────────────────────────────────────────
    } elseif ($action === 'logout') {
        unset($_SESSION['portal_state'], $_SESSION['portal_locataire'], $_SESSION['portal_email'], $_SESSION['portal_checklist']);
        header('Location: /index.php');
        exit;

    // ── Retour vers le choix ───────────────────────────────────────────────────
    } elseif ($action === 'back_to_choice') {
        $_SESSION['portal_state'] = 'choice';
        header('Location: /index.php');
        exit;

    // ── Retour arrière wizard ──────────────────────────────────────────────────
    } elseif ($action === 'back_to_anomalie1') {
        $_SESSION['portal_state'] = 'anomalie1';
        $state = 'anomalie1';
    } elseif ($action === 'back_to_anomalie2') {
        $_SESSION['portal_state'] = 'anomalie2';
        $state = 'anomalie2';
    }
}

// Contenu du guide des réparations (depuis les paramètres)
$guideContenu = getParameter('guide_reparations_contenu', '');

$companyName  = $config['COMPANY_NAME']  ?? 'My Invest Immobilier';
$companyEmail = $config['COMPANY_EMAIL'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Déclaration d'anomalie — <?php echo htmlspecialchars($companyName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f0f4f8; }
        .portal-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .portal-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: #fff;
            padding: 32px 36px 26px;
        }
        .portal-body { padding: 36px; }
        .step-indicator {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
        }
        .step-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #dee2e6;
        }
        .step-dot.active { background: #3498db; }
        .step-dot.done   { background: #27ae60; }
        .section-divider { border-top: 2px solid #e9ecef; margin: 24px 0; }
        .section-title {
            display: flex;
            align-items: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 12px;
        }
        .section-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px; height: 28px;
            border-radius: 50%;
            background: #3498db;
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            margin-right: 10px;
            flex-shrink: 0;
        }
        .guide-content h1,.guide-content h2,.guide-content h3 { margin-top: 1.1rem; }
        .guide-content h2 { font-size: 1.4rem; }
        .guide-content h3 { font-size: 1.2rem; }
        .guide-content h4 { font-size: 1.1rem; }
        .bareme-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 16px;
        }
        .type-radio label {
            cursor: pointer;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 10px 14px;
            transition: border-color .15s, background .15s;
            display: block;
        }
        .type-radio input[type="radio"]:checked + label {
            border-color: #3498db;
            background: #eaf4fd;
        }
        .type-radio input[type="radio"] { display: none; }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-md-10 col-12">

            <div class="portal-card">

                <?php /* ── En-tête ── */ ?>
                <div class="portal-header">
                    <h2 class="mb-1 fs-4">
                        <i class="bi bi-tools me-2"></i>Déclaration d'anomalie
                    </h2>
                    <p class="mb-0 opacity-75" style="font-size:.92rem;">
                        <i class="bi bi-house me-1"></i><?php echo htmlspecialchars($locataire['adresse']); ?>
                        <?php if (!empty($locataire['logement_ref'])): ?>
                            &nbsp;—&nbsp;<span class="font-monospace"><?php echo htmlspecialchars($locataire['logement_ref']); ?></span>
                        <?php endif; ?>
                        &nbsp;—&nbsp;<i class="bi bi-person me-1"></i><?php echo htmlspecialchars($locataire['prenom'] . ' ' . $locataire['nom']); ?>
                    </p>
                </div>

                <div class="portal-body">

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger mb-4">
                            <i class="bi bi-exclamation-circle-fill me-2"></i>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($errors as $e): ?>
                                    <li><?php echo htmlspecialchars($e); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php /* ════════════════
                     *  ANOMALIE — ÉTAPE 1
                     * ════════════════ */ ?>
                    <?php if ($state === 'anomalie1'): ?>

                        <div class="step-indicator">
                            <div class="step-dot active"></div>
                            <div class="step-dot"></div>
                            <div class="step-dot"></div>
                        </div>

                        <div class="section-title"><span class="section-number">1</span> Introduction</div>
                        <div class="alert alert-light border mb-3">
                            <p class="mb-1">Cher locataire,</p>
                            <p class="mb-0">Nous sommes navrés de l'anomalie constatée dans votre logement.<br>
                            Afin de traiter votre demande efficacement et dans les meilleurs délais, merci de prendre connaissance des informations ci-dessous avant de poursuivre.</p>
                        </div>

                        <div class="section-divider"></div>

                        <div class="section-title"><span class="section-number">2</span> Vérification préalable</div>
                        <p class="text-muted mb-2">
                            Certaines situations relèvent de l'entretien courant du logement <strong>(à la charge du locataire)</strong>,
                            d'autres nécessitent une intervention du propriétaire.
                        </p>
                        <p class="text-muted mb-2">Avant toute déclaration, merci de vérifier si le problème concerne par exemple :</p>
                        <ul class="text-muted mb-0">
                            <li>remplacement d'ampoule</li>
                            <li>débouchage simple (évier, siphon)</li>
                            <li>nettoyage ou réglage</li>
                            <li>joints d'usage</li>
                            <li>piles, télécommandes</li>
                            <li>entretien courant des équipements</li>
                        </ul>

                        <div class="section-divider"></div>

                        <div class="section-title"><span class="section-number">3</span> Guide des réparations locatives</div>
                        <?php if (!empty($guideContenu)): ?>
                            <div class="guide-content border rounded p-3 bg-light" style="max-height:400px;overflow-y:auto;">
                                <?php echo $guideContenu; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>Le guide des réparations locatives n'a pas encore été configuré par l'administrateur.
                            </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between mt-4">
                            <form method="POST">
                                <input type="hidden" name="action" value="back_to_choice">
                                <button type="submit" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left me-1"></i>Retour
                                </button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="action" value="next_anomalie1">
                                <button type="submit" class="btn btn-primary">
                                    Suivant <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </form>
                        </div>

                    <?php /* ════════════════
                     *  ANOMALIE — ÉTAPE 2
                     * ════════════════ */ ?>
                    <?php elseif ($state === 'anomalie2'): ?>

                        <div class="step-indicator">
                            <div class="step-dot done"></div>
                            <div class="step-dot active"></div>
                            <div class="step-dot"></div>
                        </div>

                        <div class="section-title"><span class="section-number">1</span> Conditions d'intervention</div>
                        <p class="fw-semibold mb-2">Intervention technique</p>
                        <p class="text-muted mb-3">
                            Si l'intervention ne relève pas de la responsabilité du propriétaire, une facturation distincte
                            sera établie selon le barème ci-dessous.
                        </p>
                        <div class="bareme-box mb-0">
                            <p class="fw-semibold mb-2">Barème applicable :</p>
                            <ul class="mb-0">
                                <li>Forfait déplacement + diagnostic (incluant jusqu'à 1 heure sur place) : <strong>80 € TTC</strong></li>
                                <li>Heure supplémentaire entamée : <strong>60 € TTC</strong></li>
                                <li>Fournitures et pièces : <strong>facturées au coût réel</strong></li>
                            </ul>
                        </div>

                        <div class="section-divider"></div>

                        <form method="POST" novalidate>
                            <input type="hidden" name="action" value="confirm_anomalie2">

                            <div class="section-title"><span class="section-number">2</span> Confirmation</div>

                            <div class="mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="checklist_guide"
                                           name="checklist_guide" value="1" required>
                                    <label class="form-check-label" for="checklist_guide">
                                        J'ai lu et pris connaissance du guide des réparations locatives
                                    </label>
                                </div>
                            </div>
                            <div class="mb-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="checklist_conditions"
                                           name="checklist_conditions" value="1" required>
                                    <label class="form-check-label" for="checklist_conditions">
                                        J'ai pris connaissance des conditions d'intervention et du barème applicable
                                    </label>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between">
                                <button type="button" class="btn btn-outline-secondary"
                                        onclick="document.getElementById('back-anomalie1-form').submit()">
                                    <i class="bi bi-arrow-left me-1"></i>Retour
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    Suivant <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </div>
                        </form>
                        <form id="back-anomalie1-form" method="POST" class="d-none">
                            <input type="hidden" name="action" value="back_to_anomalie1">
                        </form>

                    <?php /* ════════════════
                     *  ANOMALIE — ÉTAPE 3
                     * ════════════════ */ ?>
                    <?php elseif ($state === 'anomalie3'): ?>

                        <div class="step-indicator">
                            <div class="step-dot done"></div>
                            <div class="step-dot done"></div>
                            <div class="step-dot active"></div>
                        </div>

                        <div class="section-title"><span class="section-number">1</span> Formulaire de déclaration</div>

                        <form method="POST" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="action" value="submit_anomalie3">

                            <div class="mb-4">
                                <label class="form-label fw-semibold">Type de problème <span class="text-danger">*</span></label>
                                <div class="row g-2">
                                    <?php
                                    $types     = ['Plomberie', 'Électricité', 'Serrurerie', 'Chauffage', 'Électroménager', 'Autre'];
                                    $typeIcons = ['Plomberie' => '🛁', 'Électricité' => '⚡', 'Serrurerie' => '🔑', 'Chauffage' => '🔥', 'Électroménager' => '🏠', 'Autre' => '❓'];
                                    $selectedType = $_POST['type_probleme'] ?? '';
                                    foreach ($types as $type):
                                        $typeId = 'type_' . strtolower(preg_replace('/[^a-z]/i', '_', $type));
                                    ?>
                                    <div class="col-6 col-md-4 type-radio">
                                        <input type="radio" name="type_probleme" id="<?php echo $typeId; ?>"
                                               value="<?php echo htmlspecialchars($type); ?>"
                                               <?php echo $selectedType === $type ? 'checked' : ''; ?>>
                                        <label for="<?php echo $typeId; ?>">
                                            <?php echo $typeIcons[$type]; ?> <?php echo htmlspecialchars($type); ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold">Niveau d'urgence <span class="text-danger">*</span></label>
                                <div class="d-flex flex-column gap-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="priorite" id="prio_urgent"
                                               value="urgent" <?php echo (($_POST['priorite'] ?? '') === 'urgent') ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="prio_urgent">
                                            <strong>Urgent</strong>
                                            <small class="text-muted ms-1">— dégât des eaux, impossibilité d'accéder au logement…</small>
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="priorite" id="prio_normal"
                                               value="normal" <?php echo (($_POST['priorite'] ?? 'normal') === 'normal') ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="prio_normal">
                                            <strong>Normal</strong>
                                            <small class="text-muted ms-1">— peut attendre quelques jours</small>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold" for="description">
                                    Description détaillée <span class="text-danger">*</span>
                                </label>
                                <textarea class="form-control" id="description" name="description"
                                          rows="5" required
                                          placeholder="Décrivez précisément l'anomalie, quand elle a commencé, ce que vous avez constaté..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold" for="photos">
                                    Photos / vidéos <span class="text-danger">*</span>
                                </label>
                                <input type="file" class="form-control" id="photos" name="photos[]"
                                       accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime" multiple>
                                <div class="form-text">JPG, PNG, WebP ou vidéo — 10 Mo max par fichier.</div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold" for="disponibilites">
                                    Vos disponibilités <small class="text-muted fw-normal">(optionnel)</small>
                                </label>
                                <textarea class="form-control" id="disponibilites" name="disponibilites" rows="3"
                                          placeholder="Ex : demain 8h–16h / après-demain 14h–18h / dans 3 jours toute la journée..."><?php echo htmlspecialchars($_POST['disponibilites'] ?? ''); ?></textarea>
                                <div class="form-text">Indiquez vos disponibilités sur les 3 prochains jours pour faciliter l'intervention.</div>
                            </div>

                            <div class="d-flex justify-content-between">
                                <button type="button" class="btn btn-outline-secondary"
                                        onclick="document.getElementById('back-anomalie2-form').submit()">
                                    <i class="bi bi-arrow-left me-1"></i>Retour
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-send me-2"></i>Envoyer ma demande
                                </button>
                            </div>
                        </form>
                        <form id="back-anomalie2-form" method="POST" class="d-none">
                            <input type="hidden" name="action" value="back_to_anomalie2">
                        </form>

                    <?php endif; ?>

                </div>
            </div>

            <p class="text-center mt-3 text-muted small">
                <?php echo htmlspecialchars($companyName); ?>
                <?php if (!empty($companyEmail)): ?>
                    &nbsp;—&nbsp;<a href="mailto:<?php echo htmlspecialchars($companyEmail); ?>" class="text-muted"><?php echo htmlspecialchars($companyEmail); ?></a>
                <?php endif; ?>
            </p>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
