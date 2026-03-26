<?php
require_once 'auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Handle filters
$filter_statut = $_GET['statut'] ?? '';
$filter_search = $_GET['search'] ?? '';

// Build query
$where = [];
$params = [];

if ($filter_statut) {
    $where[] = "c.statut = ?";
    $params[] = $filter_statut;
}

if ($filter_search) {
    $where[] = "(c.nom LIKE ? OR c.prenom LIKE ? OR c.email LIKE ? OR c.reference_unique LIKE ?)";
    $searchTerm = "%$filter_search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$where[] = "c.deleted_at IS NULL";
$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

$query = "SELECT c.*, l.reference as logement_ref, l.adresse 
          FROM candidatures c 
          LEFT JOIN logements l ON c.logement_id = l.id 
          $whereClause
          ORDER BY c.date_soumission DESC";

// Verify database connection
if (!isset($pdo) || $pdo === null) {
    error_log("[ADMIN CANDIDATURES] ERREUR: Connexion à la base de données non établie");
    die("Erreur: La connexion à la base de données n'est pas disponible pour le panneau d'administration. Veuillez contacter l'administrateur.");
}

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $candidatures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Log diagnostic seulement en mode debug pour éviter de saturer les logs en production
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log("[ADMIN CANDIDATURES] Nombre de candidatures trouvées: " . count($candidatures));
    }
} catch (PDOException $e) {
    error_log("[ADMIN CANDIDATURES] Erreur SQL: " . $e->getMessage());
    error_log("[ADMIN CANDIDATURES] Query: " . $query);
    error_log("[ADMIN CANDIDATURES] Params: " . json_encode($params));
    die("Erreur lors de la récupération des candidatures. Consultez le fichier error.log à la racine du projet pour plus de détails (recherchez '[ADMIN CANDIDATURES]').");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Candidatures - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .header-bar {
            background: white;
            padding: 15px 20px;
            margin: -20px -20px 20px -20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-en-cours { background: #ffc107; color: #000; }
        .status-accepte { background: #28a745; color: white; }
        .status-refuse { background: #dc3545; color: white; }
        .status-visite-planifiee { background: #17a2b8; color: white; }
        .status-contrat-envoye { background: #6f42c1; color: white; }
        .status-contrat-signe { background: #007bff; color: white; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header-bar">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2>Gestion des Candidatures</h2>
                    <p class="text-muted mb-0"><?php echo count($candidatures); ?> candidature(s) trouvée(s)</p>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Rechercher</label>
                        <input type="text" name="search" class="form-control" placeholder="Nom, email, référence..." value="<?php echo htmlspecialchars($filter_search); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Statut</label>
                        <select name="statut" class="form-select">
                            <option value="">Tous</option>
                            <option value="en_cours" <?php echo $filter_statut === 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                            <option value="refuse" <?php echo $filter_statut === 'refuse' ? 'selected' : ''; ?>>Refusé</option>
                            <option value="accepte" <?php echo $filter_statut === 'accepte' ? 'selected' : ''; ?>>Accepté</option>
                            <option value="refus_apres_visite" <?php echo $filter_statut === 'refus_apres_visite' ? 'selected' : ''; ?>>Refus après visite</option>
                            <option value="contrat_envoye" <?php echo $filter_statut === 'contrat_envoye' ? 'selected' : ''; ?>>Contrat envoyé</option>
                            <option value="contrat_signe" <?php echo $filter_statut === 'contrat_signe' ? 'selected' : ''; ?>>Contrat signé</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Filtrer</button>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <a href="candidatures.php" class="btn btn-outline-secondary w-100">Réinitialiser</a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Applications Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Candidat</th>
                                <th>Contact</th>
                                <th>Logement</th>
                                <th>Date</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($candidatures as $cand): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($cand['prenom'] . ' ' . $cand['nom']); ?></strong>
                                </td>
                                <td>
                                    <small>
                                        <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($cand['email']); ?><br>
                                        <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($cand['telephone']); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($cand['logement_ref']): ?>
                                        <small><?php echo htmlspecialchars($cand['logement_ref']); ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">-</small>
                                    <?php endif; ?>
                                </td>
                                <td><small><?php echo date('d/m/Y', strtotime($cand['date_soumission'])); ?></small></td>
                                <td>
                                    <?php if ($cand['statut']): ?>
                                        <span class="status-badge status-<?php echo strtolower(str_replace('_', '-', $cand['statut'])); ?>">
                                            <?php echo htmlspecialchars(formatStatut($cand['statut'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="candidature-detail.php?id=<?php echo $cand['id']; ?>" class="btn btn-outline-primary" title="Voir détails">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="generer-contrat.php?candidature_id=<?php echo (int)$cand['id']; ?>" class="btn btn-outline-success" title="Générer le contrat">
                                            <i class="bi bi-file-earmark-plus"></i>
                                        </a>
                                        <a href="candidature-actions.php?id=<?php echo $cand['id']; ?>" class="btn btn-outline-secondary" title="Actions">
                                            <i class="bi bi-gear"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger" title="Supprimer" onclick="confirmDelete(<?php echo $cand['id']; ?>, '<?php echo htmlspecialchars($cand['reference_unique'] ?? 'N/A', ENT_QUOTES); ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($candidatures)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox" style="font-size: 48px;"></i>
                                    <p class="mt-3">Aucune candidature trouvée</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteModalLabel">Confirmer la suppression</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Êtes-vous sûr de vouloir supprimer la candidature <strong id="candidatureRef"></strong> ?</p>
                    <p class="text-danger mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Cette action est irréversible.</p>
                </div>
                <div class="modal-footer">
                    <form method="POST" action="delete-candidature.php" id="deleteForm">
                        <input type="hidden" name="candidature_id" id="candidatureId">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-danger">Supprimer</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function confirmDelete(candidatureId, candidatureRef) {
        document.getElementById('candidatureId').value = candidatureId;
        document.getElementById('candidatureRef').textContent = candidatureRef;
        var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
        deleteModal.show();
    }
    </script>
</body>
</html>
