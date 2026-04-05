<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../pdf/generate-quittance.php';

$contractId = (int)($_GET['id'] ?? 0);

if ($contractId === 0) {
    $_SESSION['error'] = "ID de contrat invalide.";
    header('Location: contrats.php');
    exit;
}

/**
 * Calculate the earliest allowed month for quittance generation
 * Based on contract start date and maximum 3 years lookback
 * 
 * @param string|null $contractStartDate Contract date_prise_effet
 * @return int Unix timestamp of the earliest allowed month start
 */
function calculateEarliestAllowedMonth($contractStartDate) {
    $currentMonthStart = strtotime(date('Y-m-01'));
    $threeYearsAgo = strtotime('-36 months', $currentMonthStart);
    
    $contractStartTimestamp = !empty($contractStartDate) ? strtotime($contractStartDate) : null;
    
    if ($contractStartTimestamp !== null) {
        // Use the later of: contract start date or 3 years ago
        return max($contractStartTimestamp, $threeYearsAgo);
    }
    
    return $threeYearsAgo;
}

/**
 * Get French month name from month number
 * 
 * @param int $month Month number (1-12)
 * @return string French month name
 */
function getFrenchMonthName($month) {
    $nomsMois = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
    ];
    return $nomsMois[$month] ?? '';
}

/**
 * Format month and year in French
 * 
 * @param int $month Month number (1-12)
 * @param int $year Year
 * @return string Formatted string like "Janvier 2026"
 */
function formatMonthYearFr($month, $year) {
    return getFrenchMonthName($month) . ' ' . $year;
}


// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if we have date range inputs
    if (isset($_POST['date_debut']) && isset($_POST['date_fin'])) {
        $dateDebut = $_POST['date_debut'];
        $dateFin = $_POST['date_fin'];
        
        if (empty($dateDebut) || empty($dateFin)) {
            $_SESSION['error'] = "Veuillez sélectionner une date de début et une date de fin.";
            header('Location: generer-quittances.php?id=' . $contractId);
            exit;
        }
        
        // Convert dates to timestamps
        $timestampDebut = strtotime($dateDebut . '-01');
        $timestampFin = strtotime($dateFin . '-01');
        
        if ($timestampDebut === false || $timestampFin === false) {
            $_SESSION['error'] = "Dates invalides.";
            header('Location: generer-quittances.php?id=' . $contractId);
            exit;
        }
        
        if ($timestampDebut > $timestampFin) {
            $_SESSION['error'] = "La date de début doit être antérieure ou égale à la date de fin.";
            header('Location: generer-quittances.php?id=' . $contractId);
            exit;
        }
        
        // Generate array of months between dateDebut and dateFin using DateTime for reliability
        $moisAnnees = [];
        $currentDate = new DateTime($dateDebut . '-01');
        $endDate = new DateTime($dateFin . '-01');
        
        while ($currentDate <= $endDate) {
            $moisAnnees[] = $currentDate->format('Y-m');
            $currentDate->modify('+1 month');
        }
    } elseif (isset($_POST['mois_annees'])) {
        // Legacy support for checkbox selection
        $moisAnnees = $_POST['mois_annees']; // Array of "YYYY-MM" strings
        
        if (empty($moisAnnees)) {
            $_SESSION['error'] = "Veuillez sélectionner au moins un mois.";
            header('Location: generer-quittances.php?id=' . $contractId);
            exit;
        }
    } else {
        $_SESSION['error'] = "Aucune période sélectionnée.";
        header('Location: generer-quittances.php?id=' . $contractId);
        exit;
    }
    
    // Get contract and tenant information (frozen data from contrat_logement)
    $contrat = fetchOne("
        SELECT c.*, 
               COALESCE(cl.reference, l.reference) as reference,
               COALESCE(cl.adresse, l.adresse) as adresse,
               COALESCE(cl.loyer, l.loyer) as loyer,
               COALESCE(cl.charges, l.charges) as charges
        FROM contrats c
        LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
        LEFT JOIN logements l ON c.logement_id = l.id
        WHERE c.id = ?
    ", [$contractId]);
    
    if (!$contrat) {
        $_SESSION['error'] = "Contrat non trouvé.";
        header('Location: contrats.php');
        exit;
    }
    
    // Calculate allowed date range for validation
    $currentYear = date('Y');
    $currentMonth = date('n');
    $earliestAllowed = calculateEarliestAllowedMonth($contrat['date_prise_effet']);
    
    $locataires = fetchAll("
        SELECT * FROM locataires 
        WHERE contrat_id = ? 
        ORDER BY ordre
    ", [$contractId]);
    
    if (empty($locataires)) {
        $_SESSION['error'] = "Aucun locataire trouvé pour ce contrat.";
        header('Location: contrat-detail.php?id=' . $contractId);
        exit;
    }
    
    $successCount = 0;
    $errorCount = 0;
    $errors = [];
    
    // Generate quittances for each selected month
    foreach ($moisAnnees as $moisAnnee) {
        list($annee, $mois) = explode('-', $moisAnnee);
        $annee = (int)$annee;
        $mois = (int)$mois;
        
        // Validate: no future months allowed (only current month and earlier)
        $selectedMonthStart = strtotime("$annee-$mois-01");
        if ($annee > $currentYear || ($annee == $currentYear && $mois > $currentMonth)) {
            $errorCount++;
            $errors[] = "Impossible de générer une quittance pour un mois futur (" . formatMonthYearFr($mois, $annee) . ")";
            continue;
        }
        
        // Validate: month must be within allowed range
        if ($selectedMonthStart < $earliestAllowed) {
            $errorCount++;
            $errors[] = "Le mois " . formatMonthYearFr($mois, $annee) . " est en dehors de la période autorisée";
            continue;
        }
        
        // Generate PDF
        $result = generateQuittancePDF($contractId, $mois, $annee);
        
        if ($result === false) {
            $errorCount++;
            $errors[] = "Erreur lors de la génération pour " . date('F Y', mktime(0, 0, 0, $mois, 1, $annee));
            continue;
        }
        
        // Prepare email variables
        $nomsMois = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
        ];
        
        $periode = $nomsMois[$mois] . ' ' . $annee;
        $montantLoyer = number_format((float)$contrat['loyer'], 2, ',', ' ');
        $montantCharges = number_format((float)$contrat['charges'], 2, ',', ' ');
        $montantTotal = number_format((float)$contrat['loyer'] + (float)$contrat['charges'], 2, ',', ' ');

        // Use physical URL for the quittance PDF download link
        $lienQuittance = documentPathToUrl($result['filepath']);
        
        // Send email to each tenant
        foreach ($locataires as $locataire) {
            $emailSent = sendTemplatedEmail('quittance_envoyee', $locataire['email'], [
                'locataire_nom'                 => $locataire['nom'],
                'locataire_prenom'              => $locataire['prenom'],
                'adresse'                       => $contrat['adresse'],
                'periode'                       => $periode,
                'montant_loyer'                 => $montantLoyer,
                'montant_charges'               => $montantCharges,
                'montant_total'                 => $montantTotal,
                'signature'                     => getParameter('email_signature', ''),
                'lien_telechargement_quittance' => $lienQuittance,
            ], null, false, true, ['contexte' => 'quittance_id=' . $result['quittance_id']]);
            
            if (!$emailSent) {
                error_log("Erreur envoi email quittance à " . $locataire['email']);
            }
        }
        
        // Update quittance record to mark email as sent
        $stmt = $pdo->prepare("UPDATE quittances SET email_envoye = 1, date_envoi_email = NOW() WHERE id = ?");
        $stmt->execute([$result['quittance_id']]);
        
        $successCount++;
    }
    
    // Display success/error messages
    if ($successCount > 0) {
        $message = "Quittance(s) envoyée(s) avec succès : $successCount générée(s) et envoyée(s) par email.";
        $_SESSION['success'] = $message;
    }
    
    if ($errorCount > 0) {
        $message = "Erreurs lors de la génération : $errorCount échec(s). " . implode(', ', $errors);
        $_SESSION['warning'] = $message;
    }
    
    header('Location: contrat-detail.php?id=' . $contractId);
    exit;
}

