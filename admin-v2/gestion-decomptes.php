<?php
/**
 * Gestion des décomptes d'intervention — Interface admin
 * My Invest Immobilier
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

// ── Filtres ────────────────────────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$statutFilter = $_GET['statut'] ?? '';

$where  = ['1=1'];
$params = [];

if ($statutFilter && in_array($statutFilter, ['brouillon', 'valide', 'facture_envoyee'], true)) {
    $where[] = 'd.statut = ?';
    $params[] = $statutFilter;
}
if ($search) {
    $where[] = '(d.reference LIKE ? OR sig.reference LIKE ? OR sig.titre LIKE ? OR COALESCE(cl.adresse, l.adresse) LIKE ?)';
    $s = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $search) . '%';
    $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
}
$whereClause = implode(' AND ', $where);

try {
    // Use contrat_logement for frozen adresse with fallback to logements
    $stmt = $pdo->prepare("
        SELECT d.id, d.reference, d.statut, d.montant_total, d.date_creation, d.date_validation,
               sig.id AS sig_id, sig.reference AS sig_reference, sig.titre AS sig_titre, sig.statut AS sig_statut,
               COALESCE(cl.adresse, l.adresse) AS adresse,
               CONCAT(loc.prenom, ' ', loc.nom) AS locataire_nom
        FROM signalements_decomptes d
        INNER JOIN signalements sig ON d.signalement_id = sig.id
        INNER JOIN logements l ON sig.logement_id = l.id
        LEFT JOIN contrat_logement cl ON cl.contrat_id = sig.contrat_id
        LEFT JOIN locataires loc ON sig.locataire_id = loc.id
        WHERE $whereClause
        ORDER BY d.date_creation DESC
    ");
    $stmt->execute($params);
    $decomptes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = [
        'total'          => $pdo->query("SELECT COUNT(*) FROM signalements_decomptes")->fetchColumn(),
        'brouillon'      => $pdo->query("SELECT COUNT(*) FROM signalements_decomptes WHERE statut = 'brouillon'")->fetchColumn(),
        'valide'         => $pdo->query("SELECT COUNT(*) FROM signalements_decomptes WHERE statut = 'valide'")->fetchColumn(),
        'facture_envoyee'=> $pdo->query("SELECT COUNT(*) FROM signalements_decomptes WHERE statut = 'facture_envoyee'")->fetchColumn(),
    ];
} catch (Exception $e) {
    // Table doesn't exist yet
    $decomptes = [];
    $stats     = ['total' => 0, 'brouillon' => 0, 'valide' => 0, 'facture_envoyee' => 0];
}

$statutLabels = [
    'brouillon'       => ['label' => 'Brouillon',        'class' => 'bg-secondary'],
    'valide'          => ['label' => 'Validé',           'class' => 'bg-success'],
    'facture_envoyee' => ['label' => 'Facture envoyée',  'class' => 'bg-primary'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Décomptes d'intervention — Admin My Invest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .stat-card { background: #fff; border-radius: 10px; padding: 18px 22px; box-shadow: 0 2px 6px rgba(0,0,0,0.07); }
        .stat-card .number { font-size: 2rem; font-weight: 700; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="container-fluid mt-4">

        <!-- En-tête -->
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h1><i class="bi bi-receipt me-2"></i>Décomptes d'intervention</h1>
                <p class="text-muted mb-0">Décomptes et factures liés aux signalements d'anomalie</p>
            </div>
            <a href="signalements.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Signalements
            </a>
        </div>

        <!-- Statistiques -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card text-center">
                    <div class="number text-primary"><?php echo $stats['total']; ?></div>
                    <div class="text-muted small">Total</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card text-center">
                    <div class="number text-secondary"><?php echo $stats['brouillon']; ?></div>
                    <div class="text-muted small">Brouillons</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card text-center">
                    <div class="number text-success"><?php echo $stats['valide']; ?></div>
                    <div class="text-muted small">Validés</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card text-center">
                    <div class="number text-info"><?php echo $stats['facture_envoyee']; ?></div>
                    <div class="text-muted small">Factures envoyées</div>
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Recherche</label>
                        <input type="text" class="form-control" name="search"
                               placeholder="Référence décompte, signalement, adresse..."
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Statut</label>
                        <select class="form-select" name="statut">
                            <option value="">Tous</option>
                            <?php foreach ($statutLabels as $v => $l): ?>
                                <option value="<?php echo $v; ?>" <?php echo $statutFilter === $v ? 'selected' : ''; ?>>
                                    <?php echo $l['label']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-1"></i>Filtrer
                        </button>
                    </div>
                    <?php if ($statutFilter || $search): ?>
                    <div class="col-md-2">
                        <a href="gestion-decomptes.php" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-x-circle me-1"></i>Réinitialiser
                        </a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Tableau -->
        <div class="card">
            <div class="card-body p-0">
                <?php if (empty($decomptes)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-receipt" style="font-size:3rem;"></i>
                        <p class="mt-2">Aucun décompte trouvé.</p>
                        <p class="small">Les décomptes sont créés depuis le détail d'un signalement.</p>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Référence décompte</th>
                                <th>Signalement</th>
                                <th>Logement</th>
                                <th>Locataire</th>
                                <th>Montant</th>
                                <th>Statut</th>
                                <th>Date</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($decomptes as $d): ?>
                            <tr>
                                <td class="font-monospace small"><?php echo htmlspecialchars($d['reference']); ?></td>
                                <td>
                                    <a href="signalement-detail.php?id=<?php echo $d['sig_id']; ?>" class="small text-decoration-none">
                                        <span class="font-monospace"><?php echo htmlspecialchars($d['sig_reference']); ?></span>
                                    </a><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($d['sig_titre']); ?></small>
                                </td>
                                <td class="small text-muted"><?php echo htmlspecialchars($d['adresse']); ?></td>
                                <td class="small"><?php echo htmlspecialchars($d['locataire_nom'] ?? '—'); ?></td>
                                <td class="fw-semibold"><?php echo number_format((float)$d['montant_total'], 2, ',', ' '); ?> €</td>
                                <td>
                                    <span class="badge <?php echo $statutLabels[$d['statut']]['class'] ?? 'bg-secondary'; ?>">
                                        <?php echo $statutLabels[$d['statut']]['label'] ?? $d['statut']; ?>
                                    </span>
                                </td>
                                <td class="small text-muted">
                                    <?php echo date('d/m/Y', strtotime($d['date_creation'])); ?>
                                </td>
                                <td>
                                    <a href="decompte-detail.php?id=<?php echo $d['id']; ?>"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil me-1"></i>Gérer
                                    </a>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
