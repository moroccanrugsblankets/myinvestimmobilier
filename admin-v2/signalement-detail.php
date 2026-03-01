<?php
/**
 * Détail et gestion d'un signalement d'anomalie — Interface admin
 * My Invest Immobilier
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/mail-templates.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: signalements.php');
    exit;
}

// Charger le signalement
$stmt = $pdo->prepare("
    SELECT sig.*,
           l.adresse, l.reference as logement_ref, l.loyer, l.charges,
           c.reference_unique as contrat_ref,
           CONCAT(loc.prenom, ' ', loc.nom) as locataire_nom,
           loc.email as locataire_email, loc.telephone as locataire_telephone,
           loc.token_signalement
    FROM signalements sig
    INNER JOIN logements l ON sig.logement_id = l.id
    INNER JOIN contrats c ON sig.contrat_id = c.id
    LEFT JOIN locataires loc ON sig.locataire_id = loc.id
    WHERE sig.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$sig = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sig) {
    header('Location: signalements.php');
    exit;
}

// Photos
$photos = $pdo->prepare("SELECT * FROM signalements_photos WHERE signalement_id = ? ORDER BY uploaded_at");
$photos->execute([$id]);
$photos = $photos->fetchAll(PDO::FETCH_ASSOC);

// Timeline
$actions = $pdo->prepare("SELECT * FROM signalements_actions WHERE signalement_id = ? ORDER BY created_at ASC");
$actions->execute([$id]);
$actions = $actions->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$successMsg = '';
$isClos = ($sig['statut'] === 'clos');

// ── Traitement des formulaires ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token CSRF invalide. Veuillez recharger la page.';
    } else {
        $action = $_POST['action'];
        $adminName = $_SESSION['admin_nom'] ?? 'Administrateur';

        // ── Changement de statut ─────────────────────────────────────────────
        if ($action === 'change_statut' && !$isClos) {
            $newStatut = $_POST['statut'] ?? '';
            $validStatuts = ['nouveau', 'en_cours', 'en_attente', 'resolu', 'clos'];
            if (!in_array($newStatut, $validStatuts)) {
                $errors[] = 'Statut invalide.';
            } else {
                $oldStatut = $sig['statut'];
                $extraFields = [];
                $extraParams = [];

                if ($newStatut === 'clos') {
                    $extraFields[] = 'date_cloture = NOW()';
                } elseif ($newStatut === 'resolu') {
                    $extraFields[] = 'date_resolution = NOW()';
                } elseif ($newStatut === 'en_cours' && empty($sig['date_intervention'])) {
                    $extraFields[] = 'date_intervention = NOW()';
                }

                $setClause = 'statut = ?' . (empty($extraFields) ? '' : ', ' . implode(', ', $extraFields));
                $pdo->prepare("UPDATE signalements SET $setClause, updated_at = NOW() WHERE id = ?")
                    ->execute(array_merge([$newStatut], $extraParams, [$id]));

                $pdo->prepare("
                    INSERT INTO signalements_actions (signalement_id, type_action, description, acteur, ancienne_valeur, nouvelle_valeur, ip_address)
                    VALUES (?, 'statut_change', ?, ?, ?, ?, ?)
                ")->execute([$id,
                    "Statut mis à jour : $oldStatut → $newStatut",
                    $adminName, $oldStatut, $newStatut,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                // Rechargement
                header("Location: signalement-detail.php?id=$id&success=statut");
                exit;
            }
        }

        // ── Modification responsabilité ──────────────────────────────────────
        if ($action === 'set_responsabilite' && !$isClos) {
            $responsabilite = $_POST['responsabilite'] ?? '';
            if (!in_array($responsabilite, ['locataire', 'proprietaire', 'non_determine'])) {
                $errors[] = 'Responsabilité invalide.';
            } else {
                $old = $sig['responsabilite'];
                $pdo->prepare("UPDATE signalements SET responsabilite = ?, responsabilite_confirmee_admin = 1, updated_at = NOW() WHERE id = ?")
                    ->execute([$responsabilite, $id]);

                $pdo->prepare("
                    INSERT INTO signalements_actions (signalement_id, type_action, description, acteur, ancienne_valeur, nouvelle_valeur, ip_address)
                    VALUES (?, 'responsabilite', ?, ?, ?, ?, ?)
                ")->execute([$id,
                    "Responsabilité définie : $old → $responsabilite",
                    $adminName, $old, $responsabilite,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                header("Location: signalement-detail.php?id=$id&success=responsabilite");
                exit;
            }
        }

        // ── Attribution à un collaborateur ───────────────────────────────────
        if ($action === 'attribuer' && !$isClos) {
            $collabNom = trim($_POST['collaborateur_nom'] ?? '');
            $collabEmail = trim($_POST['collaborateur_email'] ?? '');
            $collabTel = trim($_POST['collaborateur_telephone'] ?? '');
            $modeNotif = $_POST['mode_notification'] ?? 'email';
            // Utiliser l'ID collaborateur sélectionné dans le dropdown (si disponible)
            $collabId = !empty($_POST['collab_select_id']) ? (int)$_POST['collab_select_id'] : null;

            if (empty($collabNom)) {
                $errors[] = 'Le nom du collaborateur est obligatoire.';
            } else {
                // Tenter la mise à jour avec collaborateur_id (disponible après migration 082)
                try {
                    $pdo->prepare("
                        UPDATE signalements
                        SET collaborateur_nom = ?, collaborateur_email = ?, collaborateur_telephone = ?,
                            mode_notification_collab = ?,
                            collaborateur_id = ?,
                            date_attribution = COALESCE(date_attribution, NOW()),
                            statut = CASE WHEN statut = 'nouveau' THEN 'en_cours' ELSE statut END,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$collabNom, $collabEmail, $collabTel, $modeNotif, $collabId, $id]);
                } catch (Exception $e) {
                    // Fallback sans collaborateur_id si colonne absente
                    $pdo->prepare("
                        UPDATE signalements
                        SET collaborateur_nom = ?, collaborateur_email = ?, collaborateur_telephone = ?,
                            mode_notification_collab = ?,
                            date_attribution = COALESCE(date_attribution, NOW()),
                            statut = CASE WHEN statut = 'nouveau' THEN 'en_cours' ELSE statut END,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$collabNom, $collabEmail, $collabTel, $modeNotif, $id]);
                }

                $pdo->prepare("
                    INSERT INTO signalements_actions (signalement_id, type_action, description, acteur, nouvelle_valeur, ip_address)
                    VALUES (?, 'attribution', ?, ?, ?, ?)
                ")->execute([$id,
                    "Attribué à $collabNom (mode : $modeNotif)",
                    $adminName,
                    json_encode(['nom' => $collabNom, 'email' => $collabEmail, 'tel' => $collabTel, 'mode' => $modeNotif]),
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                // Envoyer email au collaborateur si mode email ou les_deux
                if (in_array($modeNotif, ['email', 'les_deux']) && !empty($collabEmail)) {
                    $siteUrl = rtrim($config['SITE_URL'], '/');
                    $signalementUrl = $siteUrl . '/admin-v2/signalement-detail.php?id=' . $id;
                    $subject = "[Signalement {$sig['priorite']}] " . htmlspecialchars($sig['titre']) . " — " . htmlspecialchars($sig['adresse']);
                    $body = "<p>Bonjour " . htmlspecialchars($collabNom) . ",</p>"
                        . "<p>Un signalement vous a été attribué :</p>"
                        . "<ul>"
                        . "<li><strong>Référence :</strong> " . htmlspecialchars($sig['reference']) . "</li>"
                        . "<li><strong>Titre :</strong> " . htmlspecialchars($sig['titre']) . "</li>"
                        . "<li><strong>Priorité :</strong> " . htmlspecialchars($sig['priorite']) . "</li>"
                        . "<li><strong>Adresse :</strong> " . htmlspecialchars($sig['adresse']) . "</li>"
                        . "<li><strong>Description :</strong> " . nl2br(htmlspecialchars($sig['description'])) . "</li>"
                        . "</ul>"
                        . "<p><a href=\"" . htmlspecialchars($signalementUrl) . "\">Voir la mission interne</a></p>";

                    $sent = sendEmail($collabEmail, $subject, $body, null, true, false, null, null, false,
                        ['contexte' => "signalement_attribution;sig_id=$id"]);
                    if (!$sent) {
                        $errors[] = 'Avertissement : l\'email au collaborateur n\'a pas pu être envoyé.';
                    }
                }

                if (empty($errors)) {
                    header("Location: signalement-detail.php?id=$id&success=attribution");
                    exit;
                }
            }
        }

        // ── Ajout de complément (uniquement si clos) ─────────────────────────
        if ($action === 'add_complement' && $isClos) {
            $complement = trim($_POST['complement'] ?? '');
            if (empty($complement)) {
                $errors[] = 'Le complément ne peut pas être vide.';
            } else {
                $pdo->prepare("UPDATE signalements SET complement = CONCAT(COALESCE(complement,''), ?), updated_at = NOW() WHERE id = ?")
                    ->execute(["\n\n[" . date('d/m/Y H:i') . " — $adminName] " . $complement, $id]);

                $pdo->prepare("
                    INSERT INTO signalements_actions (signalement_id, type_action, description, acteur, nouvelle_valeur, ip_address)
                    VALUES (?, 'complement', 'Complément ajouté au dossier clos', ?, ?, ?)
                ")->execute([$id, $adminName, $complement, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);

                header("Location: signalement-detail.php?id=$id&success=complement");
                exit;
            }
        }
    }
}

// Recharger après modifications
$stmt->execute([$id]);
$sig = $stmt->fetch(PDO::FETCH_ASSOC);
$isClos = ($sig['statut'] === 'clos');

// Recharger timeline
$actionsStmt = $pdo->prepare("SELECT * FROM signalements_actions WHERE signalement_id = ? ORDER BY created_at ASC");
$actionsStmt->execute([$id]);
$actions = $actionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Charger la liste des collaborateurs actifs
try {
    $collabListStmt = $pdo->query("SELECT id, nom, metier, email, telephone FROM collaborateurs WHERE actif = 1 ORDER BY nom ASC");
    $collabList = $collabListStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $collabList = []; // Table absente si migration non appliquée
}

$csrfToken = generateCsrfToken();

$statutLabels = [
    'nouveau'    => ['label' => 'Nouveau',     'class' => 'bg-primary'],
    'en_cours'   => ['label' => 'En cours',    'class' => 'bg-warning text-dark'],
    'en_attente' => ['label' => 'En attente',  'class' => 'bg-info text-dark'],
    'resolu'     => ['label' => 'Résolu',      'class' => 'bg-success'],
    'clos'       => ['label' => 'Clos',        'class' => 'bg-secondary'],
];

$actionIcons = [
    'creation'        => 'bi-flag-fill text-primary',
    'statut_change'   => 'bi-arrow-repeat text-warning',
    'attribution'     => 'bi-person-check text-info',
    'responsabilite'  => 'bi-shield-check text-success',
    'complement'      => 'bi-chat-text text-secondary',
    'cloture'         => 'bi-lock-fill text-secondary',
];

$successParam = $_GET['success'] ?? '';
if ($successParam) {
    $successMessages = [
        'statut'         => 'Statut mis à jour avec succès.',
        'responsabilite' => 'Responsabilité confirmée avec succès.',
        'attribution'    => 'Signalement attribué avec succès.',
        'complement'     => 'Complément ajouté avec succès.',
    ];
    $successMsg = $successMessages[$successParam] ?? 'Modification enregistrée.';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signalement <?php echo htmlspecialchars($sig['reference']); ?> — Admin My Invest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .section-card { background: #fff; border-radius: 10px; padding: 22px; margin-bottom: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.07); }
        .timeline { position: relative; padding-left: 30px; }
        .timeline::before { content: ''; position: absolute; left: 10px; top: 0; bottom: 0; width: 2px; background: #dee2e6; }
        .timeline-item { position: relative; margin-bottom: 18px; }
        .timeline-item::before { content: ''; position: absolute; left: -24px; top: 5px; width: 12px; height: 12px; border-radius: 50%; background: #6c757d; border: 2px solid #fff; box-shadow: 0 0 0 2px #dee2e6; }
        .timeline-item.action-creation::before { background: #0d6efd; }
        .timeline-item.action-attribution::before { background: #0dcaf0; }
        .timeline-item.action-statut_change::before { background: #ffc107; }
        .timeline-item.action-responsabilite::before { background: #198754; }
        .timeline-item.action-cloture::before { background: #6c757d; }
        .photo-thumb { width: 120px; height: 90px; object-fit: cover; border-radius: 6px; border: 1px solid #dee2e6; cursor: pointer; transition: transform 0.15s; }
        .photo-thumb:hover, .photo-thumb:focus { transform: scale(1.05); outline: 2px solid #0d6efd; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="container-fluid mt-4">

        <!-- En-tête -->
        <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                    <h1 class="mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($sig['titre']); ?>
                    </h1>
                    <?php if ($sig['priorite'] === 'urgent'): ?>
                        <span class="badge bg-danger fs-6"><i class="bi bi-lightning-fill me-1"></i>Urgent</span>
                    <?php endif; ?>
                    <span class="badge <?php echo $statutLabels[$sig['statut']]['class']; ?> fs-6">
                        <?php echo $statutLabels[$sig['statut']]['label']; ?>
                    </span>
                </div>
                <p class="text-muted mb-0">
                    <span class="font-monospace"><?php echo htmlspecialchars($sig['reference']); ?></span>
                    &nbsp;—&nbsp;<?php echo htmlspecialchars($sig['adresse']); ?>
                    &nbsp;—&nbsp;signalé le <?php echo date('d/m/Y à H:i', strtotime($sig['date_signalement'])); ?>
                </p>
            </div>
            <a href="signalements.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Retour
            </a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $e): ?>
                    <div><i class="bi bi-exclamation-circle me-1"></i><?php echo htmlspecialchars($e); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($successMsg): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($successMsg); ?>
            </div>
        <?php endif; ?>
        <?php if ($isClos): ?>
            <div class="alert alert-secondary">
                <i class="bi bi-lock-fill me-2"></i>
                Ce signalement est <strong>clos</strong>. Il n'est plus modifiable. Seul un complément peut être ajouté.
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Colonne gauche : détails + gestion -->
            <div class="col-lg-8">

                <!-- Informations générales -->
                <div class="section-card">
                    <h5 class="mb-3"><i class="bi bi-info-circle me-2"></i>Détails du signalement</h5>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Locataire</dt>
                        <dd class="col-sm-8">
                            <?php echo htmlspecialchars($sig['locataire_nom'] ?? '—'); ?>
                            <?php if (!empty($sig['locataire_email'])): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($sig['locataire_email']); ?></small>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-4">Logement</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars($sig['adresse']); ?></dd>

                        <dt class="col-sm-4">Contrat</dt>
                        <dd class="col-sm-8">
                            <a href="contrat-detail.php?id=<?php echo $sig['contrat_id']; ?>">
                                <?php echo htmlspecialchars($sig['contrat_ref']); ?>
                            </a>
                        </dd>

                        <dt class="col-sm-4">Description</dt>
                        <dd class="col-sm-8">
                            <div class="p-3 bg-light rounded" style="white-space:pre-wrap;"><?php
                                echo htmlspecialchars($sig['description']);
                            ?></div>
                        </dd>

                        <dt class="col-sm-4">Responsabilité</dt>
                        <dd class="col-sm-8">
                            <?php
                            $respLabels = ['locataire' => 'Locataire', 'proprietaire' => 'Propriétaire', 'non_determine' => 'Non déterminée'];
                            $respClasses = ['locataire' => 'bg-danger', 'proprietaire' => 'bg-success', 'non_determine' => 'bg-secondary'];
                            ?>
                            <span class="badge <?php echo $respClasses[$sig['responsabilite']] ?? 'bg-secondary'; ?>">
                                <?php echo $respLabels[$sig['responsabilite']] ?? $sig['responsabilite']; ?>
                            </span>
                            <?php if ($sig['responsabilite_confirmee_admin']): ?>
                                <small class="text-success ms-2"><i class="bi bi-check-circle"></i> Confirmé par l'admin</small>
                            <?php endif; ?>
                            <?php if ($sig['checklist_confirmee']): ?>
                                <br><small class="text-muted"><i class="bi bi-clipboard-check me-1"></i>Checklist confirmée par le locataire</small>
                            <?php endif; ?>
                        </dd>

                        <?php if ($sig['collaborateur_nom']): ?>
                        <dt class="col-sm-4">Collaborateur</dt>
                        <dd class="col-sm-8">
                            <?php echo htmlspecialchars($sig['collaborateur_nom']); ?>
                            <?php if (!empty($sig['collaborateur_email'])): ?>
                                — <a href="mailto:<?php echo htmlspecialchars($sig['collaborateur_email']); ?>">
                                    <?php echo htmlspecialchars($sig['collaborateur_email']); ?>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($sig['collaborateur_telephone'])): ?>
                                — <?php echo htmlspecialchars($sig['collaborateur_telephone']); ?>
                            <?php endif; ?>
                        </dd>
                        <?php endif; ?>

                        <?php if ($sig['complement']): ?>
                        <dt class="col-sm-4">Complément</dt>
                        <dd class="col-sm-8">
                            <div class="p-3 bg-light rounded" style="white-space:pre-wrap;"><?php echo htmlspecialchars($sig['complement']); ?></div>
                        </dd>
                        <?php endif; ?>
                    </dl>
                </div>

                <!-- Photos -->
                <?php if (!empty($photos)): ?>
                <div class="section-card">
                    <h5 class="mb-3"><i class="bi bi-camera me-2"></i>Photos (<?php echo count($photos); ?>)</h5>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($photos as $photo): ?>
                            <?php
                            $photoUrl = rtrim($config['SITE_URL'], '/') . '/uploads/signalements/' . urlencode($photo['filename']);
                            ?>
                            <a href="<?php echo htmlspecialchars($photoUrl); ?>" target="_blank" title="<?php echo htmlspecialchars($photo['original_name']); ?>">
                                <img src="<?php echo htmlspecialchars($photoUrl); ?>"
                                     alt="<?php echo htmlspecialchars($photo['original_name']); ?>"
                                     class="photo-thumb">
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Timeline -->
                <div class="section-card">
                    <h5 class="mb-3"><i class="bi bi-clock-history me-2"></i>Timeline</h5>
                    <?php if (empty($actions)): ?>
                        <p class="text-muted">Aucune action enregistrée.</p>
                    <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($actions as $action): ?>
                        <div class="timeline-item action-<?php echo htmlspecialchars($action['type_action']); ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <i class="bi <?php echo $actionIcons[$action['type_action']] ?? 'bi-dot text-secondary'; ?> me-2"></i>
                                    <strong><?php echo htmlspecialchars($action['description']); ?></strong>
                                    <?php if (!empty($action['acteur'])): ?>
                                        <small class="text-muted ms-2">par <?php echo htmlspecialchars($action['acteur']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted text-nowrap ms-3">
                                    <?php echo date('d/m/Y H:i', strtotime($action['created_at'])); ?>
                                </small>
                            </div>
                            <?php if (!empty($action['ancienne_valeur']) || !empty($action['nouvelle_valeur'])): ?>
                                <div class="small text-muted mt-1 ms-4">
                                    <?php if (!empty($action['ancienne_valeur'])): ?>
                                        <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($action['ancienne_valeur']); ?></span>
                                        <i class="bi bi-arrow-right mx-1"></i>
                                    <?php endif; ?>
                                    <?php if (!empty($action['nouvelle_valeur'])): ?>
                                        <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($action['nouvelle_valeur']); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Complément post-clôture -->
                <?php if ($isClos): ?>
                <div class="section-card border border-secondary">
                    <h5 class="mb-3"><i class="bi bi-chat-text me-2"></i>Ajouter un complément</h5>
                    <p class="text-muted small">Le dossier est clos mais vous pouvez ajouter un complément d'information.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="add_complement">
                        <div class="mb-3">
                            <textarea class="form-control" name="complement" rows="3" required
                                      placeholder="Complément d'information..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-secondary">
                            <i class="bi bi-plus-circle me-1"></i>Ajouter le complément
                        </button>
                    </form>
                </div>
                <?php endif; ?>

            </div>

            <!-- Colonne droite : actions admin -->
            <div class="col-lg-4">

                <!-- Dates clés (timeline rapide) -->
                <div class="section-card">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-calendar3 me-2"></i>Dates clés</h6>
                    <ul class="list-unstyled mb-0 small">
                        <li class="mb-2">
                            <i class="bi bi-flag text-primary me-2"></i>
                            <strong>Signalement :</strong><br>
                            <span class="ms-4"><?php echo date('d/m/Y H:i', strtotime($sig['date_signalement'])); ?></span>
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-person-check text-info me-2"></i>
                            <strong>Attribution :</strong><br>
                            <span class="ms-4"><?php echo $sig['date_attribution'] ? date('d/m/Y H:i', strtotime($sig['date_attribution'])) : '—'; ?></span>
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-tools text-warning me-2"></i>
                            <strong>Intervention :</strong><br>
                            <span class="ms-4"><?php echo $sig['date_intervention'] ? date('d/m/Y H:i', strtotime($sig['date_intervention'])) : '—'; ?></span>
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-check-circle text-success me-2"></i>
                            <strong>Résolution :</strong><br>
                            <span class="ms-4"><?php echo $sig['date_resolution'] ? date('d/m/Y H:i', strtotime($sig['date_resolution'])) : '—'; ?></span>
                        </li>
                        <li>
                            <i class="bi bi-lock text-secondary me-2"></i>
                            <strong>Clôture :</strong><br>
                            <span class="ms-4"><?php echo $sig['date_cloture'] ? date('d/m/Y H:i', strtotime($sig['date_cloture'])) : '—'; ?></span>
                        </li>
                    </ul>
                </div>

                <?php if (!$isClos): ?>

                <!-- Changer le statut -->
                <div class="section-card">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-arrow-repeat me-2"></i>Changer le statut</h6>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="change_statut">
                        <div class="mb-2">
                            <select class="form-select" name="statut">
                                <?php foreach ($statutLabels as $v => $l): ?>
                                    <option value="<?php echo $v; ?>" <?php echo $sig['statut'] === $v ? 'selected' : ''; ?>>
                                        <?php echo $l['label']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                            <i class="bi bi-check me-1"></i>Mettre à jour le statut
                        </button>
                    </form>
                </div>

                <!-- Responsabilité -->
                <div class="section-card">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-shield-check me-2"></i>Confirmer la responsabilité</h6>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="set_responsabilite">
                        <div class="mb-2">
                            <select class="form-select" name="responsabilite">
                                <option value="non_determine" <?php echo $sig['responsabilite'] === 'non_determine' ? 'selected' : ''; ?>>Non déterminée</option>
                                <option value="locataire" <?php echo $sig['responsabilite'] === 'locataire' ? 'selected' : ''; ?>>Locataire</option>
                                <option value="proprietaire" <?php echo $sig['responsabilite'] === 'proprietaire' ? 'selected' : ''; ?>>Propriétaire</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-outline-success btn-sm w-100">
                            <i class="bi bi-check me-1"></i>Confirmer la responsabilité
                        </button>
                    </form>
                </div>

                <!-- Transférer à un collaborateur -->
                <div class="section-card">
                    <h6 class="fw-semibold mb-3">
                        <i class="bi bi-person-check me-2"></i>Transférer à un collaborateur
                    </h6>
                    <form method="POST" id="attribuer-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="attribuer">

                        <?php if (!empty($collabList)): ?>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Sélectionner un collaborateur</label>
                            <select class="form-select form-select-sm" id="collab-select" name="collab_select_id">
                                <option value="">— Saisie manuelle —</option>
                                <?php foreach ($collabList as $cl): ?>
                                <option value="<?php echo $cl['id']; ?>"
                                    data-nom="<?php echo htmlspecialchars($cl['nom']); ?>"
                                    data-email="<?php echo htmlspecialchars($cl['email'] ?? ''); ?>"
                                    data-tel="<?php echo htmlspecialchars($cl['telephone'] ?? ''); ?>"
                                    <?php echo ((int)($sig['collaborateur_id'] ?? 0) === (int)$cl['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cl['nom']); ?>
                                    <?php if ($cl['metier']): ?>(<?php echo htmlspecialchars($cl['metier']); ?>)<?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-1">
                            <a href="collaborateurs.php" class="small text-muted">
                                <i class="bi bi-gear me-1"></i>Gérer les collaborateurs
                            </a>
                        </div>
                        <?php endif; ?>

                        <div class="mb-2">
                            <input type="text" class="form-control form-control-sm" name="collaborateur_nom" id="collab-nom"
                                   placeholder="Nom du collaborateur *"
                                   value="<?php echo htmlspecialchars($sig['collaborateur_nom'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-2">
                            <input type="email" class="form-control form-control-sm" name="collaborateur_email" id="collab-email"
                                   placeholder="Email"
                                   value="<?php echo htmlspecialchars($sig['collaborateur_email'] ?? ''); ?>">
                        </div>
                        <div class="mb-2">
                            <input type="tel" class="form-control form-control-sm" name="collaborateur_telephone" id="collab-tel"
                                   placeholder="Téléphone / WhatsApp"
                                   value="<?php echo htmlspecialchars($sig['collaborateur_telephone'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Mode d'envoi</label>
                            <div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="mode_notification" id="mode_email"
                                           value="email" <?php echo ($sig['mode_notification_collab'] ?? 'email') === 'email' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="mode_email">Email</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="mode_notification" id="mode_whatsapp"
                                           value="whatsapp" <?php echo ($sig['mode_notification_collab'] ?? '') === 'whatsapp' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="mode_whatsapp">WhatsApp</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="mode_notification" id="mode_les_deux"
                                           value="les_deux" <?php echo ($sig['mode_notification_collab'] ?? '') === 'les_deux' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="mode_les_deux">Les deux</label>
                                </div>
                            </div>
                        </div>
                        <div id="whatsapp-info" class="alert alert-info small mb-2 d-none">
                            <i class="bi bi-whatsapp me-1"></i>
                            Pour WhatsApp, copiez le message ci-dessous et envoyez-le manuellement via l'application :
                            <div class="mt-1 font-monospace small p-2 bg-white rounded border" id="whatsapp-msg">
                                [Signalement <?php echo htmlspecialchars($sig['priorite']); ?>] <?php echo htmlspecialchars($sig['titre']); ?>
                                Adresse : <?php echo htmlspecialchars($sig['adresse']); ?>
                                Priorité : <?php echo htmlspecialchars($sig['priorite']); ?>
                                Description : <?php echo htmlspecialchars(mb_substr($sig['description'], 0, 200)); ?>...
                                Lien mission : <?php echo htmlspecialchars(rtrim($config['SITE_URL'], '/') . '/admin-v2/signalement-detail.php?id=' . $id); ?>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-info btn-sm w-100 text-white">
                            <i class="bi bi-send me-1"></i>Transférer
                        </button>
                    </form>
                </div>

                <?php endif; ?>

            </div>
        </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Auto-remplissage depuis la liste des collaborateurs
    var collabSelect = document.getElementById('collab-select');
    if (collabSelect) {
        collabSelect.addEventListener('change', function() {
            var opt = this.options[this.selectedIndex];
            if (opt.value) {
                document.getElementById('collab-nom').value   = opt.dataset.nom   || '';
                document.getElementById('collab-email').value = opt.dataset.email || '';
                document.getElementById('collab-tel').value   = opt.dataset.tel   || '';
            }
        });
    }
    // Afficher le texte WhatsApp si mode = whatsapp ou les_deux
    document.querySelectorAll('[name="mode_notification"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            var info = document.getElementById('whatsapp-info');
            if (this.value === 'whatsapp' || this.value === 'les_deux') {
                info.classList.remove('d-none');
            } else {
                info.classList.add('d-none');
            }
        });
    });
    // Initialisation
    var selected = document.querySelector('[name="mode_notification"]:checked');
    if (selected && (selected.value === 'whatsapp' || selected.value === 'les_deux')) {
        document.getElementById('whatsapp-info').classList.remove('d-none');
    }
    </script>
</body>
</html>
