<?php
require_once 'auth.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Get statistics
$stats = [];

// Total applications
$stmt = $pdo->query("SELECT COUNT(*) as total FROM candidatures");
$stats['total_candidatures'] = $stmt->fetch()['total'];

// By status
$stmt = $pdo->query("SELECT statut, COUNT(*) as count FROM candidatures GROUP BY statut");
while ($row = $stmt->fetch()) {
    $stats['statut_' . strtolower(str_replace(' ', '_', $row['statut']))] = $row['count'];
}

// Properties
$stmt = $pdo->query("SELECT COUNT(*) as total FROM logements WHERE statut = 'Disponible'");
$stats['logements_disponibles'] = $stmt->fetch()['total'];

// Contracts
$stmt = $pdo->query("SELECT COUNT(*) as total FROM contrats WHERE statut = 'signe'");
$stats['contrats_signes'] = $stmt->fetch()['total'];

// Recent applications (last 10)
$stmt = $pdo->query("SELECT c.*, l.reference as logement_ref, l.adresse 
                      FROM candidatures c 
                      LEFT JOIN logements l ON c.logement_id = l.id 
                      WHERE c.statut = 'en_cours'
                      ORDER BY c.date_soumission DESC LIMIT 10");
$recent_candidatures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Actions nécessitant une intervention ────────────────────────────────────

// 1. Contrats signés par le locataire mais en attente de notre validation
$stmt = $pdo->query("
    SELECT c.id, c.reference_unique,
           COALESCE(cl.adresse, l.adresse) as adresse,
           GROUP_CONCAT(CONCAT(loc.prenom, ' ', loc.nom) SEPARATOR ', ') as locataires
    FROM contrats c
    LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
    LEFT JOIN logements l ON c.logement_id = l.id
    LEFT JOIN locataires loc ON loc.contrat_id = c.id
    WHERE c.statut = 'signe' AND c.deleted_at IS NULL
    GROUP BY c.id
    ORDER BY c.date_creation DESC
");
$contrats_en_attente_validation = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Contrats validés mais sans état des lieux d'entrée et sans inventaire d'entrée
$stmt = $pdo->query("
    SELECT c.id, c.reference_unique,
           COALESCE(cl.adresse, l.adresse) as adresse,
           GROUP_CONCAT(CONCAT(loc.prenom, ' ', loc.nom) SEPARATOR ', ') as locataires
    FROM contrats c
    LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
    LEFT JOIN logements l ON c.logement_id = l.id
    LEFT JOIN locataires loc ON loc.contrat_id = c.id
    WHERE c.statut = 'valide'
      AND c.deleted_at IS NULL
      AND NOT EXISTS (
          SELECT 1 FROM etats_lieux el WHERE el.contrat_id = c.id AND el.type = 'entree'
      )
      AND NOT EXISTS (
          SELECT 1 FROM inventaires inv WHERE inv.contrat_id = c.id AND inv.type = 'entree'
      )
    GROUP BY c.id
    ORDER BY c.date_creation DESC
");
$contrats_sans_etat_lieux = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Locataires avec loyers impayés (mois en cours)
$moisActuel = (int)date('n');
$anneeActuelle = (int)date('Y');
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id as contrat_id, c.reference_unique,
           COALESCE(cl.adresse, l.adresse) as adresse,
           GROUP_CONCAT(CONCAT(loc.prenom, ' ', loc.nom) SEPARATOR ', ') as locataires
    FROM loyers_tracking lt
    INNER JOIN contrats c ON lt.logement_id = c.logement_id
    LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
    LEFT JOIN logements l ON c.logement_id = l.id
    LEFT JOIN locataires loc ON loc.contrat_id = c.id
    WHERE lt.statut_paiement = 'impaye'
      AND lt.annee = ?
      AND lt.mois = ?
      AND c.statut = 'valide'
      AND c.deleted_at IS NULL
    GROUP BY c.id
    ORDER BY c.reference_unique
");
$stmt->execute([$anneeActuelle, $moisActuel]);
$loyers_impayes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Procédures de départ en attente (date_demande_depart renseignée)
$stmt = $pdo->query("
    SELECT c.id, c.reference_unique, c.date_demande_depart, c.date_fin_prevue,
           COALESCE(cl.adresse, l.adresse) as adresse,
           GROUP_CONCAT(CONCAT(loc.prenom, ' ', loc.nom) SEPARATOR ', ') as locataires
    FROM contrats c
    LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
    LEFT JOIN logements l ON c.logement_id = l.id
    LEFT JOIN locataires loc ON loc.contrat_id = c.id
    WHERE c.date_demande_depart IS NOT NULL
      AND c.statut = 'valide'
      AND c.deleted_at IS NULL
    GROUP BY c.id
    ORDER BY c.date_demande_depart DESC
");
$departs_en_attente = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Signalements non terminés (statut != 'clos')
$stmt = $pdo->query("
    SELECT sig.id, sig.reference, sig.titre, sig.priorite, sig.statut,
           sig.date_signalement,
           COALESCE(cl.adresse, l.adresse) as adresse,
           CONCAT(loc.prenom, ' ', loc.nom) as locataire_nom
    FROM signalements sig
    INNER JOIN logements l ON sig.logement_id = l.id
    LEFT JOIN contrats c ON sig.contrat_id = c.id
    LEFT JOIN contrat_logement cl ON cl.contrat_id = sig.contrat_id
    LEFT JOIN locataires loc ON sig.locataire_id = loc.id
    WHERE sig.statut != 'clos'
    ORDER BY
        CASE sig.priorite WHEN 'urgent' THEN 0 ELSE 1 END,
        sig.date_signalement DESC
    LIMIT 20
");
$signalements_actifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$nb_actions = count($contrats_en_attente_validation) + count($contrats_sans_etat_lieux)
            + count($loyers_impayes) + count($departs_en_attente) + count($signalements_actifs);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .stat-card {
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stat-card h3 {
            font-size: 32px;
            font-weight: bold;
            margin: 10px 0;
        }
        .stat-card .icon {
            font-size: 40px;
            opacity: 0.3;
        }
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
        .status-en_cours { background: #ffc107; color: #000; }
        .status-accepté { background: #28a745; color: white; }
        .status-refusé { background: #dc3545; color: white; }
        .status-visite_planifiée { background: #17a2b8; color: white; }
        .status-contrat_envoyé { background: #6f42c1; color: white; }
        .status-contrat_signé { background: #007bff; color: white; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header-bar">
            <h2>Tableau de bord</h2>
            <p class="text-muted mb-0">Bienvenue, <?php echo htmlspecialchars($admin_prenom . ' ' . $admin_nom); ?></p>
        </div>
        
        <!-- Statistics -->
        <div class="row">
            <div class="col-md-3">
                <div class="stat-card bg-primary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small">Total Candidatures</div>
                            <h3><?php echo $stats['total_candidatures']; ?></h3>
                        </div>
                        <i class="bi bi-file-earmark-text icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-warning text-dark">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small">En cours</div>
                            <h3><?php echo $stats['statut_en_cours'] ?? 0; ?></h3>
                        </div>
                        <i class="bi bi-hourglass-split icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-success text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small">Acceptés</div>
                            <h3><?php echo $stats['statut_accepté'] ?? 0; ?></h3>
                        </div>
                        <i class="bi bi-check-circle icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-info text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small">Logements disponibles</div>
                            <h3><?php echo $stats['logements_disponibles']; ?></h3>
                        </div>
                        <i class="bi bi-building icon"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Actions nécessitant une intervention -->
        <?php if ($nb_actions > 0): ?>
        <div class="card border-warning mb-4">
            <div class="card-header bg-warning text-dark d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <h5 class="mb-0">Actions nécessitant une intervention
                    <span class="badge bg-dark ms-2"><?php echo $nb_actions; ?></span>
                </h5>
            </div>
            <div class="card-body">

                <?php if (!empty($contrats_en_attente_validation)): ?>
                <h6 class="fw-semibold text-dark mb-2">
                    <i class="bi bi-file-earmark-check me-1 text-warning"></i>
                    Contrats signés par le locataire – en attente de validation
                    <span class="badge bg-warning text-dark ms-1"><?php echo count($contrats_en_attente_validation); ?></span>
                </h6>
                <ul class="list-group list-group-flush mb-3">
                    <?php foreach ($contrats_en_attente_validation as $c): ?>
                    <li class="list-group-item px-0 py-1 d-flex justify-content-between align-items-center">
                        <span class="small">
                            <code><?php echo htmlspecialchars($c['reference_unique']); ?></code>
                            — <?php echo htmlspecialchars($c['adresse'] ?? ''); ?>
                            <?php if ($c['locataires']): ?>
                                <span class="text-muted">(<?php echo htmlspecialchars($c['locataires']); ?>)</span>
                            <?php endif; ?>
                        </span>
                        <a href="contrat-detail.php?id=<?php echo (int)$c['id']; ?>" class="btn btn-sm btn-outline-warning">
                            <i class="bi bi-eye me-1"></i>Valider
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <?php if (!empty($contrats_sans_etat_lieux)): ?>
                <h6 class="fw-semibold text-dark mb-2">
                    <i class="bi bi-clipboard-x me-1 text-info"></i>
                    Contrats validés sans état des lieux d'entrée ni inventaire d'entrée
                    <span class="badge bg-info text-white ms-1"><?php echo count($contrats_sans_etat_lieux); ?></span>
                </h6>
                <ul class="list-group list-group-flush mb-3">
                    <?php foreach ($contrats_sans_etat_lieux as $c): ?>
                    <li class="list-group-item px-0 py-1 d-flex justify-content-between align-items-center">
                        <span class="small">
                            <code><?php echo htmlspecialchars($c['reference_unique']); ?></code>
                            — <?php echo htmlspecialchars($c['adresse'] ?? ''); ?>
                            <?php if ($c['locataires']): ?>
                                <span class="text-muted">(<?php echo htmlspecialchars($c['locataires']); ?>)</span>
                            <?php endif; ?>
                        </span>
                        <a href="contrat-detail.php?id=<?php echo (int)$c['id']; ?>" class="btn btn-sm btn-outline-info">
                            <i class="bi bi-eye me-1"></i>Compléter
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <?php if (!empty($loyers_impayes)): ?>
                <h6 class="fw-semibold text-dark mb-2">
                    <i class="bi bi-cash-stack me-1 text-danger"></i>
                    Loyers impayés – mois en cours
                    <span class="badge bg-danger ms-1"><?php echo count($loyers_impayes); ?></span>
                </h6>
                <ul class="list-group list-group-flush mb-3">
                    <?php foreach ($loyers_impayes as $l): ?>
                    <li class="list-group-item px-0 py-1 d-flex justify-content-between align-items-center">
                        <span class="small">
                            <code><?php echo htmlspecialchars($l['reference_unique']); ?></code>
                            — <?php echo htmlspecialchars($l['adresse'] ?? ''); ?>
                            <?php if ($l['locataires']): ?>
                                <span class="text-muted">(<?php echo htmlspecialchars($l['locataires']); ?>)</span>
                            <?php endif; ?>
                        </span>
                        <a href="gestion-loyers.php?contrat_id=<?php echo (int)$l['contrat_id']; ?>" class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-eye me-1"></i>Gérer
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <?php if (!empty($departs_en_attente)): ?>
                <h6 class="fw-semibold text-dark mb-2">
                    <i class="bi bi-door-open me-1 text-secondary"></i>
                    Procédures de départ en attente (AR24)
                    <span class="badge bg-secondary ms-1"><?php echo count($departs_en_attente); ?></span>
                </h6>
                <ul class="list-group list-group-flush mb-3">
                    <?php foreach ($departs_en_attente as $d): ?>
                    <li class="list-group-item px-0 py-1 d-flex justify-content-between align-items-center">
                        <span class="small">
                            <code><?php echo htmlspecialchars($d['reference_unique']); ?></code>
                            — <?php echo htmlspecialchars($d['adresse'] ?? ''); ?>
                            <?php if ($d['locataires']): ?>
                                <span class="text-muted">(<?php echo htmlspecialchars($d['locataires']); ?>)</span>
                            <?php endif; ?>
                            <span class="text-muted ms-1">Demande le <?php echo date('d/m/Y', strtotime($d['date_demande_depart'])); ?></span>
                        </span>
                        <a href="contrat-detail.php?id=<?php echo (int)$d['id']; ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-eye me-1"></i>Traiter
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <?php if (!empty($signalements_actifs)): ?>
                <?php
                $sigStatutLabels = [
                    'nouveau'        => ['Nouveau',        'primary'],
                    'en_cours'       => ['En cours',       'warning text-dark'],
                    'pris_en_charge' => ['Pris en charge', 'info text-dark'],
                    'sur_place'      => ['Sur place',      'warning text-dark'],
                    'en_attente'     => ['En attente',     'info text-dark'],
                    'reporte'        => ['Reporté',        'danger'],
                    'resolu'         => ['Résolu',         'success'],
                ];
                ?>
                <h6 class="fw-semibold text-dark mb-2">
                    <i class="bi bi-exclamation-circle me-1 text-primary"></i>
                    Signalements en cours
                    <span class="badge bg-primary ms-1"><?php echo count($signalements_actifs); ?></span>
                </h6>
                <ul class="list-group list-group-flush mb-0">
                    <?php foreach ($signalements_actifs as $sig): ?>
                    <li class="list-group-item px-0 py-1 d-flex justify-content-between align-items-center">
                        <span class="small">
                            <?php if ($sig['priorite'] === 'urgent'): ?>
                                <span class="badge bg-danger me-1"><i class="bi bi-lightning-fill"></i> Urgent</span>
                            <?php endif; ?>
                            <strong><?php echo htmlspecialchars($sig['titre']); ?></strong>
                            — <?php echo htmlspecialchars($sig['adresse'] ?? ''); ?>
                            <?php if ($sig['locataire_nom']): ?>
                                <span class="text-muted">(<?php echo htmlspecialchars($sig['locataire_nom']); ?>)</span>
                            <?php endif; ?>
                            <?php
                            $sl = $sigStatutLabels[$sig['statut']] ?? [$sig['statut'], 'secondary'];
                            ?>
                            <span class="badge bg-<?php echo $sl[1]; ?> ms-1"><?php echo $sl[0]; ?></span>
                        </span>
                        <a href="signalement-detail.php?id=<?php echo (int)$sig['id']; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye me-1"></i>Gérer
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Applications -->
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0">Candidatures récentes</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Référence</th>
                                <th>Candidat</th>
                                <th>Email</th>
                                <th>Téléphone</th>
                                <th>Logement</th>
                                <th>Date</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_candidatures as $cand): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($cand['reference_unique'] ?? 'N/A'); ?></code></td>
                                <td><?php echo htmlspecialchars($cand['prenom'] . ' ' . $cand['nom']); ?></td>
                                <td><?php echo htmlspecialchars($cand['email']); ?></td>
                                <td><?php echo htmlspecialchars($cand['telephone']); ?></td>
                                <td>
                                    <?php if ($cand['logement_ref']): ?>
                                        <small><?php echo htmlspecialchars($cand['logement_ref']); ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">Non spécifié</small>
                                    <?php endif; ?>
                                </td>
                                <td><small><?php echo date('d/m/Y', strtotime($cand['date_soumission'])); ?></small></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '_', $cand['statut'])); ?>">
                                        <?php echo htmlspecialchars($cand['statut']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="candidature-detail.php?id=<?php echo $cand['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white">
                <a href="candidatures.php" class="btn btn-primary">Voir toutes les candidatures</a>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
