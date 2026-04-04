<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

// Get filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query – only contracts with statut = 'fin'
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
    WHERE c.statut = 'fin' AND c.deleted_at IS NULL
";
$params = [];

if ($search) {
    $sql .= " AND (c.reference_unique LIKE ? OR COALESCE(cl.reference, l.reference) LIKE ? OR COALESCE(cl.adresse, l.adresse) LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$sql .= " ORDER BY c.updated_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$contrats = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = $pdo->query("SELECT COUNT(*) FROM contrats WHERE statut = 'fin' AND deleted_at IS NULL")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contrats Clôturés - Admin MyInvest</title>
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
            color: #343a40;
        }
        .table-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .status-fin { background: #343a40; color: #fff; padding: 5px 10px; border-radius: 4px; font-size: 0.85rem; font-weight: 500; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <h4><i class="bi bi-archive"></i> Contrats Clôturés</h4>
                <a href="contrats.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Contrats actifs
                </a>
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

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Contrats Clôturés</div>
                            <div class="number"><?php echo $total; ?></div>
                        </div>
                        <i class="bi bi-archive" style="font-size: 2rem; color: #343a40;"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="table-card mb-3">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control" placeholder="Rechercher (référence, logement...)" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> Filtrer
                    </button>
                </div>
                <div class="col-md-2">
                    <a href="contrats-clotures.php" class="btn btn-secondary w-100">
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
                            <th>Date Création</th>
                            <th>Date Clôture</th>
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
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><small><?php echo date('d/m/Y', strtotime($contrat['date_creation'])); ?></small></td>
                            <td><small><?php echo $contrat['updated_at'] ? date('d/m/Y', strtotime($contrat['updated_at'])) : '-'; ?></small></td>
                            <td>
                                <span class="status-fin">
                                    <i class="bi bi-archive"></i> Clôturé
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="contrat-detail.php?id=<?php echo $contrat['id']; ?>" class="btn btn-outline-primary" title="Voir détails">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="../pdf/download.php?contrat_id=<?php echo $contrat['id']; ?>" class="btn btn-outline-success" title="Télécharger PDF">
                                        <i class="bi bi-download"></i>
                                    </a>
                                    <form method="POST" action="restaurer-contrat.php" class="d-inline">
                                        <input type="hidden" name="contrat_id" value="<?php echo $contrat['id']; ?>">
                                        <input type="hidden" name="source" value="clotures">
                                        <button type="submit" class="btn btn-outline-warning"
                                                onclick="return confirm('Restaurer ce contrat et le remettre dans les contrats actifs ?')"
                                                title="Restaurer le contrat">
                                            <i class="bi bi-arrow-counterclockwise"></i> Restaurer
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($contrats)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox" style="font-size: 48px;"></i>
                                <p class="mt-3">Aucun contrat clôturé</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
