<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                try {
                    $typeContratAdd = in_array($_POST['type_contrat'] ?? '', ['meuble', 'non_meuble', 'sur_mesure'])
                        ? $_POST['type_contrat']
                        : 'meuble';
                    $dureeGarantieAdd = ($typeContratAdd === 'non_meuble') ? 1 : 2;
                    $stmt = $pdo->prepare("
                        INSERT INTO logements (reference, adresse, type, surface, loyer, charges, depot_garantie, parking, statut, date_disponibilite, lien_externe, type_contrat, duree_garantie, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'disponible', ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $_POST['reference'],
                        $_POST['adresse'],
                        $_POST['type'],
                        $_POST['surface'],
                        $_POST['loyer'],
                        $_POST['charges'],
                        $_POST['depot_garantie'],
                        $_POST['parking'],
                        !empty($_POST['date_disponibilite']) ? $_POST['date_disponibilite'] : null,
                        !empty($_POST['lien_externe']) ? trim($_POST['lien_externe']) : null,
                        $typeContratAdd,
                        $dureeGarantieAdd,
                    ]);
                    $_SESSION['success'] = "Logement ajouté avec succès";
                } catch (PDOException $e) {
                    // Check if it's a duplicate key error
                    if ($e->getCode() === '23000') {
                        $_SESSION['error'] = "Erreur : Un logement avec cette référence existe déjà.";
                    } else {
                        $_SESSION['error'] = "Erreur lors de l'ajout du logement.";
                        error_log("Erreur ajout logement: " . $e->getMessage());
                    }
                }
                break;
                
            case 'edit':
                try {
                    // Map French UI values to database values
                    $statutMap = [
                        'Disponible' => 'disponible',
                        'Réservé' => 'reserve',
                        'Loué' => 'en_location',
                        'Maintenance' => 'maintenance',
                        'Indisponible' => 'indisponible'
                    ];
                    $dbStatut = $statutMap[$_POST['statut']] ?? strtolower($_POST['statut']);
                    
                    $typeContratEdit = in_array($_POST['type_contrat'] ?? '', ['meuble', 'non_meuble', 'sur_mesure'])
                        ? $_POST['type_contrat']
                        : 'meuble';

                    $dureeGarantieEdit = ($typeContratEdit === 'non_meuble') ? 1 : 2;

                    $stmt = $pdo->prepare("
                        UPDATE logements SET 
                            reference = ?, adresse = ?, type = ?, surface = ?,
                            loyer = ?, charges = ?, depot_garantie = ?, parking = ?, statut = ?, date_disponibilite = ?, lien_externe = ?, type_contrat = ?, duree_garantie = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $_POST['reference'],
                        $_POST['adresse'],
                        $_POST['type'],
                        $_POST['surface'],
                        $_POST['loyer'],
                        $_POST['charges'],
                        $_POST['depot_garantie'],
                        $_POST['parking'],
                        $dbStatut,
                        !empty($_POST['date_disponibilite']) ? $_POST['date_disponibilite'] : null,
                        !empty($_POST['lien_externe']) ? trim($_POST['lien_externe']) : null,
                        $typeContratEdit,
                        $dureeGarantieEdit,
                        $_POST['logement_id']
                    ]);
                    $_SESSION['success'] = "Logement modifié avec succès";
                } catch (PDOException $e) {
                    // Check if it's a duplicate key error
                    if ($e->getCode() === '23000') {
                        $_SESSION['error'] = "Erreur : Un logement avec cette référence existe déjà.";
                    } else {
                        $_SESSION['error'] = "Erreur lors de la modification du logement.";
                        error_log("Erreur modification logement: " . $e->getMessage());
                    }
                }
                break;
                
            case 'delete':
                // Soft delete logement (set deleted_at timestamp instead of DELETE)
                $stmt = $pdo->prepare("UPDATE logements SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$_POST['logement_id']]);
                $_SESSION['success'] = "Logement supprimé";
                break;
                
            case 'set_defaults':
                try {
                    // Validate numeric inputs
                    $cles_appartement = isset($_POST['default_cles_appartement']) ? (int)$_POST['default_cles_appartement'] : 2;
                    $cles_boite_lettres = isset($_POST['default_cles_boite_lettres']) ? (int)$_POST['default_cles_boite_lettres'] : 1;
                    
                    // Server-side validation: ensure non-negative values
                    if ($cles_appartement < 0 || $cles_appartement > 100) {
                        $_SESSION['error'] = "Le nombre de clés d'appartement doit être entre 0 et 100.";
                        header('Location: logements.php');
                        exit;
                    }
                    if ($cles_boite_lettres < 0 || $cles_boite_lettres > 100) {
                        $_SESSION['error'] = "Le nombre de clés de boîte aux lettres doit être entre 0 et 100.";
                        header('Location: logements.php');
                        exit;
                    }
                    
                    // Default room description template
                    $default_room_description = "• Revêtement de sol : parquet très bon état d'usage\n• Murs : peintures très bon état\n• Plafond : peintures très bon état\n• Installations électriques et plomberie : fonctionnelles";
                    
                    // Sanitize and validate text inputs
                    $etat_logement = !empty($_POST['default_etat_logement']) 
                        ? trim($_POST['default_etat_logement']) 
                        : $default_room_description;
                    
                    // Validate text length (max 5000 characters)
                    if (strlen($etat_logement) > 5000) {
                        $_SESSION['error'] = "La description de l'état du logement est trop longue (max 5000 caractères).";
                        header('Location: logements.php');
                        exit;
                    }
                    
                    $stmt = $pdo->prepare("
                        UPDATE logements SET 
                            default_cles_appartement = ?,
                            default_cles_boite_lettres = ?,
                            default_etat_logement = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $cles_appartement,
                        $cles_boite_lettres,
                        $etat_logement,
                        $_POST['logement_id']
                    ]);
                    $_SESSION['success'] = "Valeurs par défaut enregistrées avec succès";
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Erreur lors de l'enregistrement des valeurs par défaut.";
                    error_log("Erreur set defaults logement: " . $e->getMessage());
                }
                break;
        }
        header('Location: logements.php');
        exit;
    }
}

