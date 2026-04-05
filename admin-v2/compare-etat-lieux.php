<?php
/**
 * Compare Entry/Exit État des Lieux
 * Shows side-by-side comparison between entry and exit inventories
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

// Get contrat ID
$contrat_id = isset($_GET['contrat_id']) ? (int)$_GET['contrat_id'] : 0;

if ($contrat_id < 1) {
    $_SESSION['error'] = "ID de contrat invalide";
    header('Location: etats-lieux.php');
    exit;
}

// Get entry état des lieux
$stmt = $pdo->prepare("
    SELECT edl.*, c.reference_unique as contrat_ref, l.adresse
    FROM etats_lieux edl
    LEFT JOIN contrats c ON edl.contrat_id = c.id
    LEFT JOIN logements l ON c.logement_id = l.id
    WHERE edl.contrat_id = ? AND edl.type = 'entree'
    ORDER BY edl.date_etat DESC LIMIT 1
");
$stmt->execute([$contrat_id]);
$etat_entree = $stmt->fetch(PDO::FETCH_ASSOC);

// Get exit état des lieux
$stmt = $pdo->prepare("
    SELECT edl.*, c.reference_unique as contrat_ref, l.adresse
    FROM etats_lieux edl
    LEFT JOIN contrats c ON edl.contrat_id = c.id
    LEFT JOIN logements l ON c.logement_id = l.id
    WHERE edl.contrat_id = ? AND edl.type = 'sortie'
    ORDER BY edl.date_etat DESC LIMIT 1
");
$stmt->execute([$contrat_id]);
$etat_sortie = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$etat_entree && !$etat_sortie) {
    $_SESSION['error'] = "Aucun état des lieux trouvé pour ce contrat";
    header('Location: etats-lieux.php');
    exit;
}

$contrat_ref = $etat_entree['contrat_ref'] ?? $etat_sortie['contrat_ref'] ?? 'N/A';
$adresse = $etat_entree['adresse'] ?? $etat_sortie['adresse'] ?? 'N/A';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comparaison Entrée/Sortie - <?php echo htmlspecialchars($contrat_ref); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .comparison-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .comparison-table {
            width: 100%;
        }
        .comparison-table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: center;
            border: 1px solid #dee2e6;
        }
        .comparison-table td {
            padding: 12px;
            border: 1px solid #dee2e6;
            vertical-align: top;
        }
        .comparison-table td.field-name {
            font-weight: 600;
            background: #f8f9fa;
            width: 25%;
        }
        .value-entry {
            background: #e7f5ff;
        }
        .value-exit {
            background: #fff5e7;
        }
        .difference {
            background: #ffe7e7;
            padding: 5px;
            border-radius: 4px;
            font-weight: 600;
        }
        .match {
            background: #e7ffe7;
            padding: 5px;
            border-radius: 4px;
        }
        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #212529;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #0d6efd;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4><i class="bi bi-arrows-angle-contract"></i> Comparaison Entrée / Sortie</h4>
                    <p class="text-muted mb-0">
                        Contrat: <?php echo htmlspecialchars($contrat_ref); ?> - <?php echo htmlspecialchars($adresse); ?>
                    </p>
                </div>
                <div>
                    <a href="etats-lieux.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Retour
                    </a>
                </div>
            </div>
        </div>

        <?php if (!$etat_entree): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> Aucun état des lieux d'entrée trouvé pour ce contrat.
            </div>
        <?php endif; ?>

        <?php if (!$etat_sortie): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> Aucun état des lieux de sortie trouvé pour ce contrat.
            </div>
        <?php endif; ?>

        <?php if ($etat_entree && $etat_sortie): ?>
        
        <!-- Compteurs -->
        <div class="comparison-card">
            <div class="section-title"><i class="bi bi-speedometer2"></i> Relevé des compteurs</div>
            <table class="comparison-table">
                <thead>
                    <tr>
                        <th style="width: 25%;">Compteur</th>
                        <th style="width: 37.5%;" class="value-entry">Entrée (<?php echo date('d/m/Y', strtotime($etat_entree['date_etat'])); ?>)</th>
                        <th style="width: 37.5%;" class="value-exit">Sortie (<?php echo date('d/m/Y', strtotime($etat_sortie['date_etat'])); ?>)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="field-name">Électricité (kWh)</td>
                        <td class="value-entry"><?php echo htmlspecialchars($etat_entree['compteur_electricite'] ?? 'N/A'); ?></td>
                        <td class="value-exit">
                            <?php 
                            echo htmlspecialchars($etat_sortie['compteur_electricite'] ?? 'N/A');
                            if (!empty($etat_entree['compteur_electricite']) && !empty($etat_sortie['compteur_electricite'])) {
                                $diff = (float)$etat_sortie['compteur_electricite'] - (float)$etat_entree['compteur_electricite'];
                                echo "<br><span class='badge bg-info'>Consommation: " . number_format($diff, 2) . " kWh</span>";
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="field-name">Eau froide (m³)</td>
                        <td class="value-entry"><?php echo htmlspecialchars($etat_entree['compteur_eau_froide'] ?? 'N/A'); ?></td>
                        <td class="value-exit">
                            <?php 
                            echo htmlspecialchars($etat_sortie['compteur_eau_froide'] ?? 'N/A');
                            if (!empty($etat_entree['compteur_eau_froide']) && !empty($etat_sortie['compteur_eau_froide'])) {
                                $diff = (float)$etat_sortie['compteur_eau_froide'] - (float)$etat_entree['compteur_eau_froide'];
                                echo "<br><span class='badge bg-info'>Consommation: " . number_format($diff, 2) . " m³</span>";
                            }
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Clés -->
        <div class="comparison-card">
            <div class="section-title"><i class="bi bi-key"></i> Clés</div>
            <table class="comparison-table">
                <thead>
                    <tr>
                        <th style="width: 25%;">Type</th>
                        <th style="width: 37.5%;" class="value-entry">Remise (Entrée)</th>
                        <th style="width: 37.5%;" class="value-exit">Restitution (Sortie)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="field-name">Clés appartement</td>
                        <td class="value-entry"><?php echo (int)($etat_entree['cles_appartement'] ?? 0); ?></td>
                        <td class="value-exit">
                            <?php 
                            $keys_exit = (int)($etat_sortie['cles_appartement'] ?? 0);
                            $keys_entry = (int)($etat_entree['cles_appartement'] ?? 0);
                            echo $keys_exit;
                            if ($keys_exit === $keys_entry) {
                                echo " <span class='match'>✓ Conforme</span>";
                            } else {
                                echo " <span class='difference'>⚠ Non conforme ($keys_entry attendu)</span>";
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="field-name">Clés boîte aux lettres</td>
                        <td class="value-entry"><?php echo (int)($etat_entree['cles_boite_lettres'] ?? 0); ?></td>
                        <td class="value-exit">
                            <?php 
                            $keys_exit = (int)($etat_sortie['cles_boite_lettres'] ?? 0);
                            $keys_entry = (int)($etat_entree['cles_boite_lettres'] ?? 0);
                            echo $keys_exit;
                            if ($keys_exit === $keys_entry) {
                                echo " <span class='match'>✓ Conforme</span>";
                            } else {
                                echo " <span class='difference'>⚠ Non conforme ($keys_entry attendu)</span>";
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="field-name">Autre</td>
                        <td class="value-entry"><?php echo (int)($etat_entree['cles_autre'] ?? 0); ?></td>
                        <td class="value-exit">
                            <?php 
                            $keys_exit = (int)($etat_sortie['cles_autre'] ?? 0);
                            $keys_entry = (int)($etat_entree['cles_autre'] ?? 0);
                            echo $keys_exit;
                            if ($keys_exit === $keys_entry) {
                                echo " <span class='match'>✓ Conforme</span>";
                            } else {
                                echo " <span class='difference'>⚠ Non conforme ($keys_entry attendu)</span>";
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="field-name">Conformité</td>
                        <td class="value-entry">-</td>
                        <td class="value-exit">
                            <?php 
                            $conformite = $etat_sortie['cles_conformite'] ?? 'non_applicable';
                            $labels = [
                                'conforme' => '<span class="badge bg-success">Conforme</span>',
                                'non_conforme' => '<span class="badge bg-danger">Non conforme</span>',
                                'non_applicable' => '<span class="badge bg-secondary">Non applicable</span>'
                            ];
                            echo $labels[$conformite] ?? $conformite;
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- État des pièces -->
        <div class="comparison-card">
            <div class="section-title"><i class="bi bi-house"></i> État des pièces</div>
            <table class="comparison-table">
                <thead>
                    <tr>
                        <th style="width: 25%;">Pièce</th>
                        <th style="width: 37.5%;" class="value-entry">État d'entrée</th>
                        <th style="width: 37.5%;" class="value-exit">État de sortie</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="field-name">Description de l'état du logement</td>
                        <td class="value-entry"><small><?php echo nl2br(htmlspecialchars($etat_entree['etat_logement'] ?? 'N/A')); ?></small></td>
                        <td class="value-exit"><small><?php echo nl2br(htmlspecialchars($etat_sortie['etat_logement'] ?? 'N/A')); ?></small></td>
                    </tr>
                    <tr>
                        <td class="field-name">État général</td>
                        <td class="value-entry"><small><?php echo nl2br(htmlspecialchars($etat_entree['etat_general'] ?? 'N/A')); ?></small></td>
                        <td class="value-exit"><small><?php echo nl2br(htmlspecialchars($etat_sortie['etat_general'] ?? 'N/A')); ?></small></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Dégradations et dépôt de garantie -->
        <?php if (!empty($etat_sortie['degradations_constatees'])): ?>
        <div class="comparison-card">
            <div class="section-title"><i class="bi bi-exclamation-triangle"></i> Dégradations et Dépôt de garantie</div>
            <div class="alert alert-warning">
                <strong><i class="bi bi-exclamation-triangle"></i> Dégradations constatées</strong>
                <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($etat_sortie['degradations_details'] ?? '')); ?></p>
            </div>
            
            <?php if ($etat_sortie['depot_garantie_status'] !== 'non_applicable'): ?>
            <table class="table table-bordered mt-3">
                <tr>
                    <th>Décision concernant le dépôt de garantie:</th>
                    <td>
                        <?php
                        $status_labels = [
                            'restitution_totale' => '<span class="badge bg-success">Restitution totale</span>',
                            'restitution_partielle' => '<span class="badge bg-warning">Restitution partielle</span>',
                            'retenue_totale' => '<span class="badge bg-danger">Retenue totale</span>'
                        ];
                        echo $status_labels[$etat_sortie['depot_garantie_status']] ?? $etat_sortie['depot_garantie_status'];
                        ?>
                    </td>
                </tr>
                <?php if (!empty($etat_sortie['depot_garantie_montant_retenu'])): ?>
                <tr>
                    <th>Montant retenu:</th>
                    <td><strong><?php echo number_format($etat_sortie['depot_garantie_montant_retenu'], 2); ?> €</strong></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($etat_sortie['depot_garantie_motif_retenue'])): ?>
                <tr>
                    <th>Motif de la retenue:</th>
                    <td><?php echo nl2br(htmlspecialchars($etat_sortie['depot_garantie_motif_retenue'])); ?></td>
                </tr>
                <?php endif; ?>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
