<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

// Get filters
$statut_filter = isset($_GET['statut']) ? $_GET['statut'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query (using contrat_logement for frozen logement data)
$sql = "
    SELECT c.*, 
           COALESCE(cl.reference, l.reference) as logement_ref, 
           COALESCE(cl.adresse, l.adresse) as logement_adresse,
           COALESCE(cl.type, l.type) as logement_type,
           (SELECT COUNT(*) FROM locataires WHERE contrat_id = c.id) as nb_locataires_signed,
           (SELECT GROUP_CONCAT(CONCAT(prenom, ' ', nom) ORDER BY ordre SEPARATOR ', ') FROM locataires WHERE contrat_id = c.id) as noms_locataires
    FROM contrats c
    LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
    LEFT JOIN logements l ON c.logement_id = l.id
    WHERE c.statut != 'fin' AND c.deleted_at IS NULL
";
$params = [];

if ($statut_filter) {
    $sql .= " AND c.statut = ?";
    $params[] = $statut_filter;
}

if ($search) {
    $sql .= " AND (c.reference_unique LIKE ? OR COALESCE(cl.reference, l.reference) LIKE ? OR COALESCE(cl.adresse, l.adresse) LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$sql .= " ORDER BY c.date_creation DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$contrats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [
    'total' => $pdo->query("SELECT COUNT(*) FROM contrats")->fetchColumn(),
    'en_attente' => $pdo->query("SELECT COUNT(*) FROM contrats WHERE statut = 'en_attente'")->fetchColumn(),
    'signe' => $pdo->query("SELECT COUNT(*) FROM contrats WHERE statut = 'signe'")->fetchColumn(),
    'valide' => $pdo->query("SELECT COUNT(*) FROM contrats WHERE statut = 'valide'")->fetchColumn(),
    'expire' => $pdo->query("SELECT COUNT(*) FROM contrats WHERE statut = 'expire'")->fetchColumn()
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Contrats - Admin MyInvest</title>
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
        .stats-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .stats-card .number {
            font-size: 2rem;
            font-weight: bold;
            color: #007bff;
        }
        .table-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .status-badge {
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .status-en_attente { background: #fff3cd; color: #856404; }
        .status-signe { background: #d4edda; color: #155724; }
        .status-valide { background: #d1ecf1; color: #0c5460; }
        .status-expire { background: #f8d7da; color: #721c24; }
        .status-annule { background: #e2e3e5; color: #383d41; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <h4>Gestion des Contrats</h4>
                <div class="d-flex gap-2">
                    <a href="quittances.php" class="btn btn-info">
                        <i class="bi bi-receipt"></i> Quittances
                    </a>
                    <a href="generer-contrat.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Générer un contrat
                    </a>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['warning'])): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <?php echo $_SESSION['warning']; unset($_SESSION['warning']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">En Attente</div>
                            <div class="number text-warning"><?php echo $stats['en_attente']; ?></div>
                        </div>
                        <i class="bi bi-clock" style="font-size: 2rem; color: #ffc107;"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Signés</div>
                            <div class="number text-success"><?php echo $stats['signe']; ?></div>
                        </div>
                        <i class="bi bi-check-circle" style="font-size: 2rem; color: #28a745;"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Validés</div>
                            <div class="number text-info"><?php echo $stats['valide']; ?></div>
                        </div>
                        <i class="bi bi-patch-check" style="font-size: 2rem; color: #17a2b8;"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Total Contrats</div>
                            <div class="number"><?php echo $stats['total']; ?></div>
                        </div>
                        <i class="bi bi-file-earmark-check" style="font-size: 2rem; color: #007bff;"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="table-card mb-3">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Rechercher (référence, logement...)" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <select name="statut" class="form-select">
                        <option value="">Tous les statuts</option>
                        <option value="en_attente" <?php echo $statut_filter === 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                        <option value="signe" <?php echo $statut_filter === 'signe' ? 'selected' : ''; ?>>Signé</option>
                        <option value="valide" <?php echo $statut_filter === 'valide' ? 'selected' : ''; ?>>Validé</option>
                        <option value="expire" <?php echo $statut_filter === 'expire' ? 'selected' : ''; ?>>Expiré</option>
                        <option value="annule" <?php echo $statut_filter === 'annule' ? 'selected' : ''; ?>>Annulé</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> Filtrer
                    </button>
                </div>
                <div class="col-md-3">
                    <a href="contrats.php" class="btn btn-secondary w-100">
                        <i class="bi bi-x-circle"></i> Réinitialiser
                    </a>
                </div>
            </form>
        </div>

        <!-- Table -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Référence</th>
                            <th>Logement</th>
                            <th>Locataires</th>
                            <?php /*
                            <th>Date Création</th>
                            <th>Date Expiration</th>
                            <th>Date Signature</th>
                            */ ?>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contrats as $contrat): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($contrat['reference_unique']); ?></strong></td>
                            <td>
                                <strong><?php echo htmlspecialchars($contrat['logement_ref']); ?></strong>
                                <br><small class="text-muted"><?php echo htmlspecialchars($contrat['logement_adresse']); ?></small>
                            </td>
                            <td>
                                <?php if ($contrat['noms_locataires']): ?>
                                    <span><?php echo htmlspecialchars($contrat['noms_locataires']); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-info">
                                        <?php echo $contrat['nb_locataires']; ?> locataire(s)
                                    </span>
                                <?php endif; ?>
                                <br><small class="text-muted"><?php echo $contrat['nb_locataires_signed']; ?> signé(s)</small>
                            </td>
                            <?php /*
                            <td><small><?php echo date('d/m/Y', strtotime($contrat['date_creation'])); ?></small></td>
                            <td>
                                <?php if ($contrat['date_expiration']): ?>
                                    <small><?php echo date('d/m/Y', strtotime($contrat['date_expiration'])); ?></small>
                                <?php else: ?>
                                    <small class="text-muted">-</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($contrat['date_signature']): ?>
                                    <small><?php echo date('d/m/Y H:i', strtotime($contrat['date_signature'])); ?></small>
                                <?php else: ?>
                                    <small class="text-muted">Non signé</small>
                                <?php endif; ?>
                            </td>
                            */ ?>
                            <td>
                                <span class="status-badge status-<?php echo $contrat['statut']; ?>">
                                    <?php
                                    $statut_labels = [
                                        'en_attente' => 'En attente',
                                        'signe' => 'Signé',
                                        'valide' => 'Validé',
                                        'expire' => 'Expiré',
                                        'annule' => 'Annulé'
                                    ];
                                    echo $statut_labels[$contrat['statut']] ?? $contrat['statut'];
                                    ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="contrat-detail.php?id=<?php echo $contrat['id']; ?>" class="btn btn-outline-primary" title="Voir détails">
                                        <i class="bi bi-eye"></i> <span class="d-none d-lg-inline">Voir</span>
                                    </a>
                                    <?php if ($contrat['statut'] === 'signe' || $contrat['statut'] === 'valide'): ?>
                                        <a href="../pdf/download.php?contrat_id=<?php echo $contrat['id']; ?>" class="btn btn-outline-success" title="Télécharger PDF">
                                            <i class="bi bi-download"></i> <span class="d-none d-lg-inline">PDF</span>
                                        </a>
                                        <a href="edit-bilan-logement.php?contrat_id=<?php echo $contrat['id']; ?>" class="btn btn-outline-info" title="Bilan de logement">
                                            <i class="bi bi-clipboard-check"></i> <span class="d-none d-lg-inline">Bilan</span>
                                        </a>
                                        <a href="quittances.php?contrat_id=<?php echo $contrat['id']; ?>" class="btn btn-outline-secondary" title="Quittances">
                                            <i class="bi bi-receipt"></i> <span class="d-none d-lg-inline">Quittances</span>
                                        </a>
                                        <a href="gestion-loyers.php?contrat_id=<?php echo $contrat['id']; ?>" class="btn btn-outline-warning" title="Gestion du loyer">
                                            <i class="bi bi-cash-stack"></i> <span class="d-none d-lg-inline">Loyers</span>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($contrat['statut'] === 'valide' && !empty($contrat['date_demande_depart'])): ?>
                                        <button type="button" class="btn btn-outline-info"
                                                onclick="openAR24Modal(<?php echo $contrat['id']; ?>, '', '<?php echo htmlspecialchars($contrat['date_fin_prevue'] ?? '', ENT_QUOTES); ?>')"
                                                title="Confirmer réception courrier AR24">
                                            <i class="bi bi-envelope-check"></i> <span class="d-none d-lg-inline">AR24</span>
                                        </button>
                                        <button type="button" class="btn btn-outline-dark"
                                                onclick="openFinContratModal(<?php echo $contrat['id']; ?>, '<?php echo htmlspecialchars($contrat['reference_unique'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($contrat['date_fin_prevue'] ?? '', ENT_QUOTES); ?>')"
                                                title="Fin de contrat (remise des clés)">
                                            <i class="bi bi-door-closed"></i> <span class="d-none d-lg-inline">Fin</span>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($contrat['statut'] === 'en_attente'): ?>
                                        <button class="btn btn-outline-warning" title="Renvoyer le lien" onclick="resendLink(<?php echo $contrat['id']; ?>)">
                                            <i class="bi bi-envelope"></i> <span class="d-none d-lg-inline">Renvoyer</span>
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline-secondary" title="Documents du contrat"
                                           onclick="openDocsModal(<?php echo $contrat['id']; ?>, '<?php echo htmlspecialchars($contrat['reference_unique'], ENT_QUOTES); ?>')">
                                       <i class="bi bi-folder2-open"></i> <span class="d-none d-lg-inline">Docs</span>
                                    </button>
                                    <button class="btn btn-outline-danger" title="Supprimer" onclick="deleteContract(<?php echo $contrat['id']; ?>, '<?php echo htmlspecialchars($contrat['reference_unique'], ENT_QUOTES); ?>')">
                                       <i class="bi bi-trash"></i> <span class="d-none d-lg-inline">Supprimer</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($contrats)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox" style="font-size: 48px;"></i>
                                <p class="mt-3">Aucun contrat trouvé</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function resendLink(contractId) {
            if (confirm('Voulez-vous renvoyer le lien de signature ?\n\nUn email sera envoyé au client et aux administrateurs.')) {
                // Send AJAX request to resend the link
                fetch('renvoyer-lien-signature.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ contrat_id: contractId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('✓ ' + data.message);
                        location.reload();
                    } else {
                        alert('Erreur: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Erreur lors de l\'envoi de la requête');
                });
            }
        }
        
        function deleteContract(contractId, reference) {
            document.getElementById('contractId').value = contractId;
            document.getElementById('contractRef').textContent = reference;
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        }

        function openFinContratModal(contractId, reference, dateFin) {
            document.getElementById('finContratId').value = contractId;
            document.getElementById('finContratRef').textContent = reference;
            var dateInput = document.getElementById('finContratDateFin');
            dateInput.value = dateFin || '';
            var finModal = new bootstrap.Modal(document.getElementById('finContratModal'));
            finModal.show();
        }

        function openAR24Modal(contratId, source, dateFin) {
            document.getElementById('ar24ContratId').value = contratId;
            document.getElementById('ar24DateFin').value = dateFin || '';
            var modal = new bootstrap.Modal(document.getElementById('ar24Modal'));
            modal.show();
        }
    </script>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteModalLabel">Confirmer la suppression</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Êtes-vous sûr de vouloir supprimer le contrat <strong id="contractRef"></strong> ?</p>
                    <p class="text-danger mb-0">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>Cette action effectue une suppression logique (corbeille) :
                    </p>
                    <ul class="text-danger">
                        <li>Le contrat est masqué des contrats actifs</li>
                        <li>Les données restent conservées (contrat, PDF, documents)</li>
                        <li>Le contrat pourra être restauré depuis « Contrats supprimés »</li>
                    </ul>
                    <p class="text-muted">Le logement sera remis en disponibilité tant que le contrat reste supprimé.</p>
                </div>
                <div class="modal-footer">
                    <form method="POST" action="supprimer-contrat.php" id="deleteForm">
                        <input type="hidden" name="contrat_id" id="contractId">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-danger">Supprimer</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- Fin de Contrat Modal -->
    <div class="modal fade" id="finContratModal" tabindex="-1" aria-labelledby="finContratModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="finContratModalLabel"><i class="bi bi-door-closed"></i> Fin de contrat</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="fin-contrat.php">
                    <div class="modal-body">
                        <p>Confirmer la fin du contrat <strong id="finContratRef"></strong> suite à la remise des clés ?</p>
                        <div class="mb-3">
                            <label for="finContratDateFin" class="form-label fw-semibold">Date de fin prévue <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="finContratDateFin" name="date_fin_prevue" required>
                            <div class="form-text">Indiquez la date prévue de fin de contrat (remise des clés).</div>
                        </div>
                        <p class="text-muted mb-0">Le contrat sera clôturé et le logement remis en disponibilité.</p>
                    </div>
                    <div class="modal-footer">
                        <input type="hidden" name="contrat_id" id="finContratId">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-dark"><i class="bi bi-door-closed"></i> Clôturer le contrat</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- AR24 Confirmation Modal -->
    <div class="modal fade" id="ar24Modal" tabindex="-1" aria-labelledby="ar24ModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="ar24ModalLabel"><i class="bi bi-envelope-check"></i> Confirmation Réception AR24</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="envoyer-confirmation-depart.php">
                    <div class="modal-body">
                        <p>Envoyer au locataire la confirmation de réception du courrier AR24.</p>
                        <div class="mb-3">
                            <label for="ar24DateFin" class="form-label fw-semibold">Date de fin prévue du contrat</label>
                            <input type="date" class="form-control" id="ar24DateFin" name="date_fin_prevue">
                            <div class="form-text">Optionnel. Si renseignée, met à jour la date de fin du contrat avant l'envoi.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <input type="hidden" name="contrat_id" id="ar24ContratId">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-info text-white"><i class="bi bi-envelope-check"></i> Envoyer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Documents Modal -->
    <div class="modal fade" id="docsModal" tabindex="-1" aria-labelledby="docsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #2c3e50, #3498db);">
                    <h5 class="modal-title text-white" id="docsModalLabel">
                        <i class="bi bi-folder2-open me-2"></i>Documents — <span id="docsModalRef"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Loading -->
                    <div id="docsLoading" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-muted">Chargement des documents…</p>
                    </div>

                    <!-- Contenu -->
                    <div id="docsContent" style="display:none;">
                        <!-- PDFs -->
                        <div id="docsPdfSection">
                            <h6 class="fw-bold mb-3"><i class="bi bi-file-earmark-pdf text-danger me-2"></i>Documents PDF</h6>
                            <div id="docsPdfList" class="list-group mb-4"></div>
                        </div>

                        <!-- Photos -->
                        <div id="docsPhotosSection">
                            <h6 class="fw-bold mb-3"><i class="bi bi-images text-primary me-2"></i>Photos</h6>
                            <!-- Grande photo active -->
                            <div class="text-center mb-3 position-relative" id="docsGalleryMain" style="background:#000; border-radius:8px; overflow:hidden; min-height:300px; max-height:520px; display:flex; align-items:center; justify-content:center;">
                                <button class="btn btn-light btn-sm position-absolute start-0 top-50 translate-middle-y ms-2" id="docsPrevBtn" style="z-index:10;" onclick="docsGalleryNav(-1)">
                                    <i class="bi bi-chevron-left"></i>
                                </button>
                                <img id="docsMainImg" src="" alt="" style="max-width:100%; max-height:520px; object-fit:contain; display:block;">
                                <button class="btn btn-light btn-sm position-absolute end-0 top-50 translate-middle-y me-2" id="docsNextBtn" style="z-index:10;" onclick="docsGalleryNav(1)">
                                    <i class="bi bi-chevron-right"></i>
                                </button>
                            </div>
                            <p class="text-center text-muted small mb-3" id="docsImgLabel"></p>
                            <!-- Miniatures -->
                            <div id="docsThumbs" class="d-flex flex-wrap gap-2 justify-content-center"></div>
                        </div>

                        <!-- Aucun document -->
                        <div id="docsEmpty" class="text-center py-4 text-muted" style="display:none;">
                            <i class="bi bi-inbox" style="font-size:40px;"></i>
                            <p class="mt-2">Aucun document trouvé pour ce contrat.</p>
                        </div>
                    </div>

                    <!-- Erreur -->
                    <div id="docsError" class="alert alert-danger" style="display:none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Photo Lightbox -->
    <div class="modal fade" id="docsLightbox" tabindex="-1" aria-hidden="true" style="z-index:1100;">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content bg-black">
                <div class="modal-header border-0">
                    <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body d-flex align-items-center justify-content-center position-relative p-0">
                    <button class="btn btn-light btn-sm position-absolute start-0 ms-3" onclick="docsGalleryNav(-1)" style="z-index:10;">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <img id="docsLightboxImg" src="" alt="" style="max-width:100%; max-height:90vh; object-fit:contain;">
                    <button class="btn btn-light btn-sm position-absolute end-0 me-3" onclick="docsGalleryNav(1)" style="z-index:10;">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <p class="text-white small mb-0" id="docsLightboxLabel"></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // ── Docs Gallery state ────────────────────────────────────────────────
        var _docsPhotos = [];
        var _docsCurrentIdx = 0;

        function openDocsModal(contratId, reference) {
            document.getElementById('docsModalRef').textContent = reference;
            document.getElementById('docsLoading').style.display = '';
            document.getElementById('docsContent').style.display = 'none';
            document.getElementById('docsError').style.display = 'none';

            var modal = new bootstrap.Modal(document.getElementById('docsModal'));
            modal.show();

            fetch('get-contrat-docs.php?contrat_id=' + encodeURIComponent(contratId))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.error) {
                        document.getElementById('docsLoading').style.display = 'none';
                        var errEl = document.getElementById('docsError');
                        errEl.textContent = data.error;
                        errEl.style.display = '';
                        return;
                    }
                    renderDocs(data.pdfs || [], data.photos || []);
                })
                .catch(function(err) {
                    document.getElementById('docsLoading').style.display = 'none';
                    var errEl = document.getElementById('docsError');
                    errEl.textContent = 'Erreur lors du chargement des documents.';
                    errEl.style.display = '';
                });
        }

        function renderDocs(pdfs, photos) {
            document.getElementById('docsLoading').style.display = 'none';
            document.getElementById('docsContent').style.display = '';

            var hasPdfs   = pdfs.length > 0;
            var hasPhotos = photos.length > 0;

            // PDFs
            var pdfSection = document.getElementById('docsPdfSection');
            var pdfList    = document.getElementById('docsPdfList');
            pdfSection.style.display = hasPdfs ? '' : 'none';
            pdfList.innerHTML = '';
            pdfs.forEach(function(doc) {
                var a = document.createElement('a');
                a.href   = doc.url;
                a.target = '_blank';
                a.rel    = 'noopener noreferrer';
                a.className = 'list-group-item list-group-item-action d-flex align-items-center gap-2';
                a.innerHTML = '<i class="bi bi-file-earmark-pdf text-danger fs-5"></i>'
                            + '<span class="flex-grow-1">' + escapeHtml(doc.label) + '</span>'
                            + '<i class="bi bi-box-arrow-up-right text-muted"></i>';
                pdfList.appendChild(a);
            });

            // Photos
            var photosSection = document.getElementById('docsPhotosSection');
            photosSection.style.display = hasPhotos ? '' : 'none';
            _docsPhotos = photos;
            _docsCurrentIdx = 0;

            var thumbs = document.getElementById('docsThumbs');
            thumbs.innerHTML = '';
            photos.forEach(function(ph, idx) {
                var img = document.createElement('img');
                img.src     = ph.url;
                img.alt     = ph.label;
                img.title   = ph.label;
                img.dataset.idx = idx;
                img.style.cssText = 'width:80px; height:80px; object-fit:cover; border-radius:6px; cursor:pointer; border:2px solid transparent; transition:border-color .2s;';
                img.onclick = function() { docsGalleryShow(idx); };
                thumbs.appendChild(img);
            });

            if (hasPhotos) {
                docsGalleryShow(0);
            }

            // Empty state
            document.getElementById('docsEmpty').style.display = (!hasPdfs && !hasPhotos) ? '' : 'none';
        }

        function docsGalleryShow(idx) {
            if (_docsPhotos.length === 0) return;
            idx = ((idx % _docsPhotos.length) + _docsPhotos.length) % _docsPhotos.length;
            _docsCurrentIdx = idx;

            var ph = _docsPhotos[idx];
            document.getElementById('docsMainImg').src       = ph.url;
            document.getElementById('docsMainImg').alt       = ph.label;
            document.getElementById('docsImgLabel').textContent = ph.label + ' (' + (idx + 1) + '/' + _docsPhotos.length + ')';

            // Sync lightbox
            document.getElementById('docsLightboxImg').src          = ph.url;
            document.getElementById('docsLightboxLabel').textContent = ph.label + ' (' + (idx + 1) + '/' + _docsPhotos.length + ')';

            // Highlight active thumb
            document.querySelectorAll('#docsThumbs img').forEach(function(t) {
                t.style.borderColor = parseInt(t.dataset.idx) === idx ? '#0d6efd' : 'transparent';
                t.style.opacity     = parseInt(t.dataset.idx) === idx ? '1' : '0.65';
            });

            // Show/hide nav buttons
            var hasPrev = _docsPhotos.length > 1;
            document.getElementById('docsPrevBtn').style.display = hasPrev ? '' : 'none';
            document.getElementById('docsNextBtn').style.display = hasPrev ? '' : 'none';
        }

        function docsGalleryNav(dir) {
            docsGalleryShow(_docsCurrentIdx + dir);
        }

        // Click on main image to open lightbox
        document.getElementById('docsMainImg').addEventListener('click', function() {
            if (_docsPhotos.length === 0) return;
            var lb = new bootstrap.Modal(document.getElementById('docsLightbox'));
            lb.show();
        });

        function escapeHtml(str) {
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(str));
            return d.innerHTML;
        }
    </script>
</body>
</html>