// Get contract details (frozen data from contrat_logement)
$contrat = fetchOne("
    SELECT c.*, 
           COALESCE(cl.reference, l.reference) as logement_ref, 
           COALESCE(cl.adresse, l.adresse) as logement_adresse
    FROM contrats c
    LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
    LEFT JOIN logements l ON c.logement_id = l.id
    WHERE c.id = ?
", [$contractId]);

if (!$contrat) {
    $_SESSION['error'] = "Contrat non trouvé.";
    header('Location: contrats.php');
    exit;
}

// Get existing quittances for this contract
$stmt = $pdo->prepare("
    SELECT mois, annee, reference_unique, date_generation, email_envoye
    FROM quittances 
    WHERE contrat_id = ? 
    ORDER BY annee DESC, mois DESC
");
$stmt->execute([$contractId]);
$existingQuittances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create a map of existing month/year combinations
$existingMap = [];
foreach ($existingQuittances as $q) {
    $existingMap[$q['annee'] . '-' . str_pad($q['mois'], 2, '0', STR_PAD_LEFT)] = true;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Générer des Quittances - <?php echo htmlspecialchars($contrat['reference_unique']); ?></title>
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
        .form-card {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .form-control-lg {
            font-size: 1.1rem;
            padding: 0.75rem 1rem;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4><i class="bi bi-receipt"></i> Générer des Quittances de Loyer</h4>
                    <p class="mb-0 text-muted">Contrat: <strong><?php echo htmlspecialchars($contrat['reference_unique']); ?></strong></p>
                    <p class="mb-0 text-muted">Logement: <?php echo htmlspecialchars($contrat['logement_adresse']); ?></p>
                </div>
                <a href="contrat-detail.php?id=<?php echo $contractId; ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Retour au contrat
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <h5 class="mb-4"><i class="bi bi-calendar-check"></i> Sélectionner la Période</h5>
            <p class="text-muted">Sélectionnez une période en choisissant un mois de début et un mois de fin. 
            Une quittance sera générée et envoyée par email pour chaque mois de la période sélectionnée.</p>
            
            <?php
            // Calculate the earliest and latest allowed months
            $currentYear = date('Y');
            $currentMonth = date('n');
            
            // Calculate the earliest allowed month
            $earliestAllowed = calculateEarliestAllowedMonth($contrat['date_prise_effet']);
            $earliestYear = date('Y', $earliestAllowed);
            $earliestMonth = date('n', $earliestAllowed);
            
            // Min and max dates for the input fields
            $minDate = sprintf('%04d-%02d', $earliestYear, $earliestMonth);
            $maxDate = sprintf('%04d-%02d', $currentYear, $currentMonth);
            ?>
            
            <?php if (!empty($contrat['date_prise_effet'])): ?>
                <?php
                $contractStartTimestamp = strtotime($contrat['date_prise_effet']);
                $contractStartMonth = date('n', $contractStartTimestamp);
                $contractStartYear = date('Y', $contractStartTimestamp);
                ?>
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle"></i>
                    <strong>Période disponible :</strong> 
                    Les quittances sont disponibles à partir de 
                    <strong><?php echo formatMonthYearFr($contractStartMonth, $contractStartYear); ?></strong> 
                    (date de prise d'effet du contrat) et jusqu'au mois en cours inclus.
                    <br>
                    La visibilité maximale est de 3 ans ou depuis le début du contrat si celui-ci a moins de 3 ans.
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="quittanceForm">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="date_debut" class="form-label">
                                <i class="bi bi-calendar-event"></i> Date de début (Depuis)
                            </label>
                            <input 
                                type="month" 
                                class="form-control form-control-lg" 
                                id="date_debut" 
                                name="date_debut" 
                                min="<?php echo $minDate; ?>" 
                                max="<?php echo $maxDate; ?>"
                                required>
                            <div class="form-text">Premier mois de la période</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="date_fin" class="form-label">
                                <i class="bi bi-calendar-event"></i> Date de fin (Jusqu'à)
                            </label>
                            <input 
                                type="month" 
                                class="form-control form-control-lg" 
                                id="date_fin" 
                                name="date_fin" 
                                min="<?php echo $minDate; ?>" 
                                max="<?php echo $maxDate; ?>"
                                required>
                            <div class="form-text">Dernier mois de la période (inclus)</div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <strong>Information:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Une quittance PDF sera générée pour chaque mois de la période sélectionnée</li>
                        <li>Les quittances seront automatiquement envoyées par email aux locataires</li>
                        <li>Une copie cachée (BCC) sera envoyée aux administrateurs</li>
                        <li>Vous pouvez re-générer une quittance déjà existante (elle sera écrasée)</li>
                    </ul>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <a href="contrat-detail.php?id=<?php echo $contractId; ?>" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Annuler
                    </a>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-send"></i> Générer et Envoyer les Quittances
                    </button>
                </div>
            </form>
        </div>

        <?php if (!empty($existingQuittances)): ?>
        <div class="form-card">
            <h5 class="mb-4"><i class="bi bi-clock-history"></i> Historique des Quittances Générées</h5>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Référence</th>
                            <th>Période</th>
                            <th>Date de Génération</th>
                            <th>Email Envoyé</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($existingQuittances as $q): ?>
                        <?php
                        $nomsMois = [
                            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
                            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
                            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
                        ];
                        $periode = $nomsMois[$q['mois']] . ' ' . $q['annee'];
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($q['reference_unique']); ?></strong></td>
                            <td><?php echo $periode; ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($q['date_generation'])); ?></td>
                            <td>
                                <?php if ($q['email_envoye']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle"></i> Oui</span>
                                <?php else: ?>
                                    <span class="badge bg-warning"><i class="bi bi-clock"></i> Non</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Date range validation
        document.getElementById('quittanceForm').addEventListener('submit', function(e) {
            const dateDebut = document.getElementById('date_debut').value;
            const dateFin = document.getElementById('date_fin').value;
            
            if (!dateDebut || !dateFin) {
                e.preventDefault();
                alert('Veuillez sélectionner une date de début et une date de fin.');
                return false;
            }
            
            if (dateDebut > dateFin) {
                e.preventDefault();
                alert('La date de début doit être antérieure ou égale à la date de fin.');
                return false;
            }
            
            // Confirmation dialog
            if (!confirm('Êtes-vous sûr de vouloir générer et envoyer les quittances pour la période sélectionnée ?')) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });
        
        // Update date_fin constraints when date_debut changes
        document.getElementById('date_debut').addEventListener('change', function() {
            const dateFinInput = document.getElementById('date_fin');
            dateFinInput.min = this.value;
            
            // If date_fin is now invalid (before new date_debut), update it
            if (dateFinInput.value && dateFinInput.value < this.value) {
                dateFinInput.value = this.value;
            }
        });
    </script>
</body>
</html>
