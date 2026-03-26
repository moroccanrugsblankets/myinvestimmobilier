<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/document-types.php';
require_once '../includes/functions.php';

// Get application ID and validate
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Validate ID is positive
if (!$id || $id < 1) {
    header('Location: candidatures.php');
    exit;
}

// Handle POST: change logement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_logement') {
    $newLogementId = (int)($_POST['new_logement_id'] ?? 0);
    if ($newLogementId > 0) {
        try {
            $pdo->prepare("UPDATE candidatures SET logement_id = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$newLogementId, $id]);
            $_SESSION['success_cd'] = 'Logement demandé mis à jour.';
        } catch (Exception $e) {
            $_SESSION['error_cd'] = 'Erreur lors de la mise à jour du logement.';
        }
    }
    header("Location: candidature-detail.php?id=$id");
    exit;
}

$successMsg = $_SESSION['success_cd'] ?? null;
$errorMsg   = $_SESSION['error_cd']   ?? null;
unset($_SESSION['success_cd'], $_SESSION['error_cd']);

// Load available logements for the change-logement modal
try {
    $stmtLog = $pdo->query("SELECT id, reference, adresse, type, loyer FROM logements WHERE deleted_at IS NULL ORDER BY reference ASC");
    $allLogements = $stmtLog->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allLogements = [];
}

// Fetch application details with logement information
$stmt = $pdo->prepare("
    SELECT c.*, l.reference as logement_reference, l.adresse as logement_adresse, 
           l.type as logement_type, l.loyer as logement_loyer, l.charges as logement_charges
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

// Fetch documents separately to maintain type information
$stmt = $pdo->prepare("
    SELECT type_document, nom_fichier, chemin_fichier, uploaded_at
    FROM candidature_documents
    WHERE candidature_id = ?
    ORDER BY type_document, uploaded_at
");
$stmt->execute([$id]);
$allDocuments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch action history
// Try to fetch logs using candidature_id first (if column exists)
// Fallback to using type_entite and entite_id (polymorphic structure)
$logs = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM logs 
        WHERE candidature_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If candidature_id column doesn't exist, use polymorphic structure
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM logs 
            WHERE type_entite = 'candidature' AND entite_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$id]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        // If both queries fail, log the error and continue with empty logs
        error_log("Error fetching logs for candidature #$id: " . $e2->getMessage());
        $logs = [];
    }
}

// Process documents and group by type
$documentsByType = [];

