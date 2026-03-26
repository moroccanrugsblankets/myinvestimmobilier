<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Get application ID and validate
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Validate ID is positive
if (!$id || $id < 1) {
    header('Location: candidatures.php');
    exit;
}

// Fetch application details
$stmt = $pdo->prepare("
    SELECT c.*, l.reference as logement_reference, l.adresse as logement_adresse
    FROM candidatures c
    LEFT JOIN logements l ON c.logement_id = l.id
    WHERE c.id = ?
");
$stmt->execute([$id]);
$candidature = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$candidature) {
    header('Location: candidatures.php');
    exit;
}

// Status badge helper
function getStatusBadge($status) {
    // Map database values to display values
    $statusMap = [
        'en_cours' => 'En cours',
        'accepte' => 'Accepté',
        'refuse' => 'Refusé',
        'visite_planifiee' => 'Refus après visite',
        'refus_apres_visite' => 'Refus après visite',
        'contrat_envoye' => 'Contrat envoyé',
        'contrat_signe' => 'Contrat signé'
    ];
    
    $displayStatus = $statusMap[$status] ?? $status;
    
    $badges = [
        'En cours' => 'bg-primary',
        'Accepté' => 'bg-success',
        'Refusé' => 'bg-danger',
        'Refus après visite' => 'bg-danger',
        'Contrat envoyé' => 'bg-warning',
        'Contrat signé' => 'bg-dark'
    ];
    $class = $badges[$displayStatus] ?? 'bg-secondary';
    return "<span class='badge $class'>" . htmlspecialchars($displayStatus) . "</span>";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Actions Candidature - Admin MyInvest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .header {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .action-card {
            background: white;
            padding: 25px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: box-shadow 0.3s;
        }
        .action-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .action-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }
        .info-row {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            width: 150px;
            color: #666;
        }
        .info-value {
            flex: 1;
            color: #333;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4>Actions - Candidature #<?php echo htmlspecialchars($candidature['reference_unique']); ?></h4>
                    <p class="text-muted mb-0">
                        <strong><?php echo htmlspecialchars($candidature['prenom'] . ' ' . $candidature['nom']); ?></strong>
                    </p>
                </div>
                <div>
                    <?php echo getStatusBadge($candidature['statut']); ?>
                    <a href="candidature-detail.php?id=<?php echo htmlspecialchars($id); ?>" class="btn btn-outline-primary ms-2">
                        <i class="bi bi-eye"></i> Voir détails complets
                    </a>
                </div>
            </div>
        </div>

        <!-- Quick Info -->
        <div class="action-card">
            <h5 class="mb-3"><i class="bi bi-info-circle"></i> Informations Rapides</h5>
            <div class="row">
                <div class="col-md-6">
                    <div class="info-row">
                        <div class="info-label">Email:</div>
                        <div class="info-value">
                            <a href="mailto:<?php echo htmlspecialchars($candidature['email']); ?>">
                                <?php echo htmlspecialchars($candidature['email']); ?>
                            </a>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Téléphone:</div>
                        <div class="info-value">
                            <a href="tel:<?php echo htmlspecialchars($candidature['telephone']); ?>">
                                <?php echo htmlspecialchars($candidature['telephone']); ?>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-row">
                        <div class="info-label">Logement:</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($candidature['logement_reference'] ?? 'N/A'); ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Date:</div>
                        <div class="info-value">
                            <?php echo date('d/m/Y', strtotime($candidature['created_at'])); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions Grid -->
        <div class="row">
            <!-- Change Status -->
            <div class="col-md-4 mb-4">
                <div class="action-card text-center h-100">
                    <i class="bi bi-pencil-square action-icon text-primary"></i>
                    <h5>Changer le statut</h5>
                    <p class="text-muted">Modifier le statut de la candidature</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#changeStatusModal">
                        <i class="bi bi-pencil"></i> Modifier
                    </button>
                </div>
            </div>

            <!-- Send Email -->
            <div class="col-md-4 mb-4">
                <div class="action-card text-center h-100">
                    <i class="bi bi-envelope action-icon text-success"></i>
                    <h5>Envoyer un email</h5>
                    <p class="text-muted">Contacter le candidat par email</p>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#sendEmailModal">
                        <i class="bi bi-send"></i> Envoyer
                    </button>
                </div>
            </div>

            <!-- Add Note -->
            <div class="col-md-4 mb-4">
                <div class="action-card text-center h-100">
                    <i class="bi bi-chat-left-text action-icon text-info"></i>
                    <h5>Ajouter une note</h5>
                    <p class="text-muted">Ajouter une note interne</p>
                    <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#addNoteModal">
                        <i class="bi bi-plus-circle"></i> Ajouter
                    </button>
                </div>
            </div>

            <!-- Generate Contract -->
            <div class="col-md-4 mb-4">
                <div class="action-card text-center h-100">
                    <i class="bi bi-file-earmark-plus action-icon text-warning"></i>
                    <h5>Générer le contrat</h5>
                    <p class="text-muted">Créer le contrat de bail</p>
                    <a href="generer-contrat.php?candidature_id=<?php echo htmlspecialchars($id); ?>" class="btn btn-warning">
                        <i class="bi bi-file-earmark-plus"></i> Générer
                    </a>
                </div>
            </div>

            <!-- View All Details -->
            <div class="col-md-4 mb-4">
                <div class="action-card text-center h-100">
                    <i class="bi bi-list-ul action-icon text-secondary"></i>
                    <h5>Détails complets</h5>
                    <p class="text-muted">Voir toutes les informations</p>
                    <a href="candidature-detail.php?id=<?php echo htmlspecialchars($id); ?>" class="btn btn-secondary">
                        <i class="bi bi-eye"></i> Voir tout
                    </a>
                </div>
            </div>

            <!-- Back to List -->
            <div class="col-md-4 mb-4">
                <div class="action-card text-center h-100">
                    <i class="bi bi-arrow-left-circle action-icon text-dark"></i>
                    <h5>Retour à la liste</h5>
                    <p class="text-muted">Retourner aux candidatures</p>
                    <a href="candidatures.php" class="btn btn-dark">
                        <i class="bi bi-arrow-left"></i> Liste
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Change Status Modal -->
    <div class="modal fade" id="changeStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Changer le Statut</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="change-status.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="candidature_id" value="<?php echo htmlspecialchars($id); ?>">
                        <div class="mb-3">
                            <label class="form-label" for="nouveau_statut">Nouveau statut:</label>
                            <select name="nouveau_statut" id="nouveau_statut" class="form-select" required>
                                <option value="">-- Sélectionner --</option>
                                <option value="en_cours" <?php echo $candidature['statut'] === 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                                <option value="accepte" <?php echo $candidature['statut'] === 'accepte' ? 'selected' : ''; ?>>Accepté</option>
                                <option value="refuse" <?php echo $candidature['statut'] === 'refuse' ? 'selected' : ''; ?>>Refusé</option>
                                <option value="refus_apres_visite" <?php echo $candidature['statut'] === 'refus_apres_visite' || $candidature['statut'] === 'visite_planifiee' ? 'selected' : ''; ?>>Refus après visite</option>
                                <option value="contrat_envoye" <?php echo $candidature['statut'] === 'contrat_envoye' ? 'selected' : ''; ?>>Contrat envoyé</option>
                                <option value="contrat_signe" <?php echo $candidature['statut'] === 'contrat_signe' ? 'selected' : ''; ?>>Contrat signé</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="commentaire">Commentaire (optionnel):</label>
                            <textarea name="commentaire" id="commentaire" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="send_email" id="sendEmail" checked>
                            <label class="form-check-label" for="sendEmail">
                                Envoyer un email au candidat
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Confirmer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Send Email Modal -->
    <div class="modal fade" id="sendEmailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Envoyer un Email</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="send-email-candidature.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="candidature_id" value="<?php echo htmlspecialchars($id); ?>">
                        <div class="mb-3">
                            <label class="form-label">Destinataire:</label>
                            <input type="email" class="form-control" value="<?php echo htmlspecialchars($candidature['email']); ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Sujet:</label>
                            <input type="text" name="sujet" class="form-control" required placeholder="Objet de l'email">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Message:</label>
                            <textarea name="message" class="form-control" rows="8" required placeholder="Votre message..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send"></i> Envoyer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Note Modal -->
    <div class="modal fade" id="addNoteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ajouter une Note</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="add-note-candidature.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="candidature_id" value="<?php echo htmlspecialchars($id); ?>">
                        <div class="mb-3">
                            <label class="form-label">Note:</label>
                            <textarea name="note" class="form-control" rows="5" required placeholder="Entrez votre note ici..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
