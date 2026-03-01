<?php
/**
 * Formulaire de signalement d'anomalie — Portail locataire
 *
 * URL: /signalement/form.php?token=XXXXX
 *
 * Le locataire accède à ce formulaire via un lien sécurisé (token unique).
 * Ce formulaire permet d'ouvrir un ticket de signalement avec :
 *   - Titre, description, priorité, photos
 *   - Confirmation de la check-list responsabilité
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';

// Validation du token
if (empty($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    die('Lien invalide ou expiré. Veuillez contacter votre propriétaire.');
}

// Récupérer le locataire via son token
try {
    $stmt = $pdo->prepare("
        SELECT loc.*, c.id as contrat_id, l.id as logement_id,
               l.adresse, l.reference as logement_ref
        FROM locataires loc
        INNER JOIN contrats c ON loc.contrat_id = c.id
        INNER JOIN logements l ON c.logement_id = l.id
        WHERE loc.token_signalement = ?
          AND c.statut = 'valide'
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $locataire = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('signalement/form.php DB error: ' . $e->getMessage());
    http_response_code(500);
    die('Erreur interne. Veuillez réessayer plus tard.');
}

if (!$locataire) {
    http_response_code(404);
    die('Lien invalide ou contrat inactif. Veuillez contacter votre propriétaire.');
}

// ── Traitement du formulaire ─────────────────────────────────────────────────
$errors = [];
$success = false;
$newSignalementId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titre = trim($_POST['titre'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priorite = in_array($_POST['priorite'] ?? '', ['urgent', 'normal']) ? $_POST['priorite'] : 'normal';
    $checklistConfirmee = !empty($_POST['checklist_confirmee']);

    if (empty($titre)) {
        $errors[] = 'Le titre est obligatoire.';
    } elseif (mb_strlen($titre) > 255) {
        $errors[] = 'Le titre ne peut pas dépasser 255 caractères.';
    }

    if (empty($description)) {
        $errors[] = 'La description est obligatoire.';
    }

    if (!$checklistConfirmee) {
        $errors[] = 'Vous devez confirmer avoir vérifié la liste des responsabilités avant de soumettre votre signalement.';
    }

    // Validation des photos (obligatoires selon le cahier des charges)
    $uploadedPhotos = [];
    if (empty($_FILES['photos']['name'][0])) {
        $errors[] = 'Au moins une photo est obligatoire.';
    } else {
        $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        $maxSize = 10 * 1024 * 1024; // 10 Mo par photo
        $uploadDir = __DIR__ . '/../uploads/signalements/';

        // S'assurer que le répertoire existe et est accessible en écriture
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            $errors[] = 'Erreur serveur : impossible de créer le répertoire d\'upload. Contactez l\'administrateur.';
        } elseif (!is_writable($uploadDir)) {
            $errors[] = 'Erreur serveur : le répertoire d\'upload n\'est pas accessible en écriture. Contactez l\'administrateur.';
        }

        if (empty($errors)) {
            foreach ($_FILES['photos']['tmp_name'] as $i => $tmpName) {
                if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($_FILES['photos']['size'][$i] > $maxSize) {
                    $errors[] = 'La photo « ' . htmlspecialchars($_FILES['photos']['name'][$i]) . ' » dépasse la taille maximale (10 Mo).';
                    continue;
                }
                $mime = mime_content_type($tmpName);
                if (!in_array($mime, $allowedMimes)) {
                    $errors[] = 'Format de photo non supporté pour « ' . htmlspecialchars($_FILES['photos']['name'][$i]) . ' » (JPG, PNG ou WebP uniquement).';
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
                $errors[] = 'Au moins une photo valide est obligatoire.';
            }
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Générer la référence unique
            $reference = 'SIG-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

            // Insérer le signalement
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
            $newSignalementId = $pdo->lastInsertId();

            // Déplacer et enregistrer les photos
            foreach ($uploadedPhotos as $photo) {
                $dest = __DIR__ . '/../uploads/signalements/' . $photo['filename'];
                if (move_uploaded_file($photo['tmp'], $dest)) {
                    $pdo->prepare("
                        INSERT INTO signalements_photos (signalement_id, filename, original_name, mime_type, taille)
                        VALUES (?, ?, ?, ?, ?)
                    ")->execute([$newSignalementId, $photo['filename'], $photo['original'], $photo['mime'], $photo['size']]);
                }
            }

            // Enregistrer l'action dans la timeline
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
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('signalement/form.php insert error: ' . $e->getMessage());
            $errors[] = 'Une erreur est survenue lors de l\'enregistrement. Veuillez réessayer.';
        }
    }
}

if ($success) {
    header('Location: confirmation.php?ref=' . urlencode($newSignalementId ?? '') . '&token=' . urlencode($token));
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signaler une anomalie — <?php echo htmlspecialchars($config['COMPANY_NAME']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f0f4f8; }
        .form-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        .header-brand {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: #fff;
            border-radius: 14px 14px 0 0;
            padding: 28px 30px 22px;
        }
        .checklist-item { padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
        .checklist-item:last-child { border-bottom: none; }
        .badge-urgent { background: #dc3545; }
        .badge-normal { background: #6c757d; }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-md-10">

            <div class="form-card mb-5">
                <!-- En-tête -->
                <div class="header-brand">
                    <h2 class="mb-1"><i class="bi bi-exclamation-triangle me-2"></i>Signaler une anomalie</h2>
                    <p class="mb-0 opacity-75">
                        <?php echo htmlspecialchars($locataire['adresse']); ?>
                        &nbsp;—&nbsp;
                        <?php echo htmlspecialchars($locataire['prenom'] . ' ' . $locataire['nom']); ?>
                    </p>
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

                    <form method="POST" enctype="multipart/form-data" novalidate>

                        <!-- Titre -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="titre">
                                Titre du signalement <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control form-control-lg" id="titre" name="titre"
                                   maxlength="255" required
                                   placeholder="Ex : Fuite d'eau sous l'évier, Chauffage en panne..."
                                   value="<?php echo htmlspecialchars($_POST['titre'] ?? ''); ?>">
                        </div>

                        <!-- Priorité -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                Priorité <span class="text-danger">*</span>
                            </label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="priorite" id="prio_normal"
                                           value="normal" <?php echo (($_POST['priorite'] ?? 'normal') === 'normal') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="prio_normal">
                                        <span class="badge badge-normal">Normal</span>
                                        <small class="text-muted ms-1">— Peut attendre quelques jours</small>
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="priorite" id="prio_urgent"
                                           value="urgent" <?php echo (($_POST['priorite'] ?? '') === 'urgent') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="prio_urgent">
                                        <span class="badge badge-urgent text-white">Urgent</span>
                                        <small class="text-muted ms-1">— Risque pour la sécurité ou habitation</small>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="description">
                                Description détaillée <span class="text-danger">*</span>
                            </label>
                            <textarea class="form-control" id="description" name="description"
                                      rows="5" required
                                      placeholder="Décrivez précisément l'anomalie, quand elle a commencé, ce que vous avez constaté..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                        </div>

                        <!-- Photos -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="photos">
                                Photos <span class="text-danger">*</span>
                            </label>
                            <input type="file" class="form-control" id="photos" name="photos[]"
                                   accept="image/jpeg,image/png,image/webp" multiple>
                            <div class="form-text">JPG, PNG ou WebP — 10 Mo max par photo. Ajoutez plusieurs photos si nécessaire.</div>
                        </div>

                        <!-- Check-list responsabilité -->
                        <div class="mb-4">
                            <div class="card border-warning">
                                <div class="card-header bg-warning bg-opacity-10 fw-semibold">
                                    <i class="bi bi-clipboard-check me-2 text-warning"></i>
                                    Vérification de la responsabilité — À lire attentivement
                                </div>
                                <div class="card-body">
                                    <p class="text-muted small mb-3">
                                        Avant de valider votre signalement, veuillez vérifier si la réparation est à votre charge
                                        ou à celle du propriétaire.
                                    </p>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <div class="p-3 rounded" style="background:#fff3cd;">
                                                <strong><i class="bi bi-person me-1"></i>À la charge du locataire :</strong>
                                                <div class="checklist-item">Remplacement des ampoules</div>
                                                <div class="checklist-item">Entretien courant (joints, filtres, débouchage)</div>
                                                <div class="checklist-item">Petites réparations (poignées, serrures usées)</div>
                                                <div class="checklist-item">Raccords peinture ou petits accrocs</div>
                                                <div class="checklist-item">Entretien de la chaudière (annuel)</div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="p-3 rounded" style="background:#d4edda;">
                                                <strong><i class="bi bi-house me-1"></i>À la charge du propriétaire :</strong>
                                                <div class="checklist-item">Toiture, façade, gros œuvre</div>
                                                <div class="checklist-item">Chauffage collectif, chaudière défectueuse</div>
                                                <div class="checklist-item">Canalisations encastrées, fuites structurelles</div>
                                                <div class="checklist-item">Volets, portes et fenêtres défectueux</div>
                                                <div class="checklist-item">Équipements vétustes fournis avec le logement</div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="checklist_confirmee"
                                               name="checklist_confirmee" value="1"
                                               <?php echo !empty($_POST['checklist_confirmee']) ? 'checked' : ''; ?> required>
                                        <label class="form-check-label fw-semibold" for="checklist_confirmee">
                                            J'ai vérifié la liste ci-dessus et je confirme que cette réparation
                                            ne relève pas de ma responsabilité de locataire.
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Note légale -->
                        <div class="alert alert-light border small text-muted mb-4">
                            <i class="bi bi-info-circle me-1"></i>
                            Votre signalement sera transmis à l'équipe de gestion. La date de dépôt sera
                            enregistrée automatiquement et servira de référence en cas de litige.
                            Toutes les actions sur ce dossier seront horodatées.
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="bi bi-send me-2"></i>Soumettre le signalement
                        </button>
                    </form>
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
