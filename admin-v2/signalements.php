<?php
/**
 * Liste des signalements d'anomalie — Interface admin
 * My Invest Immobilier
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

// ── Traitement de la configuration ───────────────────────────────────────────
$configSuccess = false;
$configError   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_signalement_config') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $configError = 'Token CSRF invalide. Veuillez recharger la page.';
    } else {
        $keys = [
            'texte_defaut_responsabilite_locataire',
            'texte_defaut_responsabilite_proprietaire',
        ];
        foreach ($keys as $cle) {
            $valeur = trim($_POST[$cle] ?? '');
            $stmt = $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = ?");
            $stmt->execute([$valeur, $cle]);
            if ($stmt->rowCount() === 0) {
                // Insert if not existing yet
                $pdo->prepare("INSERT INTO parametres (cle, valeur, type, description, groupe) VALUES (?, ?, 'string', ?, 'signalement') ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), updated_at = NOW()")
                    ->execute([
                        $cle,
                        $valeur,
                        $cle === 'texte_defaut_responsabilite_locataire'
                            ? 'Texte par défaut du commentaire de responsabilité — Locataire'
                            : 'Texte par défaut du commentaire de responsabilité — Propriétaire',
                    ]);
            }
        }
        $configSuccess = true;
        header('Location: signalements.php?tab=config&saved=1');
        exit;
    }
}

// Charger les textes par défaut depuis la base
$configParams = [];
try {
    $paramStmt = $pdo->query("SELECT cle, valeur FROM parametres WHERE cle IN ('texte_defaut_responsabilite_locataire','texte_defaut_responsabilite_proprietaire')");
    foreach ($paramStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $configParams[$row['cle']] = $row['valeur'];
    }
} catch (Exception $e) {
    // Table absente si migration non appliquée
}
$activeTab = $_GET['tab'] ?? 'list';
$savedConfig = isset($_GET['saved']);

// Filtres
$statutFilter   = $_GET['statut']   ?? '';
$prioriteFilter = $_GET['priorite'] ?? '';
$search         = trim($_GET['search'] ?? '');

$where = ['1=1'];
$params = [];

if ($statutFilter && in_array($statutFilter, ['nouveau', 'en_cours', 'pris_en_charge', 'sur_place', 'en_attente', 'reporte', 'resolu', 'clos'])) {
    $where[] = 'sig.statut = ?';
    $params[] = $statutFilter;
}
if ($prioriteFilter && in_array($prioriteFilter, ['urgent', 'normal'])) {
    $where[] = 'sig.priorite = ?';
    $params[] = $prioriteFilter;
}
if ($search) {
    $where[] = '(sig.titre LIKE ? OR sig.reference LIKE ? OR l.adresse LIKE ? OR CONCAT(loc.prenom, \' \', loc.nom) LIKE ?)';
    $s = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $search) . '%';
    $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
}

$whereClause = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT sig.id, sig.reference, sig.titre, sig.priorite, sig.statut,
           sig.date_signalement, sig.date_cloture,
           l.adresse, l.reference as logement_ref,
           CONCAT(loc.prenom, ' ', loc.nom) as locataire_nom,
           (SELECT COUNT(*) FROM signalements_photos sp WHERE sp.signalement_id = sig.id) as nb_photos
    FROM signalements sig
    INNER JOIN logements l ON sig.logement_id = l.id
    LEFT JOIN locataires loc ON sig.locataire_id = loc.id
    WHERE $whereClause
    ORDER BY
        CASE sig.priorite WHEN 'urgent' THEN 0 ELSE 1 END,
        CASE sig.statut WHEN 'nouveau' THEN 0 WHEN 'en_cours' THEN 1 WHEN 'pris_en_charge' THEN 2 WHEN 'sur_place' THEN 3 WHEN 'en_attente' THEN 4 WHEN 'reporte' THEN 5 WHEN 'resolu' THEN 6 ELSE 7 END,
        sig.date_signalement DESC
");
$stmt->execute($params);
$signalements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$stats = [
    'total'     => $pdo->query("SELECT COUNT(*) FROM signalements")->fetchColumn(),
    'nouveau'   => $pdo->query("SELECT COUNT(*) FROM signalements WHERE statut = 'nouveau'")->fetchColumn(),
    'en_cours'  => $pdo->query("SELECT COUNT(*) FROM signalements WHERE statut = 'en_cours'")->fetchColumn(),
    'urgents'   => $pdo->query("SELECT COUNT(*) FROM signalements WHERE priorite = 'urgent' AND statut NOT IN ('resolu','clos')")->fetchColumn(),
];

$statutLabels = [
    'nouveau'         => ['label' => 'Nouveau',         'class' => 'bg-primary'],
    'en_cours'        => ['label' => 'En cours',        'class' => 'bg-warning text-dark'],
    'pris_en_charge'  => ['label' => 'Pris en charge',  'class' => 'bg-info text-dark'],
    'sur_place'       => ['label' => 'Sur place',       'class' => 'bg-warning text-dark'],
    'en_attente'      => ['label' => 'En attente',      'class' => 'bg-info text-dark'],
    'reporte'         => ['label' => 'Reporté',         'class' => 'bg-danger'],
    'resolu'          => ['label' => 'Résolu',          'class' => 'bg-success'],
    'clos'            => ['label' => 'Terminé',         'class' => 'bg-secondary'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signalements — Admin My Invest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .stat-card { background: #fff; border-radius: 10px; padding: 18px 22px; box-shadow: 0 2px 6px rgba(0,0,0,0.07); }
        .stat-card .number { font-size: 2rem; font-weight: 700; }
        .table-signalements th { white-space: nowrap; }
        .badge-urgent { background: #dc3545; }
        .badge-normal { background: #6c757d; }
        tr.priorite-urgent td:first-child { border-left: 4px solid #dc3545; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="container-fluid mt-4">

        <!-- En-tête -->
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h1><i class="bi bi-exclamation-triangle me-2"></i>Signalements d'anomalie</h1>
                <p class="text-muted mb-0">Gestion des tickets ouverts par les locataires</p>
            </div>
            <a href="contrats.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Contrats
            </a>
        </div>

        <?php if ($savedConfig): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle-fill me-2"></i>Configuration enregistrée avec succès.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($configError): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-circle me-2"></i><?php echo htmlspecialchars($configError); ?>
        </div>
        <?php endif; ?>

        <!-- Onglets -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab !== 'config' ? 'active' : ''; ?>" href="signalements.php">
                    <i class="bi bi-list-ul me-1"></i>Liste des signalements
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $activeTab === 'config' ? 'active' : ''; ?>" href="signalements.php?tab=config">
                    <i class="bi bi-gear me-1"></i>Configuration
                </a>
            </li>
        </ul>

        <?php if ($activeTab === 'config'): ?>
        <!-- ═══════════════════════════════════════════════════════════════════
             Onglet Configuration
        ═══════════════════════════════════════════════════════════════════ -->
        <div class="card">
            <div class="card-header">
                <i class="bi bi-gear me-2"></i>Configuration du signalement — Textes par défaut de la responsabilité
            </div>
            <div class="card-body">
                <p class="text-muted small mb-4">
                    Ces textes seront pré-remplis dans la fenêtre de confirmation de responsabilité
                    (signalement-detail.php) et inclus dans les emails envoyés au locataire via
                    la variable <code>{{commentaire_responsabilite}}</code>.
                    L'administrateur peut modifier le texte avant de valider.
                </p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    <input type="hidden" name="action" value="save_signalement_config">

                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-person me-1 text-warning"></i>
                            Texte par défaut — Responsabilité locataire
                        </label>
                        <textarea class="form-control" name="texte_defaut_responsabilite_locataire" rows="5"><?php
                            echo htmlspecialchars($configParams['texte_defaut_responsabilite_locataire'] ?? '');
                        ?></textarea>
                        <div class="form-text">Affiché quand la responsabilité est définie sur <strong>Locataire</strong>.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-house me-1 text-success"></i>
                            Texte par défaut — Responsabilité propriétaire
                        </label>
                        <textarea class="form-control" name="texte_defaut_responsabilite_proprietaire" rows="5"><?php
                            echo htmlspecialchars($configParams['texte_defaut_responsabilite_proprietaire'] ?? '');
                        ?></textarea>
                        <div class="form-text">Affiché quand la responsabilité est définie sur <strong>Propriétaire</strong>.</div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Enregistrer la configuration
                    </button>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- ═══════════════════════════════════════════════════════════════════
             Onglet Liste (défaut)
        ═══════════════════════════════════════════════════════════════════ -->

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
                    <div class="number text-primary"><?php echo $stats['nouveau']; ?></div>
                    <div class="text-muted small">Nouveaux</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card text-center">
                    <div class="number text-warning"><?php echo $stats['en_cours']; ?></div>
                    <div class="text-muted small">En cours</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card text-center">
                    <div class="number text-danger"><?php echo $stats['urgents']; ?></div>
                    <div class="text-muted small">Urgents actifs</div>
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Recherche</label>
                        <input type="text" class="form-control" name="search"
                               placeholder="Titre, référence, adresse, locataire..."
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
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
                        <label class="form-label small fw-semibold">Priorité</label>
                        <select class="form-select" name="priorite">
                            <option value="">Toutes</option>
                            <option value="urgent" <?php echo $prioriteFilter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                            <option value="normal" <?php echo $prioriteFilter === 'normal' ? 'selected' : ''; ?>>Normal</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-1"></i>Filtrer
                        </button>
                    </div>
                    <?php if ($statutFilter || $prioriteFilter || $search): ?>
                    <div class="col-md-2">
                        <a href="signalements.php" class="btn btn-outline-secondary w-100">
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
                <?php if (empty($signalements)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-check-circle" style="font-size:3rem;"></i>
                        <p class="mt-2">Aucun signalement trouvé.</p>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-signalements mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Référence</th>
                                <th>Titre</th>
                                <th>Logement</th>
                                <th>Locataire</th>
                                <th>Priorité</th>
                                <th>Statut</th>
                                <th>Date</th>
                                <th>Photos</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($signalements as $sig): ?>
                            <tr class="<?php echo $sig['priorite'] === 'urgent' ? 'priorite-urgent' : ''; ?>">
                                <td class="font-monospace small"><?php echo htmlspecialchars($sig['reference']); ?></td>
                                <td><?php echo htmlspecialchars($sig['titre']); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($sig['adresse']); ?></td>
                                <td class="small"><?php echo htmlspecialchars($sig['locataire_nom'] ?? '—'); ?></td>
                                <td>
                                    <?php if ($sig['priorite'] === 'urgent'): ?>
                                        <span class="badge badge-urgent text-white"><i class="bi bi-lightning-fill me-1"></i>Urgent</span>
                                    <?php else: ?>
                                        <span class="badge badge-normal text-white">Normal</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo $statutLabels[$sig['statut']]['class']; ?>">
                                        <?php echo $statutLabels[$sig['statut']]['label']; ?>
                                    </span>
                                </td>
                                <td class="small text-muted">
                                    <?php echo date('d/m/Y', strtotime($sig['date_signalement'])); ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($sig['nb_photos'] > 0): ?>
                                        <span class="badge bg-light text-dark border">
                                            <i class="bi bi-camera me-1"></i><?php echo $sig['nb_photos']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="signalement-detail.php?id=<?php echo $sig['id']; ?>"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye me-1"></i>Gérer
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

        <!-- Accès au formulaire de signalement -->
        <div class="card mt-4">
            <div class="card-header">
                <i class="bi bi-link-45deg me-2"></i>Formulaire de signalement locataire
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">
                    Les locataires peuvent signaler directement une anomalie en saisissant leur adresse email sur la page de signalement.
                    Aucun lien personnalisé à générer — l'identification se fait automatiquement par email.
                </p>
                <?php
                $signalementUrl = rtrim($config['SITE_URL'] ?? '', '/') . '/signalement/form.php';
                ?>
                <div class="input-group input-group-sm" style="max-width:480px;">
                    <input type="text" class="form-control font-monospace" id="sig-url-input"
                           value="<?php echo htmlspecialchars($signalementUrl); ?>" readonly>
                    <button class="btn btn-outline-secondary" type="button" onclick="
                        navigator.clipboard.writeText(document.getElementById('sig-url-input').value);
                        this.textContent='Copié !';
                        setTimeout(()=>{this.textContent='Copier';},2000);
                    ">Copier</button>
                </div>
            </div>
        </div>

        <?php endif; // end tab: list vs config ?>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
