<?php
/**
 * Page publique — Liste des logements disponibles
 * My Invest Immobilier
 *
 * Affiche tous les logements disponibles avec filtrage par référence exacte.
 *
 * URL: /logements.php
 * URL: /logements.php?ref=REF123
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';
$siteUrl     = rtrim($config['SITE_URL'], '/');

// Filtre par référence exacte (case-insensitive)
$filterRef = isset($_GET['ref']) ? trim($_GET['ref']) : '';

try {
    if ($filterRef !== '') {
        $stmt = $pdo->prepare("
            SELECT l.id, l.reference, l.adresse, l.type, l.surface, l.loyer, l.charges,
                   l.depot_garantie, l.parking, l.date_disponibilite, l.description,
                   l.equipements, l.statut,
                   (SELECT filename FROM logements_photos WHERE logement_id = l.id ORDER BY ordre ASC, id ASC LIMIT 1) AS photo_principale
            FROM logements l
            WHERE l.deleted_at IS NULL AND LOWER(l.reference) = LOWER(?)
            ORDER BY l.reference ASC
        ");
        $stmt->execute([$filterRef]);
    } else {
        $stmt = $pdo->query("
            SELECT l.id, l.reference, l.adresse, l.type, l.surface, l.loyer, l.charges,
                   l.depot_garantie, l.parking, l.date_disponibilite, l.description,
                   l.equipements, l.statut,
                   (SELECT filename FROM logements_photos WHERE logement_id = l.id ORDER BY ordre ASC, id ASC LIMIT 1) AS photo_principale
            FROM logements l
            WHERE l.deleted_at IS NULL AND l.statut = 'disponible'
            ORDER BY l.reference ASC
        ");
    }
    $logements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('logements.php DB error: ' . $e->getMessage());
    $logements = [];
}

$statutLabels = [
    'disponible'   => ['Disponible',   'success'],
    'en_location'  => ['Loué',         'secondary'],
    'maintenance'  => ['Indisponible', 'danger'],
    'indisponible' => ['Indisponible', 'secondary'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logements disponibles — <?php echo htmlspecialchars($companyName); ?></title>
    <meta name="description" content="Découvrez nos logements disponibles à la location.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root { --primary: #1a56db; --primary-light: #e8f0fe; }
        body { background: #f5f7fa; font-family: 'Segoe UI', system-ui, sans-serif; color: #1a1a2e; }
        .site-header { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 1rem 0; }
        .site-header .brand { font-size: 1.1rem; font-weight: 700; color: var(--primary); text-decoration: none; }
        .hero-banner {
            background: linear-gradient(135deg, #1a56db 0%, #0e3a8a 100%);
            color: #fff;
            padding: 3rem 0 2.5rem;
        }
        .hero-banner h1 { font-weight: 800; }
        .search-form { max-width: 480px; }
        .search-form .form-control { border-radius: 8px 0 0 8px; }
        .search-form .btn { border-radius: 0 8px 8px 0; }
        .logement-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,.06);
            overflow: hidden;
            transition: transform .15s, box-shadow .15s;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .logement-card:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(0,0,0,.1); }
        .card-photo {
            position: relative;
            width: 100%;
            padding-top: 56.25%;
            background: #e5e7eb;
            overflow: hidden;
        }
        .card-photo img, .card-photo .no-photo {
            position: absolute; top: 0; left: 0;
            width: 100%; height: 100%;
            object-fit: cover;
        }
        .card-photo .no-photo {
            display: flex; align-items: center; justify-content: center;
            color: #9ca3af; font-size: 2.5rem;
        }
        .card-ref-badge {
            position: absolute; bottom: 8px; left: 8px;
            background: rgba(0,0,0,.65);
            color: #fff;
            font-family: monospace; font-size: .8rem; font-weight: 700;
            padding: .2em .6em; border-radius: 6px;
        }
        .card-body-custom { padding: 1.2rem; flex: 1; display: flex; flex-direction: column; }
        .card-price { font-size: 1.5rem; font-weight: 800; color: var(--primary); }
        .card-charges { font-size: .85rem; color: #6b7280; }
        .card-address { font-size: .9rem; color: #374151; font-weight: 500; margin: .4rem 0 .6rem; }
        .card-tags { display: flex; flex-wrap: wrap; gap: .3rem; margin-bottom: .8rem; }
        .card-tag { background: var(--primary-light); color: var(--primary); border-radius: 5px; padding: .2em .6em; font-size: .78rem; font-weight: 500; }
        .btn-voir { margin-top: auto; }
        .site-footer { background: #fff; border-top: 1px solid #e5e7eb; padding: 1.5rem 0; font-size: .85rem; color: #6b7280; }
        .no-results { text-align: center; padding: 4rem 0; }
        .no-results i { font-size: 4rem; color: #d1d5db; }
    </style>
</head>
<body>

<!-- Header -->
<header class="site-header">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between">
            <a href="<?php echo htmlspecialchars($siteUrl . '/logements.php'); ?>" class="brand">
                <i class="bi bi-building me-1"></i><?php echo htmlspecialchars($companyName); ?>
            </a>
        </div>
    </div>
</header>

<!-- Hero banner with search -->
<div class="hero-banner">
    <div class="container">
        <h1 class="mb-2">Nos logements disponibles</h1>
        <p class="opacity-80 mb-4">Trouvez votre prochain logement parmi nos offres.</p>
        <form method="GET" action="logements.php" class="search-form d-flex">
            <input type="text" name="ref" class="form-control form-control-lg"
                   value="<?php echo htmlspecialchars($filterRef); ?>"
                   placeholder="Recherche par référence exacte (ex: T2-PARIS-01)">
            <button type="submit" class="btn btn-warning btn-lg px-3">
                <i class="bi bi-search"></i>
            </button>
        </form>
        <?php if ($filterRef !== ''): ?>
        <a href="logements.php" class="btn btn-outline-light btn-sm mt-2">
            <i class="bi bi-x me-1"></i>Réinitialiser le filtre
        </a>
        <?php endif; ?>
    </div>
</div>

<main class="container py-5">

    <?php if ($filterRef !== '' && empty($logements)): ?>
    <div class="no-results">
        <i class="bi bi-search d-block mb-3"></i>
        <h4 class="text-muted">Aucun logement trouvé pour la référence « <?php echo htmlspecialchars($filterRef); ?> »</h4>
        <a href="logements.php" class="btn btn-primary mt-3">Voir tous les logements</a>
    </div>

    <?php elseif (empty($logements)): ?>
    <div class="no-results">
        <i class="bi bi-house-slash d-block mb-3"></i>
        <h4 class="text-muted">Aucun logement disponible pour le moment</h4>
        <p class="text-muted">Revenez bientôt, de nouveaux logements sont régulièrement ajoutés.</p>
    </div>

    <?php else: ?>
    <?php
    $count = count($logements);
    $plural = $count > 1 ? 's' : '';
    if ($filterRef !== '') {
        $resultLabel = $count . ' logement' . $plural . ' trouvé' . $plural . ' pour « ' . htmlspecialchars($filterRef) . ' »';
    } else {
        $resultLabel = $count . ' logement' . $plural . ' disponible' . $plural;
    }
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h5 mb-0"><?php echo $resultLabel; ?></h2>
    </div>
    <div class="row g-4">
        <?php foreach ($logements as $l): ?>
        <?php
        $sl          = $statutLabels[$l['statut']] ?? [$l['statut'], 'secondary'];
        $totalMens   = (float)$l['loyer'] + (float)$l['charges'];
        $lienDetail  = $siteUrl . '/logement.php?ref=' . md5($l['reference']);
        $lienCandid  = $siteUrl . '/candidature/?ref=' . md5($l['reference']);
        ?>
        <div class="col-sm-6 col-lg-4">
            <div class="logement-card">
                <div class="card-photo">
                    <?php if ($l['photo_principale']): ?>
                    <img src="<?php echo htmlspecialchars($siteUrl . '/uploads/logements/' . $l['photo_principale']); ?>"
                         alt="<?php echo htmlspecialchars($l['reference']); ?>" loading="lazy">
                    <?php else: ?>
                    <div class="no-photo"><i class="bi bi-building"></i></div>
                    <?php endif; ?>
                    <span class="card-ref-badge"><i class="bi bi-hash"></i><?php echo htmlspecialchars($l['reference']); ?></span>
                    <span style="position:absolute;top:8px;right:8px;" class="badge bg-<?php echo $sl[1]; ?>"><?php echo $sl[0]; ?></span>
                </div>
                <div class="card-body-custom">
                    <div>
                        <div class="card-price">
                            <?php echo number_format((float)$l['loyer'], 0, ',', ' '); ?> €<span style="font-size:.85rem;font-weight:400;color:#6b7280;">/mois</span>
                        </div>
                        <?php if ((float)$l['charges'] > 0): ?>
                        <div class="card-charges">+ <?php echo number_format((float)$l['charges'], 0, ',', ' '); ?> € charges</div>
                        <?php endif; ?>
                        <div class="card-address">
                            <i class="bi bi-geo-alt me-1 text-muted"></i><?php echo htmlspecialchars($l['adresse']); ?>
                        </div>
                        <div class="card-tags">
                            <?php if ($l['type']): ?>
                            <span class="card-tag"><i class="bi bi-house me-1"></i><?php echo htmlspecialchars($l['type']); ?></span>
                            <?php endif; ?>
                            <?php if ($l['surface']): ?>
                            <span class="card-tag"><i class="bi bi-rulers me-1"></i><?php echo htmlspecialchars($l['surface']); ?> m²</span>
                            <?php endif; ?>
                            <?php if ($l['parking'] !== 'Aucun'): ?>
                            <span class="card-tag"><i class="bi bi-p-square me-1"></i><?php echo htmlspecialchars($l['parking']); ?></span>
                            <?php endif; ?>
                            <?php if ($l['date_disponibilite'] && $l['statut'] === 'disponible'): ?>
                            <span class="card-tag"><i class="bi bi-calendar me-1"></i>Dès le <?php echo date('d/m/Y', strtotime($l['date_disponibilite'])); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex gap-2 btn-voir">
                        <a href="<?php echo htmlspecialchars($lienDetail); ?>"
                           class="btn btn-outline-primary btn-sm flex-grow-1">
                            <i class="bi bi-eye me-1"></i>Voir la fiche
                        </a>
                        <?php if ($l['statut'] === 'disponible'): ?>
                        <a href="<?php echo htmlspecialchars($lienCandid); ?>"
                           class="btn btn-success btn-sm flex-grow-1">
                            <i class="bi bi-person-plus me-1"></i>Candidater
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>

<!-- Footer -->
<footer class="site-footer">
    <div class="container text-center">
        <p class="mb-0">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($companyName); ?> — Tous droits réservés</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