// Get filters
$statut_filter = isset($_GET['statut']) ? $_GET['statut'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Map UI status values to database values
$statut_ui_to_db_map = [
    'Disponible' => 'disponible',
    'Réservé' => 'reserve',
    'Loué' => 'en_location',
    'Maintenance' => 'maintenance',
    'Indisponible' => 'indisponible'
];


// Convert UI status to database status for query
$statut_db = '';
if ($statut_filter && isset($statut_ui_to_db_map[$statut_filter])) {
    $statut_db = $statut_ui_to_db_map[$statut_filter];
}

// Build query - explicitly select columns we need
$sql = "SELECT l.id, l.reference, l.adresse, l.type, l.surface, l.loyer, l.charges, 
        l.depot_garantie, l.parking, l.statut, l.date_disponibilite, l.created_at, l.updated_at,
        l.lien_externe, l.type_contrat, COALESCE(l.duree_garantie, 1) as duree_garantie,
        COALESCE(l.default_cles_appartement, 2) as default_cles_appartement,
        COALESCE(l.default_cles_boite_lettres, 1) as default_cles_boite_lettres,
        l.default_etat_logement,
        (SELECT c.id FROM contrats c 
         WHERE c.logement_id = l.id 
         AND c.statut IN ('actif', 'signe', 'valide')
         AND (c.date_prise_effet IS NULL OR c.date_prise_effet <= CURDATE())
         ORDER BY c.date_creation DESC 
         LIMIT 1) as dernier_contrat_id
        FROM logements l WHERE 1=1";
$params = [];

if ($statut_db) {
    $sql .= " AND statut = ?";
    $params[] = $statut_db;
}

if ($search) {
    $sql .= " AND (reference LIKE ? OR adresse LIKE ? OR type LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$sql .= " ORDER BY reference ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Map database status to UI status for display
$statutMap = [
    'disponible' => 'Disponible',
    'reserve' => 'Réservé',
    'en_location' => 'Loué',
    'maintenance' => 'Maintenance',
    'indisponible' => 'Indisponible'
];

foreach ($logements as &$logement) {
    $logement['statut_ui'] = $statutMap[$logement['statut']] ?? ucfirst($logement['statut']);
}
unset($logement); // Important: unset reference to prevent issues with subsequent foreach loops

// Get statistics
$stats = [
    'total' => $pdo->query("SELECT COUNT(*) FROM logements")->fetchColumn(),
    'disponible' => $pdo->query("SELECT COUNT(*) FROM logements WHERE statut = 'disponible'")->fetchColumn(),
    'reserve' => $pdo->query("SELECT COUNT(*) FROM logements WHERE statut = 'reserve'")->fetchColumn(),
    'loue' => $pdo->query("SELECT COUNT(*) FROM logements WHERE statut = 'en_location'")->fetchColumn(),
    'maintenance' => $pdo->query("SELECT COUNT(*) FROM logements WHERE statut = 'maintenance'")->fetchColumn()
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Logements - Admin MyInvest</title>
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
        .status-disponible { background: #d4edda; color: #155724; }
        .status-loue { background: #fff3cd; color: #856404; }
        .status-maintenance { background: #f8d7da; color: #721c24; }
        .status-reserve { background: #d1ecf1; color: #0c5460; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <h4>Gestion des Logements</h4>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLogementModal">
                    <i class="bi bi-plus-circle"></i> Ajouter un logement
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

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Total Logements</div>
                            <div class="number"><?php echo $stats['total']; ?></div>
                        </div>
                        <i class="bi bi-building" style="font-size: 2rem; color: #007bff;"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Disponibles</div>
                            <div class="number text-success"><?php echo $stats['disponible']; ?></div>
                        </div>
                        <i class="bi bi-check-circle" style="font-size: 2rem; color: #28a745;"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Loués</div>
                            <div class="number text-warning"><?php echo $stats['loue']; ?></div>
                        </div>
                        <i class="bi bi-house-check" style="font-size: 2rem; color: #ffc107;"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Maintenance</div>
                            <div class="number text-danger"><?php echo $stats['maintenance']; ?></div>
                        </div>
                        <i class="bi bi-tools" style="font-size: 2rem; color: #dc3545;"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="table-card mb-3">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Rechercher (référence, adresse, type...)" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <select name="statut" class="form-select">
                        <option value="">Tous les statuts</option>
                        <option value="Disponible" <?php echo $statut_filter === 'Disponible' ? 'selected' : ''; ?>>Disponible</option>
                        <option value="Réservé" <?php echo $statut_filter === 'Réservé' ? 'selected' : ''; ?>>Réservé</option>
                        <option value="Loué" <?php echo $statut_filter === 'Loué' ? 'selected' : ''; ?>>Loué</option>
                        <option value="Maintenance" <?php echo $statut_filter === 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> Filtrer
                    </button>
                </div>
                <div class="col-md-3">
                    <a href="logements.php" class="btn btn-secondary w-100">
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
                            <th>Adresse</th>
                            <th>Type</th>
                            <th>Surface</th>
                            <th>Loyer</th>
                            <th>Charges</th>
                            <th>Dépôt</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logements as $logement): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($logement['reference']); ?></strong></td>
                            <td>
                                <?php echo htmlspecialchars($logement['adresse']); ?>
                            </td>
                            <td><?php echo htmlspecialchars($logement['type']); ?></td>
                            <td><?php echo htmlspecialchars($logement['surface']); ?> m²</td>
                            <td><?php echo number_format($logement['loyer'], 0, ',', ' '); ?> €</td>
                            <td><?php echo number_format($logement['charges'], 0, ',', ' '); ?> €</td>
                            <td><?php echo number_format($logement['depot_garantie'], 0, ',', ' '); ?> €</td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($logement['statut']); ?>">
                                    <?php echo htmlspecialchars($logement['statut_ui']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary edit-btn" 
                                            data-id="<?php echo $logement['id']; ?>"
                                            data-reference="<?php echo htmlspecialchars($logement['reference']); ?>"
                                            data-adresse="<?php echo htmlspecialchars($logement['adresse']); ?>"
                                            data-type="<?php echo htmlspecialchars($logement['type']); ?>"
                                            data-surface="<?php echo $logement['surface']; ?>"
                                            data-loyer="<?php echo $logement['loyer']; ?>"
                                            data-charges="<?php echo $logement['charges']; ?>"
                                            data-depot="<?php echo $logement['depot_garantie']; ?>"
                                            data-parking="<?php echo htmlspecialchars($logement['parking']); ?>"
                                            data-statut="<?php echo htmlspecialchars($logement['statut_ui']); ?>"
                                            data-date-disponibilite="<?php echo htmlspecialchars($logement['date_disponibilite'] ?? ''); ?>"
                                            data-lien-externe="<?php echo htmlspecialchars($logement['lien_externe'] ?? ''); ?>"
                                            data-type-contrat="<?php echo htmlspecialchars($logement['type_contrat'] ?? 'meuble'); ?>"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#editLogementModal">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <a href="logement-detail.php?id=<?php echo $logement['id']; ?>"
                                       class="btn btn-outline-primary btn-sm"
                                       title="Fiche complète du logement">
                                        <i class="bi bi-building"></i>
                                    </a>
                                    <a href="<?php echo htmlspecialchars(rtrim($config['SITE_URL'], '/') . '/logement.php?ref=' . md5($logement['reference'])); ?>"
                                       class="btn btn-outline-info btn-sm"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       title="Voir la page publique">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <button class="btn btn-outline-secondary btn-sm copy-candidature-btn"
                                            data-url="<?php echo htmlspecialchars(rtrim($config['SITE_URL'], '/') . '/candidature/?ref=' . md5($logement['reference'])); ?>"
                                            title="Copier le lien de candidature">
                                        <i class="bi bi-person-plus"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm"
                                            data-id="<?php echo $logement['id']; ?>"
                                            data-reference="<?php echo htmlspecialchars($logement['reference']); ?>"
                                            data-default-cles-appartement="<?php echo $logement['default_cles_appartement']; ?>"
                                            data-default-cles-boite-lettres="<?php echo $logement['default_cles_boite_lettres']; ?>"
                                            data-default-etat-logement="<?php echo htmlspecialchars($logement['default_etat_logement'] ?? ''); ?>"
                                            data-bs-toggle="modal"
                                            data-bs-target="#setDefaultsModal"
                                            title="Définir les valeurs par défaut">
                                        <i class="bi bi-gear"></i>
                                    </button>
                                    <a href="manage-inventory-equipements.php?logement_id=<?php echo $logement['id']; ?>" 
                                       class="btn btn-outline-success btn-sm"
                                       title="Définir l'inventaire">
                                        <i class="bi bi-box-seam"></i>
                                    </a>
                                    <?php if ($logement['dernier_contrat_id']): ?>
                                        <a href="gestion-loyers.php?contrat_id=<?php echo $logement['dernier_contrat_id']; ?>" 
                                           class="btn btn-outline-warning btn-sm"
                                           title="Gestion du loyer">
                                            <i class="bi bi-cash-stack"></i>
                                        </a>
                                    <?php endif; ?>
                                    <button class="btn btn-outline-danger delete-btn"
                                            data-id="<?php echo $logement['id']; ?>"
                                            data-reference="<?php echo htmlspecialchars($logement['reference']); ?>"
                                            data-bs-toggle="modal"
                                            data-bs-target="#deleteLogementModal">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logements)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox" style="font-size: 48px;"></i>
                                <p class="mt-3">Aucun logement trouvé</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add Logement Modal -->
    <div class="modal fade" id="addLogementModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ajouter un Logement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="logements.php" method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Référence *</label>
                                <input type="text" name="reference" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Type *</label>
                                <select name="type" class="form-select" required>
                                    <option value="">Sélectionner...</option>
                                    <option value="T1 Bis">T1 Bis</option>
                                    <option value="T2">T2</option>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Adresse *</label>
                                <input type="text" name="adresse" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Surface (m²) *</label>
                                <input type="number" step="0.01" name="surface" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Loyer (€) *</label>
                                <input type="number" step="0.01" name="loyer" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Charges (€) *</label>
                                <input type="number" step="0.01" name="charges" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Dépôt de garantie (€) *</label>
                                <input type="number" step="0.01" name="depot_garantie" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Parking</label>
                                <select name="parking" class="form-select">
                                    <option value="Aucun">Aucun</option>
                                    <option value="1 place">1 place</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Type de contrat *</label>
                                <select name="type_contrat" class="form-select" required>
                                    <option value="meuble">Meublé</option>
                                    <option value="non_meuble">Non meublé</option>
                                    <option value="sur_mesure">Sur mesure</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Date de disponibilité</label>
                                <input type="date" name="date_disponibilite" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Lien externe (annonce sur un autre site)</label>
                                <input type="url" name="lien_externe" class="form-control" placeholder="https://...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Total mensuel (calculé automatiquement)</label>
                                <input type="text" class="form-control" id="add_total_mensuel" readonly disabled placeholder="Loyer + Charges">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Revenus requis (calculé automatiquement)</label>
                                <input type="text" class="form-control" id="add_revenus_requis" readonly disabled placeholder="Total mensuel × 3">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Ajouter</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Logement Modal -->
    <div class="modal fade" id="editLogementModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Modifier le Logement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="logements.php" method="POST" id="editForm">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="logement_id" id="edit_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Référence *</label>
                                <input type="text" name="reference" id="edit_reference" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Type *</label>
                                <select name="type" id="edit_type" class="form-select" required>
                                    <option value="">Sélectionner...</option>
                                    <option value="T1 Bis">T1 Bis</option>
                                    <option value="T2">T2</option>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Adresse *</label>
                                <input type="text" name="adresse" id="edit_adresse" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Surface (m²) *</label>
                                <input type="number" step="0.01" name="surface" id="edit_surface" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Loyer (€) *</label>
                                <input type="number" step="0.01" name="loyer" id="edit_loyer" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Charges (€) *</label>
                                <input type="number" step="0.01" name="charges" id="edit_charges" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Dépôt de garantie (€) *</label>
                                <input type="number" step="0.01" name="depot_garantie" id="edit_depot" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Parking</label>
                                <select name="parking" id="edit_parking" class="form-select">
                                    <option value="Aucun">Aucun</option>
                                    <option value="1 place">1 place</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Type de contrat *</label>
                                <select name="type_contrat" id="edit_type_contrat" class="form-select" required>
                                    <option value="meuble">Meublé</option>
                                    <option value="non_meuble">Non meublé</option>
                                    <option value="sur_mesure">Sur mesure</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Statut *</label>
                                <select name="statut" id="edit_statut" class="form-select">
                                    <option value="Disponible">Disponible</option>
                                    <option value="Réservé">Réservé</option>
                                    <option value="Loué">Loué</option>
                                    <option value="Maintenance">Maintenance</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Date de disponibilité</label>
                                <input type="date" name="date_disponibilite" id="edit_date_disponibilite" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Lien externe (annonce sur un autre site)</label>
                                <input type="url" name="lien_externe" id="edit_lien_externe" class="form-control" placeholder="https://...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Total mensuel (calculé automatiquement)</label>
                                <input type="text" class="form-control" id="edit_total_mensuel" readonly disabled placeholder="Loyer + Charges">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Revenus requis (calculé automatiquement)</label>
                                <input type="text" class="form-control" id="edit_revenus_requis" readonly disabled placeholder="Total mensuel × 3">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteLogementModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmer la Suppression</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="logements.php" method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="logement_id" id="delete_id">
                    <div class="modal-body">
                        <p>Êtes-vous sûr de vouloir supprimer le logement <strong id="delete_reference"></strong> ?</p>
                        <p class="text-danger"><small>Cette action est irréversible.</small></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-danger">Supprimer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Set Defaults Modal -->
    <div class="modal fade" id="setDefaultsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Définir les Valeurs par Défaut - <span id="defaults_reference"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="logements.php" method="POST">
                    <input type="hidden" name="action" value="set_defaults">
                    <input type="hidden" name="logement_id" id="defaults_id">
                    <div class="modal-body">
                        <p class="text-muted mb-4">
                            <i class="bi bi-info-circle"></i> 
                            Ces valeurs seront utilisées par défaut lors de la création d'un état des lieux d'entrée pour ce logement.
                        </p>
                        
                        <!-- Remise des clés -->
                        <div class="mb-4">
                            <h6 class="mb-3"><i class="bi bi-key"></i> Remise des clés</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Nombre de clés d'appartement</label>
                                    <input type="number" name="default_cles_appartement" id="defaults_cles_appartement" 
                                           class="form-control" min="0" max="100" value="2" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Nombre de clés de boîte aux lettres</label>
                                    <input type="number" name="default_cles_boite_lettres" id="defaults_cles_boite_lettres" 
                                           class="form-control" min="0" max="100" value="1" required>
                                </div>
                            </div>
                        </div>

                        <!-- Description du logement - État d'entrée -->
                        <div class="mb-4">
                            <h6 class="mb-3"><i class="bi bi-house-door"></i> Description du logement - État d'entrée</h6>
                            
                            <div class="mb-3">
                                <label class="form-label">Description de l'état du logement</label>
                                <textarea name="default_etat_logement" id="defaults_etat_logement" 
                                          class="form-control" rows="4" maxlength="5000">• Revêtement de sol : parquet très bon état d'usage
• Murs : peintures très bon état
• Plafond : peintures très bon état
• Installations électriques et plomberie : fonctionnelles</textarea>
                                <small class="form-text text-muted">Modifiez le texte ci-dessus selon vos besoins</small>
                            </div>
                        </div>

                        <div class="alert alert-info">
                            <i class="bi bi-lightbulb"></i> 
                            <strong>Note :</strong> Les relevés des compteurs ne sont pas configurables ici car ils doivent être saisis manuellement lors de chaque état des lieux.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Enregistrer les valeurs par défaut
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Copy candidature link button
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.copy-candidature-btn');
            if (!btn) return;
            var url = btn.dataset.url;
            if (!url) return;
            navigator.clipboard.writeText(url).then(function() {
                var icon = btn.querySelector('i');
                if (icon) {
                    var prev = icon.className;
                    icon.className = 'bi bi-check-lg text-success';
                    btn.title = 'Lien copié !';
                    setTimeout(function() {
                        icon.className = prev;
                        btn.title = 'Copier le lien de candidature';
                    }, 2000);
                }
            }).catch(function() {
                prompt('Copiez ce lien :', url);
            });
        });

        const textareaDefaults = {
            etatLogement: document.getElementById('defaults_etat_logement')?.value.trim() || '',
        };
        
        // Calculate total mensuel and revenus requis
        function calculateTotals(prefix) {
            const loyer = parseFloat(document.querySelector(`[name="loyer"]${prefix ? '#' + prefix + '_loyer' : ''}`).value) || 0;
            const charges = parseFloat(document.querySelector(`[name="charges"]${prefix ? '#' + prefix + '_charges' : ''}`).value) || 0;
            const totalMensuel = loyer + charges;
            const revenusRequis = totalMensuel * 3;
            
            const totalField = document.getElementById(prefix + '_total_mensuel');
            const revenusField = document.getElementById(prefix + '_revenus_requis');
            
            if (totalField) {
                totalField.value = totalMensuel.toFixed(2) + ' €';
            }
            if (revenusField) {
                revenusField.value = revenusRequis.toFixed(2) + ' €';
            }
        }
        
        // Add event listeners for add form
        ['loyer', 'charges'].forEach(field => {
            const input = document.querySelector(`#addLogementModal [name="${field}"]`);
            if (input) {
                input.addEventListener('input', () => calculateTotals('add'));
            }
        });
        
        // Add event listeners for edit form
        ['loyer', 'charges'].forEach(field => {
            const input = document.getElementById(`edit_${field}`);
            if (input) {
                input.addEventListener('input', () => calculateTotals('edit'));
            }
        });
        
        // Edit button handler
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('edit_id').value = this.dataset.id;
                document.getElementById('edit_reference').value = this.dataset.reference;
                document.getElementById('edit_adresse').value = this.dataset.adresse;
                document.getElementById('edit_type').value = this.dataset.type;
                document.getElementById('edit_surface').value = this.dataset.surface;
                document.getElementById('edit_loyer').value = this.dataset.loyer;
                document.getElementById('edit_charges').value = this.dataset.charges;
                document.getElementById('edit_depot').value = this.dataset.depot;
                document.getElementById('edit_parking').value = this.dataset.parking;
                document.getElementById('edit_type_contrat').value = this.dataset.typeContrat || 'meuble';
                document.getElementById('edit_statut').value = this.dataset.statut;
                document.getElementById('edit_date_disponibilite').value = this.dataset.dateDisponibilite || '';
                document.getElementById('edit_lien_externe').value = this.dataset.lienExterne || '';
                
                // Calculate totals for edit form
                calculateTotals('edit');
            });
        });

        // Delete button handler
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('delete_id').value = this.dataset.id;
                document.getElementById('delete_reference').textContent = this.dataset.reference;
            });
        });
        
        // Defaults button handler
        document.querySelectorAll('.defaults-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Set hidden fields
                document.getElementById('defaults_id').value = this.dataset.id;
                document.getElementById('defaults_reference').textContent = this.dataset.reference;
                
                // Set form fields with current values
                document.getElementById('defaults_cles_appartement').value = this.dataset.defaultClesAppartement || '2';
                document.getElementById('defaults_cles_boite_lettres').value = this.dataset.defaultClesBoiteLettres || '1';
                
                // For textareas, use stored value if available, otherwise use the defaults captured at page load
                document.getElementById('defaults_etat_logement').value = this.dataset.defaultEtatLogement || textareaDefaults.etatLogement;
            });
        });
        
        // Reset add form when modal is opened
        const addModal = document.getElementById('addLogementModal');
        if (addModal) {
            addModal.addEventListener('show.bs.modal', function () {
                // Reset the form
                const form = addModal.querySelector('form');
                if (form) {
                    form.reset();
                }
                // Clear calculated fields
                document.getElementById('add_total_mensuel').value = '';
                document.getElementById('add_revenus_requis').value = '';
            });
        }
    </script>
</body>
</html>
