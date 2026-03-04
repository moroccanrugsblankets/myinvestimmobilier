<?php
/**
 * Gestion des collaborateurs (intervenants) — Interface admin
 * My Invest Immobilier
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

$errors = [];
$successMsg = '';

// Actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token CSRF invalide. Veuillez recharger la page.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'edit') {
            $nom              = trim($_POST['nom'] ?? '');
            $metier           = trim($_POST['metier'] ?? '');
            $email            = trim($_POST['email'] ?? '');
            $tel              = trim($_POST['telephone'] ?? '');
            $notes            = trim($_POST['notes'] ?? '');
            $actif            = !empty($_POST['actif']) ? 1 : 0;
            $serviceTechnique = !empty($_POST['service_technique']) ? 1 : 0;

            if (empty($nom)) {
                $errors[] = 'Le nom est obligatoire.';
            }
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Adresse email invalide.';
            }

            if (empty($errors)) {
                if ($action === 'add') {
                    try {
                        $pdo->prepare("INSERT INTO collaborateurs (nom, metier, email, telephone, notes, actif, service_technique) VALUES (?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$nom, $metier, $email, $tel, $notes, 1, $serviceTechnique]);
                    } catch (Exception $e) {
                        // Fallback if service_technique column not yet available
                        $pdo->prepare("INSERT INTO collaborateurs (nom, metier, email, telephone, notes, actif) VALUES (?, ?, ?, ?, ?, ?)")
                            ->execute([$nom, $metier, $email, $tel, $notes, 1]);
                    }
                    $successMsg = 'Collaborateur ajouté avec succès.';
                } else {
                    $editId = (int)($_POST['edit_id'] ?? 0);
                    if ($editId > 0) {
                        try {
                            $pdo->prepare("UPDATE collaborateurs SET nom=?, metier=?, email=?, telephone=?, notes=?, actif=?, service_technique=?, updated_at=NOW() WHERE id=?")
                                ->execute([$nom, $metier, $email, $tel, $notes, $actif, $serviceTechnique, $editId]);
                        } catch (Exception $e) {
                            // Fallback if service_technique column not yet available
                            $pdo->prepare("UPDATE collaborateurs SET nom=?, metier=?, email=?, telephone=?, notes=?, actif=?, updated_at=NOW() WHERE id=?")
                                ->execute([$nom, $metier, $email, $tel, $notes, $actif, $editId]);
                        }
                        $successMsg = 'Collaborateur mis à jour.';
                    }
                }
            }
        }

        if ($action === 'delete') {
            $deleteId = (int)($_POST['delete_id'] ?? 0);
            if ($deleteId > 0) {
                // Désactiver plutôt que supprimer (préserve l'historique)
                $pdo->prepare("UPDATE collaborateurs SET actif = 0, updated_at = NOW() WHERE id = ?")
                    ->execute([$deleteId]);
                $successMsg = 'Collaborateur désactivé.';
            }
        }
    }
}

// Liste des collaborateurs
$collaborateurs = $pdo->query("SELECT * FROM collaborateurs ORDER BY actif DESC, nom ASC")->fetchAll(PDO::FETCH_ASSOC);

// Collaborateur en cours d'édition
$editCollab = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    foreach ($collaborateurs as $c) {
        if ($c['id'] === $editId) { $editCollab = $c; break; }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collaborateurs — Admin My Invest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="container-fluid mt-4">

        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h1><i class="bi bi-people me-2"></i>Collaborateurs</h1>
                <p class="text-muted mb-0">Intervenants pouvant être assignés aux tickets de signalement</p>
            </div>
            <a href="signalements.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Signalements
            </a>
        </div>

        <?php if ($successMsg): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($successMsg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Formulaire ajout / édition -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-person-plus me-2"></i>
                        <?php echo $editCollab ? 'Modifier le collaborateur' : 'Ajouter un collaborateur'; ?>
                    </div>
                    <div class="card-body">
                        <form method="POST" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="<?php echo $editCollab ? 'edit' : 'add'; ?>">
                            <?php if ($editCollab): ?>
                            <input type="hidden" name="edit_id" value="<?php echo $editCollab['id']; ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Nom <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nom" required
                                       value="<?php echo htmlspecialchars($editCollab['nom'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Métier / Spécialité</label>
                                <input type="text" class="form-control" name="metier"
                                       placeholder="Électricien, Maçon, Plombier..."
                                       value="<?php echo htmlspecialchars($editCollab['metier'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Email</label>
                                <input type="email" class="form-control" name="email"
                                       value="<?php echo htmlspecialchars($editCollab['email'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Téléphone</label>
                                <input type="text" class="form-control" name="telephone"
                                       value="<?php echo htmlspecialchars($editCollab['telephone'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Notes internes</label>
                                <textarea class="form-control" name="notes" rows="3"><?php echo htmlspecialchars($editCollab['notes'] ?? ''); ?></textarea>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="service_technique" name="service_technique" value="1"
                                           <?php echo !empty($editCollab['service_technique']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-semibold" for="service_technique">
                                        <i class="bi bi-tools me-1"></i>Service Technique
                                    </label>
                                    <div class="form-text">Reçoit automatiquement les notifications de signalement en copie.</div>
                                </div>
                            </div>
                            <?php if ($editCollab): ?>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="actif" name="actif" value="1"
                                           <?php echo ($editCollab['actif'] ?? 1) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="actif">Actif</label>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-fill">
                                    <i class="bi bi-check-lg me-1"></i>
                                    <?php echo $editCollab ? 'Enregistrer' : 'Ajouter'; ?>
                                </button>
                                <?php if ($editCollab): ?>
                                <a href="collaborateurs.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Liste des collaborateurs -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-list-ul me-2"></i>Liste des collaborateurs
                        <span class="badge bg-secondary ms-1"><?php echo count($collaborateurs); ?></span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($collaborateurs)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-people" style="font-size:2.5rem;"></i>
                                <p class="mt-2">Aucun collaborateur enregistré.</p>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Nom</th>
                                        <th>Métier</th>
                                        <th>Contact</th>
                                        <th>Statut</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($collaborateurs as $c): ?>
                                    <tr class="<?php echo !$c['actif'] ? 'text-muted' : ''; ?>">
                                        <td class="fw-semibold"><?php echo htmlspecialchars($c['nom']); ?></td>
                                        <td><?php echo htmlspecialchars($c['metier'] ?? '—'); ?></td>
                                        <td class="small">
                                            <?php if ($c['email']): ?>
                                                <div><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($c['email']); ?></div>
                                            <?php endif; ?>
                                            <?php if ($c['telephone']): ?>
                                                <div><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($c['telephone']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!$c['email'] && !$c['telephone']): ?>—<?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($c['actif']): ?>
                                                <span class="badge bg-success">Actif</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactif</span>
                                            <?php endif; ?>
                                            <?php if (!empty($c['service_technique'])): ?>
                                                <span class="badge bg-warning text-dark ms-1"><i class="bi bi-tools me-1"></i>Serv. Technique</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="collaborateurs.php?edit=<?php echo $c['id']; ?>"
                                               class="btn btn-sm btn-outline-primary me-1">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <?php if ($c['actif']): ?>
                                            <form method="POST" class="d-inline"
                                                  onsubmit="return confirm('Désactiver ce collaborateur ?')">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="delete_id" value="<?php echo $c['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-person-dash"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
