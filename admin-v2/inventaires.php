<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

// Get all inventaires
$stmt = $pdo->query("
    SELECT inv.*, c.reference_unique as contrat_ref, 
           CONCAT(cand.prenom, ' ', cand.nom) as locataire,
           l.reference as logement_reference,
           l.type as logement_type,
           inv.statut
    FROM inventaires inv
    LEFT JOIN contrats c ON inv.contrat_id = c.id
    LEFT JOIN candidatures cand ON c.candidature_id = cand.id
    LEFT JOIN logements l ON inv.logement_id = l.id
    WHERE inv.deleted_at IS NULL
    ORDER BY inv.date_inventaire DESC
");
$inventaires = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate by type for tabs
$inventaires_entree = array_filter($inventaires, function($i) { return $i['type'] === 'entree'; });
$inventaires_sortie = array_filter($inventaires, function($i) { return $i['type'] === 'sortie'; });

// Find contracts with both entry and exit for comparison
$contracts_with_both = [];
foreach ($inventaires as $inv) {
    $contrat_id = $inv['contrat_id'];
    if (!isset($contracts_with_both[$contrat_id])) {
        $contracts_with_both[$contrat_id] = ['entree' => false, 'sortie' => false];
    }
    $contracts_with_both[$contrat_id][$inv['type']] = true;
}

// Filter to only those with both
$comparable_contracts = array_filter($contracts_with_both, function($status) {
    return $status['entree'] && $status['sortie'];
});
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventaire - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .table-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .badge-status {
            font-size: 0.75rem;
            padding: 0.35rem 0.65rem;
        }
        .nav-tabs .nav-link {
            color: #6c757d;
            border: none;
            border-bottom: 3px solid transparent;
        }
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            border-bottom: 3px solid #0d6efd;
            background: transparent;
        }
        .search-filter-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
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
                    <h4>Inventaire</h4>
                    <p class="text-muted mb-0">Gestion des inventaires d'équipements d'entrée et de sortie</p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addInventaireModal">
                    <i class="bi bi-plus-circle"></i> Nouvel inventaire
                </button>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Tabs for Entry/Exit -->
        <ul class="nav nav-tabs mb-4" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="entree-tab" data-bs-toggle="tab" data-bs-target="#entree" type="button" role="tab">
                    <i class="bi bi-box-arrow-in-right"></i> Inventaires d'entrée (<?php echo count($inventaires_entree); ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="sortie-tab" data-bs-toggle="tab" data-bs-target="#sortie" type="button" role="tab">
                    <i class="bi bi-box-arrow-right"></i> Inventaires de sortie (<?php echo count($inventaires_sortie); ?>)
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- Entry Tab -->
            <div class="tab-pane fade show active" id="entree" role="tabpanel">
                <?php if (empty($inventaires_entree)): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="bi bi-box-seam" style="font-size: 4rem; color: #dee2e6;"></i>
                            <h5 class="mt-3 text-muted">Aucun inventaire d'entrée enregistré</h5>
                            <p class="text-muted">Créez votre premier inventaire d'entrée pour commencer</p>
                            <button class="btn btn-success" onclick="openModal('entree')">
                                <i class="bi bi-plus-circle"></i> Créer un inventaire d'entrée
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-card">
                        <div class="search-filter-section mb-3">
                            <div class="row">
                                <div class="col-md-6">
                                    <input type="text" id="searchEntree" class="form-control" placeholder="Rechercher par référence, contrat, logement, locataire...">
                                </div>
                                <div class="col-md-3">
                                    <select id="filterStatusEntree" class="form-select">
                                        <option value="">Tous les statuts</option>
                                        <option value="brouillon">Brouillon</option>
                                        <option value="finalise">Finalisé</option>
                                        <option value="envoye">Envoyé</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <button class="btn btn-success w-100" onclick="openModal('entree')">
                                        <i class="bi bi-plus-circle"></i> Nouvel inventaire d'entrée
                                    </button>
                                </div>
                            </div>
                        </div>
                        <table id="tableEntree" class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Référence</th>
                                    <th>Contrat</th>
                                    <th>Locataire</th>
                                    <th>Logement</th>
                                    <th>Date</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inventaires_entree as $inv): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($inv['reference_unique'] ?? 'N/A'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($inv['contrat_ref'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($inv['locataire'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php 
                                        $logement = $inv['logement_reference'] ?? 'N/A';
                                        $type = $inv['logement_type'] ?? '';
                                        echo htmlspecialchars($type ? "$logement ($type)" : $logement);
                                        ?>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($inv['date_inventaire'])); ?></td>
                                    <td>
                                        <?php
                                        $statut = $inv['statut'] ?? 'brouillon';
                                        $badgeClass = [
                                            'brouillon' => 'secondary',
                                            'finalise' => 'info',
                                            'envoye' => 'success'
                                        ];
                                        ?>
                                        <span class="badge bg-<?php echo $badgeClass[$statut] ?? 'secondary'; ?> badge-status">
                                            <?php echo ucfirst($statut); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="edit-inventaire.php?id=<?php echo $inv['id']; ?>" class="btn btn-sm btn-outline-primary" title="Modifier">
                                            <i class="bi bi-pencil"></i> <span class="d-none d-lg-inline">Modifier</span>
                                        </a>
                                        <a href="download-inventaire.php?id=<?php echo $inv['id']; ?>" class="btn btn-sm btn-outline-info" title="Voir PDF" target="_blank">
                                            <i class="bi bi-eye"></i> <span class="d-none d-lg-inline">PDF</span>
                                        </a>
                                        <?php if (isset($comparable_contracts[$inv['contrat_id']])): ?>
                                        <a href="compare-inventaire.php?contrat_id=<?php echo $inv['contrat_id']; ?>" class="btn btn-sm btn-outline-warning" title="Comparer entrée/sortie">
                                            <i class="bi bi-arrows-angle-contract"></i> <span class="d-none d-lg-inline">Comparer</span>
                                        </a>
                                        <?php endif; ?>
                                        <a href="download-inventaire.php?id=<?php echo $inv['id']; ?>&download=1" class="btn btn-sm btn-outline-secondary" title="Télécharger">
                                            <i class="bi bi-download"></i> <span class="d-none d-lg-inline">Télécharger</span>
                                        </a>
                                        <?php if ($inv['statut'] === 'brouillon'): ?>
                                        <a href="finalize-inventaire.php?id=<?php echo $inv['id']; ?>" class="btn btn-sm btn-success" title="Finaliser et envoyer">
                                            <i class="bi bi-send"></i> <span class="d-none d-lg-inline">Finaliser</span>
                                        </a>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Supprimer" 
                                                onclick="confirmDelete(<?php echo $inv['id']; ?>)">
                                            <i class="bi bi-trash"></i> <span class="d-none d-lg-inline">Supprimer</span>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Exit Tab -->
            <div class="tab-pane fade" id="sortie" role="tabpanel">
                <?php if (empty($inventaires_sortie)): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="bi bi-box-seam" style="font-size: 4rem; color: #dee2e6;"></i>
                            <h5 class="mt-3 text-muted">Aucun inventaire de sortie enregistré</h5>
                            <p class="text-muted">Créez votre premier inventaire de sortie pour commencer</p>
                            <button class="btn btn-danger" onclick="openModal('sortie')">
                                <i class="bi bi-plus-circle"></i> Créer un inventaire de sortie
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-card">
                        <div class="search-filter-section mb-3">
                            <div class="row">
                                <div class="col-md-6">
                                    <input type="text" id="searchSortie" class="form-control" placeholder="Rechercher par référence, contrat, logement, locataire...">
                                </div>
                                <div class="col-md-3">
                                    <select id="filterStatusSortie" class="form-select">
                                        <option value="">Tous les statuts</option>
                                        <option value="brouillon">Brouillon</option>
                                        <option value="finalise">Finalisé</option>
                                        <option value="envoye">Envoyé</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <button class="btn btn-danger w-100" onclick="openModal('sortie')">
                                        <i class="bi bi-plus-circle"></i> Nouvel inventaire de sortie
                                    </button>
                                </div>
                            </div>
                        </div>
                        <table id="tableSortie" class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Référence</th>
                                    <th>Contrat</th>
                                    <th>Locataire</th>
                                    <th>Logement</th>
                                    <th>Date</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inventaires_sortie as $inv): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($inv['reference_unique'] ?? 'N/A'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($inv['contrat_ref'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($inv['locataire'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php 
                                        $logement = $inv['logement_reference'] ?? 'N/A';
                                        $type = $inv['logement_type'] ?? '';
                                        echo htmlspecialchars($type ? "$logement ($type)" : $logement);
                                        ?>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($inv['date_inventaire'])); ?></td>
                                    <td>
                                        <?php
                                        $statut = $inv['statut'] ?? 'brouillon';
                                        $badgeClass = [
                                            'brouillon' => 'secondary',
                                            'finalise' => 'info',
                                            'envoye' => 'success'
                                        ];
                                        ?>
                                        <span class="badge bg-<?php echo $badgeClass[$statut] ?? 'secondary'; ?> badge-status">
                                            <?php echo ucfirst($statut); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="edit-inventaire.php?id=<?php echo $inv['id']; ?>" class="btn btn-sm btn-outline-primary" title="Modifier">
                                            <i class="bi bi-pencil"></i> <span class="d-none d-lg-inline">Modifier</span>
                                        </a>
                                        <a href="download-inventaire.php?id=<?php echo $inv['id']; ?>" class="btn btn-sm btn-outline-info" title="Voir PDF" target="_blank">
                                            <i class="bi bi-eye"></i> <span class="d-none d-lg-inline">PDF</span>
                                        </a>
                                        <?php if (isset($comparable_contracts[$inv['contrat_id']])): ?>
                                        <a href="compare-inventaire.php?contrat_id=<?php echo $inv['contrat_id']; ?>" class="btn btn-sm btn-outline-warning" title="Comparer entrée/sortie">
                                            <i class="bi bi-arrows-angle-contract"></i> <span class="d-none d-lg-inline">Comparer</span>
                                        </a>
                                        <?php endif; ?>
                                        <a href="download-inventaire.php?id=<?php echo $inv['id']; ?>&download=1" class="btn btn-sm btn-outline-secondary" title="Télécharger">
                                            <i class="bi bi-download"></i> <span class="d-none d-lg-inline">Télécharger</span>
                                        </a>
                                        <?php if ($inv['statut'] === 'brouillon'): ?>
                                        <a href="finalize-inventaire.php?id=<?php echo $inv['id']; ?>" class="btn btn-sm btn-success" title="Finaliser et envoyer">
                                            <i class="bi bi-send"></i> <span class="d-none d-lg-inline">Finaliser</span>
                                        </a>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Supprimer" 
                                                onclick="confirmDelete(<?php echo $inv['id']; ?>)">
                                            <i class="bi bi-trash"></i> <span class="d-none d-lg-inline">Supprimer</span>
                                        </button>
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

    <!-- Add Inventaire Modal -->
    <div class="modal fade" id="addInventaireModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Nouvel inventaire</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="create-inventaire.php">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Logement <span class="text-danger">*</span></label>
                            <select name="logement_id" class="form-select" required>
                                <option value="">Sélectionner un logement...</option>
                                <?php
                                // Get logements that have at least one validated contract
                                $stmt = $pdo->query("
                                    SELECT l.id, l.reference, l.type, l.adresse,
                                           c.reference_unique as contrat_ref
                                    FROM logements l
                                    INNER JOIN (
                                        SELECT c1.logement_id, c1.reference_unique
                                        FROM contrats c1
                                        INNER JOIN (
                                            SELECT logement_id, MAX(date_creation) as max_date
                                            FROM contrats
                                            WHERE statut = 'valide'
                                            GROUP BY logement_id
                                        ) c2 ON c1.logement_id = c2.logement_id AND c1.date_creation = c2.max_date
                                        WHERE c1.statut = 'valide'
                                    ) c ON l.id = c.logement_id
                                    ORDER BY l.reference
                                ");
                                while ($logement = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                    $id = htmlspecialchars($logement['id'], ENT_QUOTES, 'UTF-8');
                                    $reference = htmlspecialchars($logement['reference'], ENT_QUOTES, 'UTF-8');
                                    $type = htmlspecialchars($logement['type'], ENT_QUOTES, 'UTF-8');
                                    $contrat_ref = $logement['contrat_ref'] ? htmlspecialchars($logement['contrat_ref'], ENT_QUOTES, 'UTF-8') : '';
                                    
                                    $display = "{$reference} - {$type}";
                                    if ($contrat_ref) {
                                        $display .= " ({$contrat_ref})";
                                    }
                                    echo "<option value='{$id}'>{$display}</option>";
                                }
                                ?>
                            </select>
                            <small class="form-text text-muted">Un contrat validé est requis pour créer un inventaire. Les logements avec contrat validé affichent la référence entre parenthèses.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Type <span class="text-danger">*</span></label>
                            <select name="type" class="form-select" required>
                                <option value="">Sélectionner...</option>
                                <option value="entree">Inventaire d'entrée</option>
                                <option value="sortie">Inventaire de sortie</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Date de l'inventaire <span class="text-danger">*</span></label>
                            <input type="date" name="date_inventaire" class="form-control" required 
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Créer l'inventaire</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Confirmer la suppression</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Êtes-vous sûr de vouloir supprimer cet inventaire ?</p>
                    <p class="text-danger"><strong>Cette action est irréversible.</strong></p>
                </div>
                <div class="modal-footer">
                    <form id="deleteForm" method="POST" action="delete-inventaire.php">
                        <input type="hidden" name="inventaire_id" id="deleteInventaireId">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-danger">Supprimer</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTables for both tabs
            if ($('#tableEntree tbody tr').length > 0) {
                const tableEntree = $('#tableEntree').DataTable({
                    language: {
                        url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json'
                    },
                    order: [[4, 'desc']], // Sort by date descending
                    pageLength: 25
                });

                // Search filter for entry
                $('#searchEntree').on('keyup', function() {
                    tableEntree.search(this.value).draw();
                });

                // Status filter for entry
                $('#filterStatusEntree').on('change', function() {
                    tableEntree.column(5).search(this.value).draw();
                });
            }

            if ($('#tableSortie tbody tr').length > 0) {
                const tableSortie = $('#tableSortie').DataTable({
                    language: {
                        url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json'
                    },
                    order: [[4, 'desc']], // Sort by date descending
                    pageLength: 25
                });

                // Search filter for exit
                $('#searchSortie').on('keyup', function() {
                    tableSortie.search(this.value).draw();
                });

                // Status filter for exit
                $('#filterStatusSortie').on('change', function() {
                    tableSortie.column(5).search(this.value).draw();
                });
            }
        });

        function openModal(type) {
            const modal = new bootstrap.Modal(document.getElementById('addInventaireModal'));
            const typeSelect = document.querySelector('select[name="type"]');
            if (type) {
                typeSelect.value = type;
            }
            modal.show();
        }

        function confirmDelete(id) {
            document.getElementById('deleteInventaireId').value = id;
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }
    </script>
</body>
</html>
