<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

$quittanceId = (int)($_GET['id'] ?? 0);

if ($quittanceId === 0) {
    $_SESSION['error'] = "ID de quittance invalide.";
    header('Location: quittances.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notes = trim($_POST['notes'] ?? '');
    
    try {
        $stmt = $pdo->prepare("
            UPDATE quittances 
            SET notes = ?
            WHERE id = ?
        ");
        $stmt->execute([$notes, $quittanceId]);
        
        $_SESSION['success'] = "Quittance mise à jour avec succès.";
        header('Location: quittances.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = "Erreur lors de la mise à jour : " . $e->getMessage();
        error_log("Erreur mise à jour quittance: " . $e->getMessage());
    }
}

// Get quittance details (using contrat_logement for frozen reference/address)
$stmt = $pdo->prepare("
    SELECT q.*, 
           c.reference_unique as contrat_ref,
           COALESCE(cl.reference, l.reference) as logement_ref,
           COALESCE(cl.adresse, l.adresse) as logement_adresse,
           (SELECT GROUP_CONCAT(CONCAT(prenom, ' ', nom) SEPARATOR ', ') 
            FROM locataires 
            WHERE contrat_id = q.contrat_id) as locataires_noms
    FROM quittances q
    INNER JOIN contrats c ON q.contrat_id = c.id
    LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
    LEFT JOIN logements l ON c.logement_id = l.id
    WHERE q.id = ?
");
$stmt->execute([$quittanceId]);
$quittance = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quittance) {
    $_SESSION['error'] = "Quittance non trouvée.";
    header('Location: quittances.php');
    exit;
}

$nomsMois = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier Quittance - Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .info-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .info-row {
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-pencil"></i> Modifier Quittance</h1>
            <a href="quittances.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php 
                echo $_SESSION['error']; 
                unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6">
                <div class="info-card">
                    <h5 class="mb-3"><i class="bi bi-info-circle"></i> Informations de la Quittance</h5>
                    
                    <div class="info-row">
                        <div class="info-label">Référence</div>
                        <div><?php echo htmlspecialchars($quittance['reference_unique']); ?></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Contrat</div>
                        <div>
                            <a href="contrat-detail.php?id=<?php echo $quittance['contrat_id']; ?>">
                                <?php echo htmlspecialchars($quittance['contrat_ref']); ?>
                            </a>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Logement</div>
                        <div>
                            <strong><?php echo htmlspecialchars($quittance['logement_ref']); ?></strong><br>
                            <small class="text-muted"><?php echo htmlspecialchars($quittance['logement_adresse']); ?></small>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Locataires</div>
                        <div><?php echo htmlspecialchars($quittance['locataires_noms'] ?? 'N/A'); ?></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Période</div>
                        <div><strong><?php echo $nomsMois[$quittance['mois']] . ' ' . $quittance['annee']; ?></strong></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Date de Période</div>
                        <div>
                            Du <?php echo date('d/m/Y', strtotime($quittance['date_debut_periode'])); ?> 
                            au <?php echo date('d/m/Y', strtotime($quittance['date_fin_periode'])); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="info-card">
                    <h5 class="mb-3"><i class="bi bi-currency-euro"></i> Montants</h5>
                    
                    <div class="info-row">
                        <div class="info-label">Loyer</div>
                        <div><?php echo number_format($quittance['montant_loyer'], 2, ',', ' '); ?> €</div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Charges</div>
                        <div><?php echo number_format($quittance['montant_charges'], 2, ',', ' '); ?> €</div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Total</div>
                        <div><strong class="text-primary"><?php echo number_format($quittance['montant_total'], 2, ',', ' '); ?> €</strong></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Date de Génération</div>
                        <div><?php echo date('d/m/Y H:i', strtotime($quittance['date_generation'])); ?></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Email Envoyé</div>
                        <div>
                            <?php if ($quittance['email_envoye']): ?>
                                <span class="badge bg-success">
                                    <i class="bi bi-check-circle"></i> Oui
                                </span>
                                <?php if ($quittance['date_envoi_email']): ?>
                                    <br><small class="text-muted">Le <?php echo date('d/m/Y H:i', strtotime($quittance['date_envoi_email'])); ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-warning">
                                    <i class="bi bi-exclamation-circle"></i> Non
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($quittance['fichier_pdf']): ?>
                    <div class="info-row">
                        <div class="info-label">Fichier PDF</div>
                        <div>
                            <?php 
                            $editPdfFsPath = $quittance['fichier_pdf'];
                            // If not an absolute path, try to locate the file in the known pdf/quittances/ directory
                            if ($editPdfFsPath && $editPdfFsPath[0] !== '/' && !file_exists($editPdfFsPath)) {
                                $editPdfFsPath = dirname(__DIR__) . '/pdf/quittances/' . basename($editPdfFsPath);
                            }
                            ?>
                            <?php if (file_exists($editPdfFsPath)): ?>
                                <a href="<?php echo htmlspecialchars($config['SITE_URL'] . '/pdf/quittances/' . basename($quittance['fichier_pdf'])); ?>" target="_blank" class="btn btn-sm btn-success">
                                    <i class="bi bi-download"></i> Télécharger
                                </a>
                            <?php else: ?>
                                <span class="text-danger">Fichier introuvable</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="info-card">
                    <h5 class="mb-3"><i class="bi bi-pencil-square"></i> Modifier les Notes</h5>
                    
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="5" placeholder="Ajoutez des notes concernant cette quittance..."><?php echo htmlspecialchars($quittance['notes'] ?? ''); ?></textarea>
                            <div class="form-text">Ces notes sont privées et ne seront pas visibles dans le PDF.</div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Enregistrer
                            </button>
                            <a href="quittances.php" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i> Annuler
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    </div><!-- end main-content -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