foreach ($allDocuments as $doc) {
    $type = $doc['type_document'];
    if (!isset($documentsByType[$type])) {
        $documentsByType[$type] = [];
    }
    $documentsByType[$type][] = [
        'name' => $doc['nom_fichier'],
        'path' => $doc['chemin_fichier'],
        'uploaded_at' => $doc['uploaded_at']
    ];
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
    <title>Détail Candidature - Admin MyInvest</title>
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
        .info-card {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .info-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            width: 200px;
            color: #666;
        }
        .info-value {
            flex: 1;
            color: #333;
        }
        .document-item {
            display: flex;
            align-items: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        .document-type-section {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
        }
        .document-type-section:last-child {
            border-bottom: none;
        }
        .document-type-header {
            font-size: 1rem;
            font-weight: 600;
            color: #0066cc;
            margin-bottom: 12px;
            padding-left: 5px;
            border-left: 3px solid #0066cc;
        }
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        .timeline-item {
            position: relative;
            padding-bottom: 20px;
        }
        .timeline-item:before {
            content: '';
            position: absolute;
            left: -22px;
            top: 5px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #007bff;
        }
        .timeline-item:after {
            content: '';
            position: absolute;
            left: -18px;
            top: 15px;
            width: 2px;
            height: 100%;
            background: #dee2e6;
        }
        .timeline-item:last-child:after {
            display: none;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header d-flex justify-content-between align-items-center">
            <div>
                <h4>Détail de la Candidature #<?php echo htmlspecialchars($candidature['reference_unique']); ?></h4>
                <p class="text-muted mb-0">
                    <i class="bi bi-calendar"></i> Soumise le <?php echo date('d/m/Y à H:i', strtotime($candidature['created_at'])); ?>
                </p>
            </div>
            <div>
                <?php echo getStatusBadge($candidature['statut']); ?>
                <button class="btn btn-primary ms-2" data-bs-toggle="modal" data-bs-target="#changeStatusModal">
                    <i class="bi bi-pencil"></i> Changer le statut
                </button>
            </div>
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

        <div class="row">
            <!-- Left Column -->
            <div class="col-lg-8">
                <!-- Logement Information -->
                <div class="info-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="bi bi-building"></i> Logement Demandé</h5>
                        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#changeLogementModal">
                            <i class="bi bi-pencil me-1"></i>Changer
                        </button>
                    </div>
                    <?php if ($candidature['logement_id']): ?>
                    <div class="info-row">
                        <div class="info-label">Référence:</div>
                        <div class="info-value"><strong><?php echo htmlspecialchars($candidature['logement_reference'] ?? 'N/A'); ?></strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Adresse:</div>
                        <div class="info-value"><?php echo htmlspecialchars($candidature['logement_adresse'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Type:</div>
                        <div class="info-value"><?php echo htmlspecialchars($candidature['logement_type'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Loyer:</div>
                        <div class="info-value">
                            <?php 
                            if (isset($candidature['logement_loyer'])) {
                                echo number_format($candidature['logement_loyer'], 2, ',', ' ') . ' €';
                                if (isset($candidature['logement_charges'])) {
                                    echo ' + ' . number_format($candidature['logement_charges'], 2, ',', ' ') . ' € de charges';
                                }
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <p class="text-muted small mb-0">Aucun logement sélectionné.</p>
                    <?php endif; ?>
                </div>

                <!-- Personal Information -->
                <div class="info-card">
                    <h5 class="mb-3"><i class="bi bi-person-circle"></i> Informations Personnelles</h5>
                    <div class="info-row">
                        <div class="info-label">Nom complet:</div>
                        <div class="info-value"><?php echo htmlspecialchars($candidature['nom'] . ' ' . $candidature['prenom']); ?></div>
                    </div>
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

                <!-- Professional Situation -->
                <div class="info-card">
                    <h5 class="mb-3"><i class="bi bi-briefcase"></i> Situation Professionnelle</h5>
                    <div class="info-row">
                        <div class="info-label">Statut professionnel:</div>
                        <div class="info-value">
                            <strong><?php echo htmlspecialchars($candidature['statut_professionnel']); ?></strong>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Période d'essai:</div>
                        <div class="info-value"><?php echo htmlspecialchars($candidature['periode_essai']); ?></div>
                    </div>
                </div>

                <!-- Revenus & Solvabilité -->
                <div class="info-card">
                    <h5 class="mb-3"><i class="bi bi-cash-stack"></i> Revenus & Solvabilité</h5>
                    <div class="info-row">
                        <div class="info-label">Revenus nets mensuels:</div>
                        <div class="info-value">
                            <strong><?php echo htmlspecialchars(formatRevenus($candidature['revenus_mensuels'] ?? null)); ?></strong>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Type de revenus:</div>
                        <div class="info-value"><?php echo htmlspecialchars($candidature['type_revenus']); ?></div>
                    </div>
                </div>

                <!-- Logement Actuel -->
                <div class="info-card">
                    <h5 class="mb-3"><i class="bi bi-house"></i> Logement Actuel</h5>
                    <div class="info-row">
                        <div class="info-label">Situation:</div>
                        <div class="info-value"><?php echo htmlspecialchars($candidature['situation_logement']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Préavis donné:</div>
                        <div class="info-value"><?php echo htmlspecialchars($candidature['preavis_donne']); ?></div>
                    </div>
                </div>

                <!-- Occupation -->
                <div class="info-card">
                    <h5 class="mb-3"><i class="bi bi-people-fill"></i> Occupation</h5>
                    <div class="info-row">
                        <div class="info-label">Nombre d'occupants:</div>
                        <div class="info-value">
                            <?php 
                            echo htmlspecialchars($candidature['nb_occupants']);
                            if ($candidature['nb_occupants'] === 'Autre' && !empty($candidature['nb_occupants_autre'])) {
                                echo ' (' . htmlspecialchars($candidature['nb_occupants_autre']) . ')';
                            }
                            ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Garantie Visale:</div>
                        <div class="info-value">
                            <?php 
                            $visale = htmlspecialchars($candidature['garantie_visale']);
                            $color = $visale === 'Oui' ? 'success' : ($visale === 'Non' ? 'danger' : 'warning');
                            echo "<span class='badge bg-$color'>$visale</span>";
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Documents -->
                <div class="info-card">
                    <h5 class="mb-3"><i class="bi bi-paperclip"></i> Documents Justificatifs</h5>
                    <?php if (!empty($documentsByType)): ?>
                        <?php foreach ($documentsByType as $type => $docs): ?>
                            <div class="document-type-section">
                                <div class="document-type-header">
                                    <i class="bi bi-folder"></i> 
                                    <?php echo htmlspecialchars(getDocumentTypeLabel($type)); ?>
                                </div>
                                <?php foreach ($docs as $doc): ?>
                                    <div class="document-item">
                                        <i class="bi bi-file-earmark-pdf text-danger me-2"></i>
                                        <span class="flex-grow-1"><?php echo htmlspecialchars($doc['name']); ?></span>
                                        <a href="download-document.php?candidature_id=<?php echo $id; ?>&path=<?php echo urlencode($doc['path']); ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-download"></i> Télécharger
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">Aucun document uploadé</p>
                    <?php endif; ?>
                </div>

                <!-- Workflow / Response Information -->
                <div class="info-card">
                    <h5 class="mb-3"><i class="bi bi-diagram-3"></i> Informations de Traitement</h5>
                    <div class="info-row">
                        <div class="info-label">Réponse automatique:</div>
                        <div class="info-value">
                            <?php 
                            $reponse = $candidature['reponse_automatique'];
                            $reponseMap = [
                                'accepte' => 'Accepté',
                                'refuse' => 'Refusé',
                                'en_attente' => 'En attente'
                            ];
                            $reponseDisplay = $reponseMap[$reponse] ?? htmlspecialchars($reponse);
                            $color = $reponse === 'accepte' ? 'success' : ($reponse === 'refuse' ? 'danger' : 'warning');
                            echo "<span class='badge bg-$color'>$reponseDisplay</span>";
                            ?>
                        </div>
                    </div>
                    <?php if (!empty($candidature['date_soumission'])): ?>
                    <div class="info-row">
                        <div class="info-label">Date de soumission:</div>
                        <div class="info-value"><?php echo date('d/m/Y à H:i', strtotime($candidature['date_soumission'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($candidature['date_reponse_auto'])): ?>
                    <div class="info-row">
                        <div class="info-label">Date réponse auto:</div>
                        <div class="info-value"><?php echo date('d/m/Y à H:i', strtotime($candidature['date_reponse_auto'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($candidature['scheduled_response_date'])): ?>
                    <div class="info-row">
                        <div class="info-label">Réponse prévue le:</div>
                        <div class="info-value">
                            <?php echo date('d/m/Y à H:i', strtotime($candidature['scheduled_response_date'])); ?>
                            <small class="text-muted">(Date fixe calculée lors du refus)</small>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($candidature['date_reponse_envoyee'])): ?>
                    <div class="info-row">
                        <div class="info-label">Date réponse envoyée:</div>
                        <div class="info-value"><?php echo date('d/m/Y à H:i', strtotime($candidature['date_reponse_envoyee'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($candidature['motif_refus'])): ?>
                    <div class="info-row">
                        <div class="info-label">Motif de refus:</div>
                        <div class="info-value"><span class="text-danger"><?php echo nl2br(htmlspecialchars($candidature['motif_refus'])); ?></span></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Visit Information -->
                <?php if (!empty($candidature['date_visite']) || !empty($candidature['notes_visite']) || !empty($candidature['visite_confirmee'])): ?>
                <div class="info-card">
                    <h5 class="mb-3"><i class="bi bi-calendar-check"></i> Informations de Visite</h5>
                    <?php if (!empty($candidature['date_visite'])): ?>
                    <div class="info-row">
                        <div class="info-label">Date de visite:</div>
                        <div class="info-value">
                            <strong><?php echo date('d/m/Y à H:i', strtotime($candidature['date_visite'])); ?></strong>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <div class="info-label">Visite confirmée:</div>
                        <div class="info-value">
                            <?php 
                            $confirmed = !empty($candidature['visite_confirmee']) ? 'Oui' : 'Non';
                            $color = !empty($candidature['visite_confirmee']) ? 'success' : 'secondary';
                            echo "<span class='badge bg-$color'>$confirmed</span>";
                            ?>
                        </div>
                    </div>
                    <?php if (!empty($candidature['notes_visite'])): ?>
                    <div class="info-row">
                        <div class="info-label">Notes de visite:</div>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($candidature['notes_visite'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Admin Information -->
                <div class="info-card">
                    <h5 class="mb-3"><i class="bi bi-gear"></i> Informations Administratives</h5>
                    <div class="info-row">
                        <div class="info-label">Référence unique:</div>
                        <div class="info-value"><code><?php echo htmlspecialchars($candidature['reference_unique']); ?></code></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Priorité:</div>
                        <div class="info-value">
                            <?php 
                            $priorite = (int)$candidature['priorite'];
                            $color = $priorite > 5 ? 'danger' : ($priorite > 0 ? 'warning' : 'secondary');
                            echo "<span class='badge bg-$color'>$priorite</span>";
                            ?>
                        </div>
                    </div>
                    <?php if (!empty($candidature['notes_admin'])): ?>
                    <div class="info-row">
                        <div class="info-label">Notes admin:</div>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($candidature['notes_admin'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column -->
            <div class="col-lg-4">
                <!-- Quick Actions -->
                <div class="info-card">
                    <h5 class="mb-3"><i class="bi bi-lightning"></i> Actions Rapides</h5>
                    <div class="d-grid gap-2">
                        <a href="generer-contrat.php?candidature_id=<?php echo (int)$id; ?>" class="btn btn-success">
                            <i class="bi bi-file-earmark-plus"></i> Générer le contrat
                        </a>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#sendEmailModal">
                            <i class="bi bi-envelope"></i> Envoyer un email
                        </button>
                        <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#addNoteModal">
                            <i class="bi bi-chat-left-text"></i> Ajouter une note
                        </button>
                    </div>
                </div>

                <!-- Activity Timeline -->
                <div class="info-card">
                    <h5 class="mb-3"><i class="bi bi-clock-history"></i> Historique des Actions</h5>
                    <div class="timeline">
                        <?php foreach ($logs as $log): ?>
                            <div class="timeline-item">
                                <div class="small text-muted">
                                    <?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?>
                                </div>
                                <div><?php echo htmlspecialchars($log['action']); ?></div>
                                <?php if ($log['details']): ?>
                                    <div class="small text-muted"><?php echo htmlspecialchars($log['details']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                            <p class="text-muted">Aucune action enregistrée</p>
                        <?php endif; ?>
                    </div>
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
                        <input type="hidden" name="candidature_id" value="<?php echo $id; ?>">
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
                        <input type="hidden" name="candidature_id" value="<?php echo $id; ?>">
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
                        <input type="hidden" name="candidature_id" value="<?php echo $id; ?>">
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

    <!-- Change Logement Modal -->
    <div class="modal fade" id="changeLogementModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-building me-2"></i>Changer le Logement Demandé</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="candidature-detail.php?id=<?php echo $id; ?>">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="change_logement">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Logement :</label>
                            <select name="new_logement_id" class="form-select" required>
                                <option value="">-- Sélectionnez un logement --</option>
                                <?php foreach ($allLogements as $lg): ?>
                                <option value="<?php echo (int)$lg['id']; ?>"
                                    <?php echo ($candidature['logement_id'] == $lg['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($lg['reference']); ?>
                                    — <?php echo htmlspecialchars($lg['adresse']); ?>
                                    <?php if ($lg['type']): ?>(<?php echo htmlspecialchars($lg['type']); ?>)<?php endif; ?>
                                    — <?php echo number_format((float)$lg['loyer'], 0, ',', ' '); ?> €/mois
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
