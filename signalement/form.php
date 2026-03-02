<?php
/**
 * Formulaire de signalement d'anomalie — Portail locataire
 *
 * URL: /signalement/form.php
 *
 * Le locataire s'identifie par son adresse email (toujours transformée en minuscule).
 * Ce formulaire permet d'ouvrir un ticket de signalement avec :
 *   - Email (identification), Titre, Description, Priorité, Photos
 *   - Confirmation de la check-list responsabilité
 *   - Date automatique de signalement
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail-templates.php';

$errors = [];
$success = false;
$newSignalementId = null;
$locataire = null;

// Étape 1 : identification par email
$emailSaisi = strtolower(trim($_POST['email'] ?? $_GET['email'] ?? ''));
$etapeFormulaire = false;

if (!empty($emailSaisi)) {
    if (!filter_var($emailSaisi, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Adresse email invalide.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT loc.*, c.id as contrat_id, l.id as logement_id,
                       l.adresse, l.reference as logement_ref
                FROM locataires loc
                INNER JOIN contrats c ON loc.contrat_id = c.id
                INNER JOIN logements l ON c.logement_id = l.id
                WHERE LOWER(loc.email) = ?
                  AND c.statut = 'valide'
                LIMIT 1
            ");
            $stmt->execute([$emailSaisi]);
            $locataire = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('signalement/form.php DB error: ' . $e->getMessage());
            $errors[] = 'Erreur interne. Veuillez réessayer plus tard.';
        }

        if (empty($errors) && !$locataire) {
            $errors[] = 'Aucun contrat actif trouvé pour cette adresse email. Vérifiez l\'adresse saisie ou contactez votre gestionnaire.';
        }

        if (empty($errors) && $locataire) {
            $etapeFormulaire = true;
        }
    }
}

// Étape 2 : traitement du signalement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $etapeFormulaire && !empty($_POST['action_signaler'])) {
    $typeProbleme = trim($_POST['type_probleme'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priorite = in_array($_POST['priorite'] ?? '', ['urgent', 'normal']) ? $_POST['priorite'] : 'normal';
    $checklistGuide = !empty($_POST['checklist_guide']);
    $checklistConditions = !empty($_POST['checklist_conditions']);
    $checklistConfirmee = $checklistGuide && $checklistConditions;

    $typesValides = ['Plomberie', 'Électricité', 'Serrurerie', 'Chauffage', 'Électroménager', 'Autre'];
    if (empty($typeProbleme) || !in_array($typeProbleme, $typesValides)) {
        $errors[] = 'Veuillez sélectionner un type de problème.';
    }

    if (empty($description)) {
        $errors[] = 'La description est obligatoire.';
    }

    if (!$checklistGuide) {
        $errors[] = 'Veuillez confirmer avoir consulté le guide des réparations locatives.';
    }
    if (!$checklistConditions) {
        $errors[] = 'Veuillez confirmer avoir pris connaissance des conditions d\'intervention.';
    }

    // Validation des photos/vidéos (obligatoires)
    $uploadedPhotos = [];
    if (empty($_FILES['photos']['name'][0])) {
        $errors[] = 'Au moins une photo est obligatoire.';
    } else {
        $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'video/mp4', 'video/quicktime', 'video/x-msvideo'];
        $maxSize = 10 * 1024 * 1024; // 10 Mo par fichier
        $uploadDir = __DIR__ . '/../uploads/signalements/';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            $errors[] = 'Erreur serveur : impossible de créer le répertoire d\'upload. Contactez l\'administrateur.';
        } elseif (!is_writable($uploadDir)) {
            $errors[] = 'Erreur serveur : le répertoire d\'upload n\'est pas accessible en écriture. Contactez l\'administrateur.';
        }

        if (empty($errors)) {
            foreach ($_FILES['photos']['tmp_name'] as $i => $tmpName) {
                if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($_FILES['photos']['size'][$i] > $maxSize) {
                    $errors[] = 'Le fichier « ' . htmlspecialchars($_FILES['photos']['name'][$i]) . ' » dépasse la taille maximale (10 Mo).';
                    continue;
                }
                $mime = mime_content_type($tmpName);
                if (!in_array($mime, $allowedMimes)) {
                    $errors[] = 'Format non supporté pour « ' . htmlspecialchars($_FILES['photos']['name'][$i]) . ' » (JPG, PNG, WebP ou vidéo MP4/MOV).';
                    continue;
                }
                $ext = pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION);
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
            $titre = $typeProbleme; // Titre auto-généré depuis le type de problème

            // Tenter l'insertion avec type_probleme (disponible après migration 085)
            try {
                $insertStmt = $pdo->prepare("
                    INSERT INTO signalements
                        (reference, contrat_id, logement_id, locataire_id, titre, description,
                         priorite, type_probleme, checklist_confirmee, statut)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'nouveau')
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
                    $checklistConfirmee ? 1 : 0,
                ]);
            } catch (Exception $e) {
                // Fallback sans type_probleme si colonne absente (migration 085 non encore appliquée)
                if (strpos($e->getMessage(), 'type_probleme') === false && strpos($e->getMessage(), 'Unknown column') === false) {
                    throw $e; // Re-lancer si l'erreur n'est pas liée à la colonne manquante
                }
                error_log('signalement/form.php: type_probleme column not found, falling back to old INSERT: ' . $e->getMessage());
                $insertStmt = $pdo->prepare("
                    INSERT INTO signalements
                        (reference, contrat_id, logement_id, locataire_id, titre, description,
                         priorite, checklist_confirmee, statut)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'nouveau')
                ");
                $insertStmt->execute([
                    $reference,
                    $locataire['contrat_id'],
                    $locataire['logement_id'],
                    $locataire['id'],
                    $titre,
                    $description,
                    $priorite,
                    $checklistConfirmee ? 1 : 0,
                ]);
            }
            $newSignalementId = $pdo->lastInsertId();

            foreach ($uploadedPhotos as $photo) {
                $dest = __DIR__ . '/../uploads/signalements/' . $photo['filename'];
                if (move_uploaded_file($photo['tmp'], $dest)) {
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
                'Signalement créé par le locataire',
                $locataire['prenom'] . ' ' . $locataire['nom'],
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);

            $pdo->commit();
            $success = true;

            // Envoi des emails de notification après commit réussi
            $siteUrl = rtrim($config['SITE_URL'] ?? '', '/');

            // 1. Email de confirmation au locataire
            $locataireEmail = strtolower(trim($locataire['email'] ?? ''));
            if (!empty($locataireEmail)) {
                $vars = [
                    'prenom'      => $locataire['prenom'],
                    'nom'         => $locataire['nom'],
                    'reference'   => $reference,
                    'titre'       => $titre,
                    'priorite'    => ucfirst($priorite),
                    'adresse'     => $locataire['adresse'],
                    'date'        => date('d/m/Y à H:i'),
                    'company'     => $config['COMPANY_NAME'] ?? '',
                ];
                $sent = sendTemplatedEmail('nouveau_signalement_locataire', $locataireEmail, $vars, null, false, false,
                    ['contexte' => "signalement_confirmation;sig_id=$newSignalementId"]);
                if (!$sent) {
                    error_log("signalement/form.php: Impossible d'envoyer l'email de confirmation au locataire ($locataireEmail)");
                }
            }

            // 2. Email de notification à l'admin
            $adminEmail = getAdminEmail();
            if (!empty($adminEmail)) {
                $vars = [
                    'reference'   => $reference,
                    'titre'       => $titre,
                    'priorite'    => ucfirst($priorite),
                    'adresse'     => $locataire['adresse'],
                    'locataire'   => $locataire['prenom'] . ' ' . $locataire['nom'],
                    'description' => $description,
                    'date'        => date('d/m/Y à H:i'),
                    'lien_admin'  => $siteUrl . '/admin-v2/signalement-detail.php?id=' . $newSignalementId,
                ];
                sendTemplatedEmail('nouveau_signalement_admin', $adminEmail, $vars, null, true, false,
                    ['contexte' => "signalement_admin_notification;sig_id=$newSignalementId"]);
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('signalement/form.php insert error: ' . $e->getMessage());
            $errors[] = 'Une erreur est survenue lors de l\'enregistrement. Veuillez réessayer.';
        }
    }
}

if ($success) {
    header('Location: confirmation.php?ref=' . urlencode($newSignalementId ?? ''));
    exit;
}

// Paramètre configurable : lien vers le guide des réparations locatives
// Valider l'URL pour éviter les schémas dangereux (javascript:, data:, etc.)
$guideLienRaw = getParameter('guide_reparations_lien', '');
$guideLien = (!empty($guideLienRaw) && preg_match('#^https?://#i', $guideLienRaw)) ? $guideLienRaw : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Déclaration d'anomalie — <?php echo htmlspecialchars($config['COMPANY_NAME']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f0f4f8; }
        .form-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
        .header-brand { background: linear-gradient(135deg, #2c3e50, #3498db); color: #fff; border-radius: 14px 14px 0 0; padding: 28px 30px 22px; }
        .section-divider { border-top: 2px solid #e9ecef; margin: 28px 0; }
        .section-label { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #6c757d; margin-bottom: 10px; }
        .section-number { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: #3498db; color: #fff; font-weight: 700; font-size: 13px; margin-right: 8px; flex-shrink: 0; }
        .section-title { display: flex; align-items: center; font-size: 1.05rem; font-weight: 700; color: #2c3e50; margin-bottom: 14px; }
        .bareme-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 16px; }
        .bareme-box li { padding: 4px 0; }
        .type-radio label { cursor: pointer; border: 2px solid #dee2e6; border-radius: 8px; padding: 10px 14px; transition: border-color .15s, background .15s; display: block; }
        .type-radio input[type="radio"]:checked + label { border-color: #3498db; background: #eaf4fd; }
        .type-radio input[type="radio"] { display: none; }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-md-10">

            <div class="form-card mb-5">
                <div class="header-brand">
                    <h2 class="mb-1">🛠 Déclaration d'anomalie ou demande d'intervention</h2>
                    <?php if ($locataire): ?>
                    <p class="mb-0 opacity-75">
                        <i class="bi bi-house me-1"></i><?php echo htmlspecialchars($locataire['adresse']); ?>
                        <?php if (!empty($locataire['logement_ref'])): ?>
                            &nbsp;—&nbsp;<span class="font-monospace"><?php echo htmlspecialchars($locataire['logement_ref']); ?></span>
                        <?php endif; ?>
                        &nbsp;—&nbsp;
                        <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($locataire['prenom'] . ' ' . $locataire['nom']); ?>
                    </p>
                    <?php else: ?>
                    <p class="mb-0 opacity-75">Portail locataire — <?php echo htmlspecialchars($config['COMPANY_NAME']); ?></p>
                    <?php endif; ?>
                </div>

                <div class="p-4 p-md-5">

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-circle-fill me-2"></i>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($errors as $e): ?>
                                    <li><?php echo htmlspecialchars($e); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!$etapeFormulaire): ?>
                    <!-- Étape 1 : identification par email -->
                    <p class="text-muted mb-4">Saisissez votre adresse email pour accéder au formulaire de déclaration.</p>
                    <form method="POST" novalidate>
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="email">
                                Votre adresse email <span class="text-danger">*</span>
                            </label>
                            <input type="email" class="form-control form-control-lg" id="email" name="email"
                                   required autofocus placeholder="votre@email.fr"
                                   value="<?php echo htmlspecialchars($emailSaisi); ?>">
                            <div class="form-text">L'adresse email associée à votre contrat de location.</div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="bi bi-arrow-right me-2"></i>Continuer
                        </button>
                    </form>

                    <?php else: ?>
                    <!-- Étape 2 : formulaire de déclaration -->
                    <form method="POST" enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="email" value="<?php echo htmlspecialchars($emailSaisi); ?>">
                        <input type="hidden" name="action_signaler" value="1">

                        <!-- ─── Section 1 : Introduction ──────────────────── -->
                        <div class="section-title">
                            <span class="section-number">1</span> Introduction
                        </div>
                        <p class="text-muted mb-0">
                            Nous sommes désolés que vous rencontriez une difficulté dans le logement.<br>
                            Afin de traiter votre demande efficacement et dans les meilleurs délais, merci de prendre
                            connaissance des informations ci-dessous avant validation.
                        </p>

                        <div class="section-divider"></div>

                        <!-- ─── Section 2 : Vérification préalable ─────────── -->
                        <div class="section-title">
                            <span class="section-number">2</span> Vérification préalable
                        </div>
                        <p class="text-muted mb-2">
                            Certaines situations relèvent de l'entretien courant du logement <strong>(à la charge du locataire)</strong>,
                            d'autres nécessitent une intervention du propriétaire.
                        </p>
                        <p class="text-muted mb-2">Avant toute déclaration, merci de vérifier si le problème concerne par exemple :</p>
                        <ul class="text-muted mb-3">
                            <li>remplacement d'ampoule</li>
                            <li>débouchage simple (évier, siphon)</li>
                            <li>nettoyage ou réglage</li>
                            <li>joints d'usage</li>
                            <li>piles, télécommandes</li>
                            <li>entretien courant des équipements</li>
                        </ul>
                        <?php if (!empty($guideLien)): ?>
                        <p class="mb-0">
                            👉 <strong><a href="<?php echo htmlspecialchars($guideLien); ?>" target="_blank" rel="noopener">
                                Consultez le guide des réparations locatives ici
                            </a></strong>
                        </p>
                        <?php else: ?>
                        <p class="text-muted small mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Le lien vers le guide des réparations locatives peut être configuré par l'administrateur.
                        </p>
                        <?php endif; ?>

                        <div class="section-divider"></div>

                        <!-- ─── Section 3 : Conditions d'intervention ──────── -->
                        <div class="section-title">
                            <span class="section-number">3</span> Conditions d'intervention
                        </div>
                        <p class="fw-semibold mb-2">Intervention technique</p>
                        <p class="text-muted mb-3">
                            Si l'intervention ne relève pas de la responsabilité du propriétaire, une facturation distincte
                            sera établie selon le barème ci-dessous.
                        </p>
                        <div class="bareme-box">
                            <p class="fw-semibold mb-2">Barème applicable :</p>
                            <ul class="mb-0">
                                <li>Forfait déplacement + diagnostic (incluant jusqu'à 1 heure sur place) : <strong>80 € TTC</strong></li>
                                <li>Heure supplémentaire entamée : <strong>60 € TTC</strong></li>
                                <li>Fournitures et pièces : <strong>facturées au coût réel</strong></li>
                            </ul>
                        </div>

                        <div class="section-divider"></div>

                        <!-- ─── Section 4 : Confirmation ───────────────────── -->
                        <div class="section-title">
                            <span class="section-number">4</span> Confirmation
                        </div>
                        <div class="mb-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="checklist_guide"
                                       name="checklist_guide" value="1"
                                       <?php echo !empty($_POST['checklist_guide']) ? 'checked' : ''; ?> required>
                                <label class="form-check-label" for="checklist_guide">
                                    J'ai consulté le guide des réparations locatives<?php echo !empty($guideLien) ? ' (<a href="' . htmlspecialchars($guideLien) . '" target="_blank" rel="noopener">voir le guide</a>)' : ''; ?>
                                </label>
                            </div>
                        </div>
                        <div class="mb-0">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="checklist_conditions"
                                       name="checklist_conditions" value="1"
                                       <?php echo !empty($_POST['checklist_conditions']) ? 'checked' : ''; ?> required>
                                <label class="form-check-label" for="checklist_conditions">
                                    J'ai pris connaissance des conditions d'intervention et du barème applicable
                                </label>
                            </div>
                        </div>

                        <div class="section-divider"></div>

                        <!-- ─── Section 5 : Formulaire de déclaration ──────── -->
                        <div class="section-title">
                            <span class="section-number">5</span> Formulaire de déclaration
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Type de problème <span class="text-danger">*</span></label>
                            <div class="row g-2">
                                <?php
                                $types = ['Plomberie', 'Électricité', 'Serrurerie', 'Chauffage', 'Électroménager', 'Autre'];
                                $typeIcons = ['Plomberie' => '🔧', 'Électricité' => '⚡', 'Serrurerie' => '🔑', 'Chauffage' => '🔥', 'Électroménager' => '🏠', 'Autre' => '❓'];
                                $selectedType = $_POST['type_probleme'] ?? '';
                                foreach ($types as $type):
                                    $typeId = 'type_' . strtolower(preg_replace('/[^a-z]/i', '_', $type)); ?>
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
                            <div class="form-text">JPG, PNG, WebP ou vidéo — 10 Mo max par fichier. Ajoutez plusieurs fichiers si nécessaire.</div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="bi bi-send me-2"></i>Envoyer ma demande
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <p class="text-center text-muted small">
                <?php echo htmlspecialchars($config['COMPANY_NAME']); ?>
                &nbsp;—&nbsp;
                <a href="mailto:<?php echo htmlspecialchars($config['COMPANY_EMAIL']); ?>" class="text-muted">
                    <?php echo htmlspecialchars($config['COMPANY_EMAIL']); ?>
                </a>
            </p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
