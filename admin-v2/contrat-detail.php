<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

$contractId = (int)($_GET['id'] ?? 0);

if ($contractId === 0) {
    $_SESSION['error'] = "ID de contrat invalide.";
    header('Location: contrats.php');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Check which columns exist (used by both validate and cancel actions)
    $existingColumns = [];
    $result = $pdo->query("
        SELECT COLUMN_NAME 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'contrats' 
        AND COLUMN_NAME IN ('validated_by', 'validation_notes', 'motif_annulation')
    ");
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $existingColumns[$row['COLUMN_NAME']] = true;
    }
    
    if ($_POST['action'] === 'validate') {
        // Validate the contract and add company signature
        $notes = trim($_POST['validation_notes'] ?? '');
        $adminId = $_SESSION['admin_id'] ?? null;
        
        // Build UPDATE query based on existing columns
        $updateFields = ['statut = ?', 'date_validation = NOW()'];
        $params = ['valide'];
        
        if (isset($existingColumns['validation_notes'])) {
            $updateFields[] = 'validation_notes = ?';
            $params[] = $notes;
        }
        
        if (isset($existingColumns['validated_by'])) {
            $updateFields[] = 'validated_by = ?';
            $params[] = $adminId;
        }
        
        $params[] = $contractId;
        
        $stmt = $pdo->prepare("
            UPDATE contrats 
            SET " . implode(', ', $updateFields) . "
            WHERE id = ?
        ");
        $stmt->execute($params);
        
        // Regenerate PDF with company signature now that contract is validated
        error_log("Contract Validation: Régénération du PDF pour contrat #$contractId après validation");
        require_once __DIR__ . '/../pdf/generate-bail.php';
        $pdfPath = generateBailPDF($contractId);
        
        // Check if PDF generation was successful
        if (!$pdfPath) {
            error_log("Contract Validation: ERREUR - La régénération du PDF a échoué (generateBailPDF a retourné false) pour contrat #$contractId");
        } elseif (!file_exists($pdfPath)) {
            error_log("Contract Validation: ERREUR - Le PDF régénéré n'existe pas: $pdfPath pour contrat #$contractId");
        } else {
            error_log("Contract Validation: PDF régénéré avec succès: $pdfPath pour contrat #$contractId");
        }
        
        // Get contract and tenant details for emails
        // Important: Select c.* first, then explicitly name logements columns to avoid column name collision
        $contrat = fetchOne("
            SELECT c.*, 
                   c.id as contrat_id, 
                   c.reference_unique as reference_contrat,
                   l.reference,
                   l.adresse,
                   
                   l.type,
                   l.surface,
                   l.loyer,
                   l.charges,
                   l.depot_garantie,
                   l.parking
            FROM contrats c
            INNER JOIN logements l ON c.logement_id = l.id
            WHERE c.id = ?
        ", [$contractId]);
        
        $locataires = fetchAll("
            SELECT * FROM locataires 
            WHERE contrat_id = ? 
            ORDER BY ordre
        ", [$contractId]);
        
        // Prepare email data
        $locatairesNames = array_map(function($loc) {
            return $loc['prenom'] . ' ' . $loc['nom'];
        }, $locataires);
        
        $adminInfo = fetchOne("SELECT nom, prenom FROM administrateurs WHERE id = ?", [$adminId]);
        $adminName = $adminInfo ? $adminInfo['prenom'] . ' ' . $adminInfo['nom'] : 'Administrateur';
        
        // Generate a unique token for the assurance/visale upload link
        $tokenAssurance = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE contrats SET token_assurance = ? WHERE id = ?")->execute([$tokenAssurance, $contractId]);

        // Send email to all tenants with admin notification in Cc (isAdminEmail parameter enables Cc to admins)
        if (!empty($locataires)) {
            $lienAssurance = BASE_URL . '/envoyer-assurance.php?token=' . $tokenAssurance;
            foreach ($locataires as $locataire) {
                sendTemplatedEmail('contrat_valide_client', $locataire['email'], [
                    'nom' => $locataire['nom'],
                    'prenom' => $locataire['prenom'],
                    'reference' => $contrat['reference_contrat'],
                    'logement' => $contrat['reference'] . ' - ' . $contrat['adresse'],
                    'date_prise_effet' => date('d/m/Y', strtotime($contrat['date_prise_effet'])),
                    'depot_garantie' => number_format($contrat['depot_garantie'], 2, ',', ' '),
                    'lien_telecharger' => BASE_URL . '/pdf/download.php?contrat_id=' . $contractId,
                    'lien_procedure_depart' => BASE_URL . '/signature/procedure-depart.php?token=' . urlencode($contrat['reference_unique'])
                ], null, true, false, ['contexte' => 'contrat_id=' . $contractId]);

                // Send assurance/visale request email (client email, admins in BCC)
                sendTemplatedEmail('demande_assurance_visale', $locataire['email'], [
                    'nom' => $locataire['nom'],
                    'prenom' => $locataire['prenom'],
                    'reference' => $contrat['reference_unique'],
                    'lien_upload' => $lienAssurance
                ], null, false, true, ['contexte' => 'contrat_id=' . $contractId]); // isAdminEmail=false (client email), addAdminBcc=true
            }
        }
        
        $_SESSION['success'] = "Contrat validé avec succès. La signature électronique de la société a été ajoutée au PDF.";
        header('Location: contrat-detail.php?id=' . $contractId);
        exit;
    }
    elseif ($_POST['action'] === 'cancel') {
        // Cancel the contract
        $motif = trim($_POST['motif_annulation'] ?? '');
        $adminId = $_SESSION['admin_id'] ?? null;
        
        if (empty($motif)) {
            $_SESSION['error'] = "Le motif d'annulation est requis.";
            header('Location: contrat-detail.php?id=' . $contractId);
            exit;
        }
        
        // Build UPDATE query based on existing columns
        $updateFields = ['statut = ?', 'updated_at = NOW()'];
        $params = ['annule'];
        
        if (isset($existingColumns['motif_annulation'])) {
            $updateFields[] = 'motif_annulation = ?';
            $params[] = $motif;
        }
        
        if (isset($existingColumns['validated_by'])) {
            $updateFields[] = 'validated_by = ?';
            $params[] = $adminId;
        }
        
        $params[] = $contractId;
        
        $stmt = $pdo->prepare("
            UPDATE contrats 
            SET " . implode(', ', $updateFields) . "
            WHERE id = ?
        ");
        $stmt->execute($params);
        
        // Get contract and tenant details for emails
        // Important: Select c.* first, then explicitly name logements columns to avoid column name collision
        $contrat = fetchOne("
            SELECT c.*, 
                   c.id as contrat_id, 
                   c.reference_unique as reference_contrat,
                   l.reference,
                   l.adresse,
                   
                   l.type,
                   l.surface,
                   l.loyer,
                   l.charges,
                   l.depot_garantie,
                   l.parking
            FROM contrats c
            INNER JOIN logements l ON c.logement_id = l.id
            WHERE c.id = ?
        ", [$contractId]);
        
        $locataires = fetchAll("
            SELECT * FROM locataires 
            WHERE contrat_id = ? 
            ORDER BY ordre
        ", [$contractId]);
        
        // Prepare email data
        $locatairesNames = array_map(function($loc) {
            return $loc['prenom'] . ' ' . $loc['nom'];
        }, $locataires);
        
        $adminInfo = fetchOne("SELECT nom, prenom FROM administrateurs WHERE id = ?", [$adminId]);
        $adminName = $adminInfo ? $adminInfo['prenom'] . ' ' . $adminInfo['nom'] : 'Administrateur';
        
        // Send email to client with admin notification in Cc (isAdminEmail parameter enables Cc to admins)
        if (!empty($locataires)) {
            $firstTenant = $locataires[0];
            sendTemplatedEmail('contrat_annule_client', $firstTenant['email'], [
                'nom' => $firstTenant['nom'],
                'prenom' => $firstTenant['prenom'],
                'reference' => $contrat['reference_contrat'],
                'logement' => $contrat['reference'] . ' - ' . $contrat['adresse'],
                'motif_annulation' => $motif
            ], null, true, false, ['contexte' => 'contrat_id=' . $contractId]);
        }
        
        $_SESSION['success'] = "Contrat annulé. Le client a été notifié.";
        header('Location: contrat-detail.php?id=' . $contractId);
        exit;
    }
    elseif ($_POST['action'] === 'update_dates') {
        // Update contract dates
        $date_prise_effet = trim($_POST['date_prise_effet'] ?? '');
        $date_fin_prevue = trim($_POST['date_fin_prevue'] ?? '');

        $updateFields = ['updated_at = NOW()'];
        $params = [];

        if (!empty($date_prise_effet)) {
            $d = DateTime::createFromFormat('Y-m-d', $date_prise_effet);
            if ($d && $d->format('Y-m-d') === $date_prise_effet) {
                $updateFields[] = 'date_prise_effet = ?';
                $params[] = $date_prise_effet;
            }
        } else {
            $updateFields[] = 'date_prise_effet = NULL';
        }

        if (!empty($date_fin_prevue)) {
            $d = DateTime::createFromFormat('Y-m-d', $date_fin_prevue);
            if ($d && $d->format('Y-m-d') === $date_fin_prevue) {
                $updateFields[] = 'date_fin_prevue = ?';
                $params[] = $date_fin_prevue;
            }
        } else {
            $updateFields[] = 'date_fin_prevue = NULL';
        }

        $params[] = $contractId;
        $stmt = $pdo->prepare("UPDATE contrats SET " . implode(', ', $updateFields) . " WHERE id = ?");
        $stmt->execute($params);

        $_SESSION['success'] = "Dates du contrat mises à jour avec succès.";
        header('Location: contrat-detail.php?id=' . $contractId);
        exit;
    }

    elseif ($_POST['action'] === 'delete') {
        // Delete contract permanently — cascades to locataires, logs, etc.
        // Only allow deletion if the contract is in draft/annule status
        $contratCheck = fetchOne("SELECT statut FROM contrats WHERE id = ?", [$contractId]);
        if (!$contratCheck) {
            $_SESSION['error'] = "Contrat introuvable.";
            header('Location: contrats.php');
            exit;
        }

        // Delete related records first (foreign key constraints)
        try {
            $pdo->beginTransaction();

            // Delete linked documents and files
            $locataires = fetchAll("SELECT * FROM locataires WHERE contrat_id = ?", [$contractId]);
            foreach ($locataires as $loc) {
                // Remove uploaded identity document files if they exist
                foreach (['piece_identite_recto', 'piece_identite_verso'] as $col) {
                    if (!empty($loc[$col])) {
                        $filePath = dirname(__DIR__) . '/uploads/' . $loc[$col];
                        if (file_exists($filePath)) {
                            @unlink($filePath);
                        }
                    }
                }
            }

            // Remove payment proof file if it exists
            $contratData = fetchOne("SELECT justificatif_paiement FROM contrats WHERE id = ?", [$contractId]);
            if ($contratData && !empty($contratData['justificatif_paiement'])) {
                $filePath = dirname(__DIR__) . '/uploads/' . $contratData['justificatif_paiement'];
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }

            // Delete locataires
            $pdo->prepare("DELETE FROM locataires WHERE contrat_id = ?")->execute([$contractId]);
            // Delete logs
            try {
                $pdo->prepare("DELETE FROM logs WHERE contrat_id = ?")->execute([$contractId]);
            } catch (Exception $e) { /* table or column may not exist */ }
            // Delete the contract
            $pdo->prepare("DELETE FROM contrats WHERE id = ?")->execute([$contractId]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Erreur lors de la suppression : " . $e->getMessage();
            header('Location: contrat-detail.php?id=' . $contractId);
            exit;
        }

        $_SESSION['success'] = "Contrat supprimé avec succès.";
        header('Location: contrats.php');
        exit;
    }

    if ($_POST['action'] === 'generate_signalement_token') {
        $locataireId = (int)($_POST['locataire_id'] ?? 0);
        if ($locataireId > 0) {
            // Verify the locataire belongs to this contract
            $check = $pdo->prepare("SELECT id FROM locataires WHERE id = ? AND contrat_id = ?");
            $check->execute([$locataireId, $contractId]);
            if ($check->fetch()) {
                // Check if token_signalement column exists (migration 081)
                $colCheck = $pdo->query("
                    SELECT COLUMN_NAME FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'locataires'
                    AND COLUMN_NAME = 'token_signalement'
                ");
                if ($colCheck->fetch()) {
                    $newToken = bin2hex(random_bytes(32));
                    $pdo->prepare("UPDATE locataires SET token_signalement = ? WHERE id = ?")
                        ->execute([$newToken, $locataireId]);
                    $_SESSION['success'] = "Lien de signalement généré avec succès.";
                } else {
                    $_SESSION['error'] = "La migration 081 n'a pas encore été exécutée.";
                }
            }
        }
        header('Location: contrat-detail.php?id=' . $contractId);
        exit;
    }
}

// Get contract details
$contrat = fetchOne("
    SELECT c.*, 
           l.reference as logement_ref, 
           l.adresse as logement_adresse,
           
           l.type,
           l.surface,
           l.loyer,
           l.charges,
           l.depot_garantie,
           l.parking,
           (SELECT COUNT(*) FROM locataires WHERE contrat_id = c.id) as nb_locataires_total,
           (SELECT COUNT(*) FROM locataires WHERE contrat_id = c.id AND signature_data IS NOT NULL) as nb_locataires_signed
    FROM contrats c
    LEFT JOIN logements l ON c.logement_id = l.id
    WHERE c.id = ?
", [$contractId]);

if (!$contrat) {
    $_SESSION['error'] = "Contrat non trouvé.";
    header('Location: contrats.php');
    exit;
}

// Get tenants
$locataires = fetchAll("
    SELECT * FROM locataires 
    WHERE contrat_id = ? 
    ORDER BY ordre
", [$contractId]);

// Get validator info if exists
$validatorInfo = null;
if ($contrat['validated_by']) {
    $validatorInfo = fetchOne("SELECT nom, prenom FROM administrateurs WHERE id = ?", [$contrat['validated_by']]);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails du Contrat - <?php echo htmlspecialchars($contrat['reference_unique']); ?></title>
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
        .detail-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .detail-card h5 {
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3498db;
        }
        .info-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #ecf0f1;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            color: #2c3e50;
            width: 200px;
            flex-shrink: 0;
        }
        .info-value {
            color: #34495e;
        }
        .status-badge {
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 0.9rem;
            font-weight: 500;
            display: inline-block;
        }
        .status-en_attente { background: #fff3cd; color: #856404; }
        .status-signe { background: #cfe2ff; color: #084298; }
        .status-en_verification { background: #fff3cd; color: #664d03; }
        .status-valide { background: #d4edda; color: #155724; }
        .status-expire { background: #f8d7da; color: #721c24; }
        .status-annule { background: #e2e3e5; color: #383d41; }
        .tenant-card {
            background: #f8f9fa;
            border-left: 4px solid #3498db;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        .signature-preview {
            max-width: 300px;
            max-height: 150px;
            border: none;
            border-radius: 4px;
            padding: 5px;
        }
        .action-section {
            background: #e8f4f8;
            border-left: 4px solid #3498db;
            padding: 20px;
            border-radius: 4px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4><i class="bi bi-file-earmark-text"></i> Détails du Contrat</h4>
                    <p class="mb-0 text-muted">Référence: <strong><?php echo htmlspecialchars($contrat['reference_unique']); ?></strong></p>
                </div>
                <div>
                    <a href="contrats.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Retour
                    </a>
                    <?php if ($contrat['statut'] === 'valide' || $contrat['statut'] === 'signe'): ?>
                        <a href="../pdf/download.php?contrat_id=<?php echo $contrat['id']; ?>" class="btn btn-success">
                            <i class="bi bi-download"></i> Télécharger PDF
                        </a>
                        <a href="gestion-loyers.php?contrat_id=<?php echo $contrat['id']; ?>" class="btn btn-warning">
                            <i class="bi bi-cash-stack"></i> Gestion du loyer
                        </a>
                    <?php endif; ?>
                    <?php if ($contrat['statut'] === 'valide'): ?>
                        <a href="generer-quittances.php?id=<?php echo $contrat['id']; ?>" class="btn btn-primary">
                            <i class="bi bi-receipt"></i> Générer une quittance
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6">
                <!-- Contract Information -->
                <div class="detail-card">
                    <h5><i class="bi bi-file-earmark-check"></i> Informations du Contrat</h5>
                    <div class="info-row">
                        <div class="info-label">Statut</div>
                        <div class="info-value">
                            <span class="status-badge status-<?php echo $contrat['statut']; ?>">
                                <?php
                                $statut_labels = [
                                    'en_attente' => 'En attente de signature',
                                    'signe' => 'Signé par le client',
                                    'en_verification' => 'En vérification',
                                    'valide' => 'Validé',
                                    'expire' => 'Expiré',
                                    'annule' => 'Annulé',
                                    'fin' => 'Clôturé (remise des clés)'
                                ];
                                echo $statut_labels[$contrat['statut']] ?? $contrat['statut'];
                                ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Nombre de locataires</div>
                        <div class="info-value"><?php echo $contrat['nb_locataires']; ?> (<?php echo $contrat['nb_locataires_signed']; ?> signé(s))</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Date de création</div>
                        <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($contrat['date_creation'])); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Date d'expiration lien</div>
                        <div class="info-value">
                            <?php if ($contrat['date_expiration']): ?>
                                <?php echo date('d/m/Y H:i', strtotime($contrat['date_expiration'])); ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Date de signature</div>
                        <div class="info-value">
                            <?php if ($contrat['date_signature']): ?>
                                <?php echo date('d/m/Y H:i', strtotime($contrat['date_signature'])); ?>
                            <?php else: ?>
                                <span class="text-muted">Non signé</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (isset($contrat['date_validation']) && $contrat['date_validation']): ?>
                    <div class="info-row">
                        <div class="info-label">Date de validation</div>
                        <div class="info-value">
                            <?php echo date('d/m/Y H:i', strtotime($contrat['date_validation'])); ?>
                            <?php if ($validatorInfo): ?>
                                <br><small class="text-muted">Par: <?php echo htmlspecialchars($validatorInfo['prenom'] . ' ' . $validatorInfo['nom']); ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($contrat['date_prise_effet']): ?>
                    <div class="info-row">
                        <div class="info-label">Date de prise d'effet</div>
                        <div class="info-value"><?php echo date('d/m/Y', strtotime($contrat['date_prise_effet'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (isset($contrat['validation_notes']) && $contrat['validation_notes']): ?>
                    <div class="info-row">
                        <div class="info-label">Notes de validation</div>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($contrat['validation_notes'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (isset($contrat['motif_annulation']) && $contrat['motif_annulation']): ?>
                    <div class="info-row">
                        <div class="info-label">Motif d'annulation</div>
                        <div class="info-value text-danger"><?php echo nl2br(htmlspecialchars($contrat['motif_annulation'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-6">
                <!-- Property Information -->
                <div class="detail-card">
                    <h5><i class="bi bi-building"></i> Informations du Logement</h5>
                    <div class="info-row">
                        <div class="info-label">Référence</div>
                        <div class="info-value"><?php echo htmlspecialchars($contrat['logement_ref']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Adresse</div>
                        <div class="info-value"><?php echo htmlspecialchars($contrat['logement_adresse']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Type</div>
                        <div class="info-value"><?php echo htmlspecialchars($contrat['type']); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Surface</div>
                        <div class="info-value"><?php echo htmlspecialchars($contrat['surface']); ?> m²</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Loyer</div>
                        <div class="info-value"><?php echo number_format($contrat['loyer'], 2, ',', ' '); ?> €</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Charges</div>
                        <div class="info-value"><?php echo number_format($contrat['charges'], 2, ',', ' '); ?> €</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Loyer total</div>
                        <div class="info-value"><strong><?php echo number_format($contrat['loyer'] + $contrat['charges'], 2, ',', ' '); ?> €</strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Dépôt de garantie</div>
                        <div class="info-value"><?php echo number_format($contrat['depot_garantie'], 2, ',', ' '); ?> €</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Parking</div>
                        <div class="info-value"><?php echo htmlspecialchars($contrat['parking']); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tenants Information -->
        <div class="detail-card">
            <h5><i class="bi bi-people"></i> Locataires</h5>
            <?php if (empty($locataires)): ?>
                <p class="text-muted">Aucun locataire enregistré.</p>
            <?php else: ?>
                <?php foreach ($locataires as $locataire): ?>
                    <div class="tenant-card">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Locataire <?php echo $locataire['ordre']; ?>
                                    <a href="edit-locataire.php?id=<?php echo $locataire['id']; ?>" 
                                       class="btn btn-sm btn-outline-primary ms-2">
                                        <i class="bi bi-pencil"></i> Modifier
                                    </a>
                                </h6>
                                <div class="info-row">
                                    <div class="info-label">Nom</div>
                                    <div class="info-value"><?php echo htmlspecialchars($locataire['nom']); ?></div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Prénom</div>
                                    <div class="info-value"><?php echo htmlspecialchars($locataire['prenom']); ?></div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Date de naissance</div>
                                    <div class="info-value"><?php echo date('d/m/Y', strtotime($locataire['date_naissance'])); ?></div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Email</div>
                                    <div class="info-value"><?php echo htmlspecialchars($locataire['email']); ?></div>
                                </div>
                                <?php if ($locataire['telephone']): ?>
                                <div class="info-row">
                                    <div class="info-label">Téléphone</div>
                                    <div class="info-value"><?php echo htmlspecialchars($locataire['telephone']); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <?php if ($locataire['signature_timestamp']): ?>
                                    <div class="mb-3">
                                        <strong><i class="bi bi-check-circle text-success"></i> Signature validée</strong>
                                        <br><small class="text-muted">Le <?php echo date('d/m/Y H:i', strtotime($locataire['signature_timestamp'])); ?></small>
                                        <br><small class="text-muted">IP: <?php echo htmlspecialchars($locataire['signature_ip']); ?></small>
                                    </div>
                                    <?php if ($locataire['signature_data']): ?>
                                        <div>
                                            <strong>Aperçu de la signature:</strong><br>
                                            <?php 
                                            // Fix path for admin-v2 directory - prepend ../ for relative paths
                                            // Relative paths are those that don't start with 'data:', 'http://', 'https://', or '/'
                                            // Using preg_match for consistency with PDF generation code
                                            $signatureSrc = $locataire['signature_data'];
                                            $isDataUri = strpos($signatureSrc, 'data:') === 0;
                                            $isHttpUrl = preg_match('/^https?:\/\//', $signatureSrc); // Case-sensitive, no /i flag needed
                                            $isAbsolutePath = strpos($signatureSrc, '/') === 0;
                                            
                                            if (!$isDataUri && !$isHttpUrl && !$isAbsolutePath) {
                                                // Relative path - prepend ../ to make it relative to admin-v2
                                                $signatureSrc = '../' . $signatureSrc;
                                            }
                                            ?>
                                            <img src="<?php echo htmlspecialchars($signatureSrc); ?>" 
                                                 alt="Signature" 
                                                 class="signature-preview">
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="alert alert-warning mb-0">
                                        <i class="bi bi-exclamation-triangle"></i> Non signé
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($locataire['piece_identite_recto']): ?>
                                    <div class="mt-3">
                                        <strong><i class="bi bi-check-circle text-success"></i> Pièce d'identité uploadée</strong>
                                    </div>
                                <?php else: ?>
                                    <div class="mt-3">
                                        <i class="bi bi-x-circle text-danger"></i> Pièce d'identité non uploadée
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($locataire['mention_lu_approuve']): ?>
                                    <div class="mt-2">
                                        <i class="bi bi-check-circle text-success"></i> Mention "Lu et approuvé" validée
                                    </div>
                                <?php endif; ?>

                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Documents Section -->
        <div class="detail-card">
            <h5><i class="bi bi-file-earmark-text"></i> Documents Envoyés</h5>
            <?php
            // Helper function to check if tenant has documents
            function tenantHasDocuments($locataire) {
                return !empty($locataire['piece_identite_recto']) || 
                       !empty($locataire['piece_identite_verso']) || 
                       !empty($locataire['preuve_paiement_depot']);
            }
            
            // Helper function to validate and sanitize filename for security
            function validateAndSanitizeFilename($filename) {
                if (empty($filename)) {
                    return null;
                }
                
                // Security: Prevent directory traversal attacks
                // basename() removes any directory components, keeping only the filename
                // This is defense-in-depth: basename already removes .., /, and \
                $filename = basename($filename);
                
                // Verify the filename is not empty after sanitization
                if (empty($filename)) {
                    return null;
                }
                
                return $filename;
            }
            
            // Helper function to validate file path is within uploads directory
            function validateFilePath($relativePath) {
                $uploadsDir = dirname(__DIR__) . '/uploads/';
                $fullPath = $uploadsDir . $relativePath;
                
                // Get real paths for comparison
                $realUploadsDir = realpath($uploadsDir);
                $realFilePath = realpath($fullPath);
                
                // Check if uploads directory exists and is accessible
                if ($realUploadsDir === false) {
                    return null;
                }
                
                // If file doesn't exist or is inaccessible, realpath returns false
                if ($realFilePath === false) {
                    return null;
                }
                
                // Ensure the resolved path is within the uploads directory
                if (strpos($realFilePath, $realUploadsDir) !== 0) {
                    return null;
                }
                
                return $fullPath;
            }
            
            // Helper function to render document card
            function renderDocumentCard($documentPath, $title, $icon) {
                $safePath = validateAndSanitizeFilename($documentPath);
                if (!$safePath) {
                    return;
                }
                
                // Validate the file path is within uploads and exists
                $validatedPath = validateFilePath($safePath);
                $fileExists = ($validatedPath !== null);
                
                // Only construct relative path if file is validated
                if (!$fileExists) {
                    echo '<div class="col-md-4 mb-3">';
                    echo '    <div class="card">';
                    echo '        <div class="card-body">';
                    echo '            <h6 class="card-title"><i class="bi bi-' . htmlspecialchars($icon) . '"></i> ' . htmlspecialchars($title) . '</h6>';
                    echo '            <p class="text-muted small mb-0">Fichier non disponible</p>';
                    echo '        </div>';
                    echo '    </div>';
                    echo '</div>';
                    return;
                }
                
                $extension = strtolower(pathinfo($safePath, PATHINFO_EXTENSION));
                $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'gif']);
                $relativePath = '../uploads/' . $safePath;
                
                echo '<div class="col-md-4 mb-3">';
                echo '    <div class="card">';
                echo '        <div class="card-body">';
                echo '            <h6 class="card-title"><i class="bi bi-' . htmlspecialchars($icon) . '"></i> ' . htmlspecialchars($title) . '</h6>';
                
                // Show image preview if it's an image
                if ($isImage) {
                    echo '            <img src="' . htmlspecialchars($relativePath) . '" class="img-fluid mb-2" style="max-height: 150px; object-fit: cover;" alt="' . htmlspecialchars($title) . '">';
                }
                
                // Download button (file exists and is validated)
                echo '            <a href="' . htmlspecialchars($relativePath) . '" ';
                echo '               class="btn btn-sm btn-primary" ';
                echo '               download>';
                echo '                <i class="bi bi-download"></i> Télécharger';
                echo '            </a>';
                
                echo '        </div>';
                echo '    </div>';
                echo '</div>';
            }
            
            // Check if any tenant has documents or if contract has justificatif
            $hasDocuments = false;
            foreach ($locataires as $locataire) {
                if (tenantHasDocuments($locataire)) {
                    $hasDocuments = true;
                    break;
                }
            }
            
            // Check if contract has justificatif de paiement
            $hasContractJustificatif = !empty($contrat['justificatif_paiement']);
            $hasContractAssurance = !empty($contrat['assurance_habitation']);
            $hasAnyDocuments = $hasDocuments || $hasContractJustificatif || $hasContractAssurance;
            
            if (!$hasAnyDocuments): ?>
                <p class="text-muted">Aucun document envoyé pour le moment.</p>
            <?php else: ?>
                <?php if ($hasContractJustificatif): ?>
                    <div class="mb-4">
                        <h6><i class="bi bi-receipt"></i> Justificatif de dépôt de garantie</h6>
                        <?php if (!empty($contrat['date_envoi_justificatif'])): ?>
                            <p class="text-muted small mb-2">
                                Envoyé le <?php echo date('d/m/Y à H:i', strtotime($contrat['date_envoi_justificatif'])); ?>
                            </p>
                        <?php endif; ?>
                        <div class="row mt-2">
                            <?php
                            renderDocumentCard($contrat['justificatif_paiement'], 'Justificatif de virement du dépôt de garantie', 'receipt');
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($hasContractAssurance): ?>
                    <div class="mb-4">
                        <h6><i class="bi bi-shield-check"></i> Assurance habitation &amp; Visale</h6>
                        <?php if (!empty($contrat['date_envoi_assurance'])): ?>
                            <p class="text-muted small mb-2">
                                Envoyé le <?php echo date('d/m/Y à H:i', strtotime($contrat['date_envoi_assurance'])); ?>
                            </p>
                        <?php endif; ?>
                        <?php if (!empty($contrat['numero_visale'])): ?>
                            <p class="mb-2"><strong>Numéro Visale :</strong> <?php echo htmlspecialchars($contrat['numero_visale']); ?></p>
                        <?php endif; ?>
                        <div class="row mt-2">
                            <?php
                            renderDocumentCard($contrat['assurance_habitation'], 'Attestation d\'assurance habitation', 'shield-check');
                            if (!empty($contrat['visa_certifie'])) {
                                renderDocumentCard($contrat['visa_certifie'], 'Visa certifié Visale', 'patch-check');
                            }
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php foreach ($locataires as $locataire): ?>
                    <?php if (!tenantHasDocuments($locataire)) continue; ?>
                    <div class="mb-4">
                        <h6><i class="bi bi-person"></i> Locataire <?php echo $locataire['ordre']; ?> - <?php echo htmlspecialchars($locataire['prenom'] . ' ' . $locataire['nom']); ?></h6>
                        <div class="row mt-2">
                            <?php
                            if (!empty($locataire['piece_identite_recto'])) {
                                renderDocumentCard($locataire['piece_identite_recto'], "Pièce d'identité (Recto)", 'card-image');
                            }
                            if (!empty($locataire['piece_identite_verso'])) {
                                renderDocumentCard($locataire['piece_identite_verso'], "Pièce d'identité (Verso)", 'card-image');
                            }
                            if (!empty($locataire['preuve_paiement_depot'])) {
                                renderDocumentCard($locataire['preuve_paiement_depot'], 'Justificatif de paiement', 'receipt');
                            }
                            ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
		
		<!-- État des lieux Section -->
		<div class="detail-card mt-4">
            <?php
            $stmtEdl = $pdo->prepare("SELECT * FROM etats_lieux WHERE contrat_id = ? ORDER BY type, date_etat DESC");
            $stmtEdl->execute([$contrat['id']]);
            $etats_lieux_list = $stmtEdl->fetchAll(PDO::FETCH_ASSOC);
            $edl_entree = null;
            $edl_sortie = null;
            foreach ($etats_lieux_list as $edl) {
                if ($edl['type'] === 'entree' && !$edl_entree) $edl_entree = $edl;
                elseif ($edl['type'] === 'sortie' && !$edl_sortie) $edl_sortie = $edl;
            }
            ?>
            <h5><i class="bi bi-house-check"></i> État des lieux</h5>
            <?php $edlBadgeClass = ['brouillon'=>'secondary','finalise'=>'info','envoye'=>'success']; ?>
            <div class="row mt-2">
                <!-- EDL Entrée -->
                <div class="col-md-6 mb-3">
                    <div class="card <?php echo $edl_entree ? 'border-success' : 'border-secondary'; ?>">
                        <div class="card-header <?php echo $edl_entree ? 'bg-success text-white' : 'bg-secondary text-white'; ?>">
                            <h6 class="mb-0"><i class="bi bi-box-arrow-in-right"></i> État des lieux d'Entrée</h6>
                        </div>
                        <div class="card-body">
                            <?php if ($edl_entree): ?>
                                <p class="mb-2">
                                    <strong>Référence:</strong> <?php echo htmlspecialchars($edl_entree['reference_unique'] ?? 'N/A'); ?><br>
                                    <strong>Date:</strong> <?php echo date('d/m/Y', strtotime($edl_entree['date_etat'])); ?><br>
                                    <strong>Statut:</strong>
                                    <?php $s = $edl_entree['statut'] ?? 'brouillon'; ?>
                                    <span class="badge bg-<?php echo $edlBadgeClass[$s] ?? 'secondary'; ?>"><?php echo htmlspecialchars(ucfirst($s)); ?></span>
                                </p>
                                <div class="btn-group w-100" role="group">
                                    <a href="edit-etat-lieux.php?id=<?php echo $edl_entree['id']; ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil"></i> Modifier</a>
                                    <a href="download-etat-lieux.php?id=<?php echo $edl_entree['id']; ?>" class="btn btn-sm btn-info" target="_blank"><i class="bi bi-file-pdf"></i> PDF</a>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-3">Aucun état des lieux d'entrée créé.</p>
                                <a href="create-etat-lieux.php?contrat_id=<?php echo $contractId; ?>&type=entree" class="btn btn-sm btn-success w-100">
                                    <i class="bi bi-plus-circle"></i> Créer l'état des lieux d'entrée
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- EDL Sortie -->
                <div class="col-md-6 mb-3">
                    <div class="card <?php echo $edl_sortie ? 'border-danger' : 'border-secondary'; ?>">
                        <div class="card-header <?php echo $edl_sortie ? 'bg-danger text-white' : 'bg-secondary text-white'; ?>">
                            <h6 class="mb-0"><i class="bi bi-box-arrow-right"></i> État des lieux de Sortie</h6>
                        </div>
                        <div class="card-body">
                            <?php if ($edl_sortie): ?>
                                <p class="mb-2">
                                    <strong>Référence:</strong> <?php echo htmlspecialchars($edl_sortie['reference_unique'] ?? 'N/A'); ?><br>
                                    <strong>Date:</strong> <?php echo date('d/m/Y', strtotime($edl_sortie['date_etat'])); ?><br>
                                    <strong>Statut:</strong>
                                    <?php $s2 = $edl_sortie['statut'] ?? 'brouillon'; ?>
                                    <span class="badge bg-<?php echo $edlBadgeClass[$s2] ?? 'secondary'; ?>"><?php echo htmlspecialchars(ucfirst($s2)); ?></span>
                                </p>
                                <div class="btn-group w-100" role="group">
                                    <a href="edit-etat-lieux.php?id=<?php echo $edl_sortie['id']; ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil"></i> Modifier</a>
                                    <a href="download-etat-lieux.php?id=<?php echo $edl_sortie['id']; ?>" class="btn btn-sm btn-info" target="_blank"><i class="bi bi-file-pdf"></i> PDF</a>
                                    <?php if ($edl_entree): ?>
                                    <a href="compare-etat-lieux.php?contrat_id=<?php echo $contractId; ?>" class="btn btn-sm btn-warning"><i class="bi bi-arrow-left-right"></i> Comparer</a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-3">Aucun état des lieux de sortie créé.</p>
                                <?php if ($edl_entree): ?>
                                <a href="create-etat-lieux.php?contrat_id=<?php echo $contractId; ?>&type=sortie" class="btn btn-sm btn-danger w-100">
                                    <i class="bi bi-plus-circle"></i> Créer l'état des lieux de sortie
                                </a>
                                <?php else: ?>
                                <p class="text-muted small">Créez d'abord l'état des lieux d'entrée</p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inventaire Section -->
        <?php if ($contrat['statut'] === 'valide'): ?>
        <div class="detail-card mt-4">
            <h5><i class="bi bi-clipboard-check"></i> Inventaire</h5>
            <?php
            // Fetch inventaires for this contract
            $stmt = $pdo->prepare("
                SELECT * FROM inventaires 
                WHERE contrat_id = ? 
                ORDER BY type, date_inventaire DESC
            ");
            $stmt->execute([$contrat['id']]);
            $inventaires = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $inventaire_entree = null;
            $inventaire_sortie = null;
            foreach ($inventaires as $inv) {
                if ($inv['type'] === 'entree') {
                    $inventaire_entree = $inv;
                } elseif ($inv['type'] === 'sortie') {
                    $inventaire_sortie = $inv;
                }
            }
            ?>
            
            <div class="row mt-3">
                <!-- Entry Inventory -->
                <div class="col-md-6 mb-3">
                    <div class="card <?php echo $inventaire_entree ? 'border-success' : 'border-secondary'; ?>">
                        <div class="card-header <?php echo $inventaire_entree ? 'bg-success text-white' : 'bg-secondary text-white'; ?>">
                            <h6 class="mb-0"><i class="bi bi-box-arrow-in-right"></i> Inventaire d'Entrée</h6>
                        </div>
                        <div class="card-body">
                            <?php if ($inventaire_entree): ?>
                                <p class="mb-2">
                                    <strong>Référence:</strong> <?php echo htmlspecialchars($inventaire_entree['reference_unique']); ?><br>
                                    <strong>Date:</strong> <?php echo date('d/m/Y', strtotime($inventaire_entree['date_inventaire'])); ?><br>
                                    <strong>Statut:</strong> 
                                    <span class="badge bg-<?php echo $inventaire_entree['statut'] === 'finalise' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($inventaire_entree['statut']); ?>
                                    </span>
                                </p>
                                <div class="btn-group w-100" role="group">
                                    <a href="edit-inventaire.php?id=<?php echo $inventaire_entree['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="bi bi-pencil"></i> Modifier
                                    </a>
                                    <a href="download-inventaire.php?id=<?php echo $inventaire_entree['id']; ?>" class="btn btn-sm btn-info" target="_blank">
                                        <i class="bi bi-file-pdf"></i> PDF
                                    </a>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-3">Aucun inventaire d'entrée créé.</p>
                                <form method="POST" action="create-inventaire.php" class="d-inline">
                                    <input type="hidden" name="logement_id" value="<?php echo $contrat['logement_id']; ?>">
                                    <input type="hidden" name="type" value="entree">
                                    <input type="hidden" name="date_inventaire" value="<?php echo date('Y-m-d'); ?>">
                                    <button type="submit" class="btn btn-sm btn-success w-100">
                                        <i class="bi bi-plus-circle"></i> Créer l'inventaire d'entrée
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Exit Inventory -->
                <div class="col-md-6 mb-3">
                    <div class="card <?php echo $inventaire_sortie ? 'border-danger' : 'border-secondary'; ?>">
                        <div class="card-header <?php echo $inventaire_sortie ? 'bg-danger text-white' : 'bg-secondary text-white'; ?>">
                            <h6 class="mb-0"><i class="bi bi-box-arrow-right"></i> Inventaire de Sortie</h6>
                        </div>
                        <div class="card-body">
                            <?php if ($inventaire_sortie): ?>
                                <p class="mb-2">
                                    <strong>Référence:</strong> <?php echo htmlspecialchars($inventaire_sortie['reference_unique']); ?><br>
                                    <strong>Date:</strong> <?php echo date('d/m/Y', strtotime($inventaire_sortie['date_inventaire'])); ?><br>
                                    <strong>Statut:</strong> 
                                    <span class="badge bg-<?php echo $inventaire_sortie['statut'] === 'finalise' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($inventaire_sortie['statut']); ?>
                                    </span>
                                </p>
                                <div class="btn-group w-100" role="group">
                                    <a href="edit-inventaire.php?id=<?php echo $inventaire_sortie['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="bi bi-pencil"></i> Modifier
                                    </a>
                                    <a href="download-inventaire.php?id=<?php echo $inventaire_sortie['id']; ?>" class="btn btn-sm btn-info" target="_blank">
                                        <i class="bi bi-file-pdf"></i> PDF
                                    </a>
                                    <?php if ($inventaire_entree): ?>
                                    <a href="compare-inventaire.php?entree=<?php echo $inventaire_entree['id']; ?>&sortie=<?php echo $inventaire_sortie['id']; ?>" class="btn btn-sm btn-warning">
                                        <i class="bi bi-arrow-left-right"></i> Comparer
                                    </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-3">Aucun inventaire de sortie créé.</p>
                                <?php if ($inventaire_entree): ?>
                                <form method="POST" action="create-inventaire.php" class="d-inline">
                                    <input type="hidden" name="logement_id" value="<?php echo $contrat['logement_id']; ?>">
                                    <input type="hidden" name="type" value="sortie">
                                    <input type="hidden" name="date_inventaire" value="<?php echo date('Y-m-d'); ?>">
                                    <button type="submit" class="btn btn-sm btn-danger w-100">
                                        <i class="bi bi-plus-circle"></i> Créer l'inventaire de sortie
                                    </button>
                                </form>
                                <?php else: ?>
                                <p class="text-muted small">Créez d'abord l'inventaire d'entrée</p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="alert alert-info mt-3">
                <i class="bi bi-info-circle"></i> 
                <strong>Information:</strong> L'inventaire utilise désormais un formulaire standardisé conforme au cahier des charges. 
                Tous les logements utilisent le même modèle d'inventaire.
            </div>
        </div>
		
        <div class="detail-card mt-4">
            <h5><i class="bi bi-clipboard-check"></i> Bilan du Logement</h5>


            <div class="row mt-4">
                <div class="col-12">
                    <div class="card border-warning">
                        <div class="card-header bg-warning text-dark">
                            <h6 class="mb-0"><i class="bi bi-clipboard-check"></i> Bilan du Logement</h6>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Le bilan du logement centralise les dégradations constatées lors de la sortie et les données des inventaires.</p>
                            <a href="edit-bilan-logement.php?contrat_id=<?php echo $contractId; ?>" class="btn btn-warning w-100">
                                <i class="bi bi-clipboard-check"></i> Accéder au Bilan du Logement
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Action Section for signed contracts -->
        <?php if ($contrat['statut'] === 'signe'): ?>
        <div class="action-section">
            <h5><i class="bi bi-clipboard-check"></i> Actions de Vérification</h5>
            <p>Le contrat a été signé par le client. Vous devez maintenant vérifier les informations et:</p>
            <ul>
                <li><strong>Valider</strong> le contrat si tout est correct (la signature électronique de la société sera ajoutée automatiquement)</li>
                <li><strong>Annuler</strong> le contrat si des corrections sont nécessaires (possibilité de régénérer un nouveau contrat)</li>
            </ul>
            
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card border-success">
                        <div class="card-header bg-success text-white">
                            <h6 class="mb-0"><i class="bi bi-check-circle"></i> Valider le Contrat</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" onsubmit="return confirm('Êtes-vous sûr de vouloir valider ce contrat ?\n\nCette action ajoutera la signature électronique de la société au PDF et notifiera le client.');">
                                <input type="hidden" name="action" value="validate">
                                <div class="mb-3">
                                    <label for="validation_notes" class="form-label">Notes de validation (optionnel)</label>
                                    <textarea 
                                        class="form-control" 
                                        id="validation_notes" 
                                        name="validation_notes" 
                                        rows="3" 
                                        placeholder="Notes internes..."></textarea>
                                </div>
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-check-circle"></i> Valider le Contrat
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card border-danger">
                        <div class="card-header bg-danger text-white">
                            <h6 class="mb-0"><i class="bi bi-x-circle"></i> Annuler le Contrat</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" onsubmit="return confirm('Êtes-vous sûr de vouloir annuler ce contrat ?\n\nLe client sera notifié de l\'annulation.');">
                                <input type="hidden" name="action" value="cancel">
                                <div class="mb-3">
                                    <label for="motif_annulation" class="form-label">Motif d'annulation <span class="text-danger">*</span></label>
                                    <textarea 
                                        class="form-control" 
                                        id="motif_annulation" 
                                        name="motif_annulation" 
                                        rows="3" 
                                        placeholder="Raison de l'annulation (sera communiquée au client)..."
                                        required></textarea>
                                </div>
                                <button type="submit" class="btn btn-danger w-100">
                                    <i class="bi bi-x-circle"></i> Annuler le Contrat
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Supprimer le contrat (toujours visible) -->
        <div class="detail-card mt-4">
            <h5><i class="bi bi-trash text-danger"></i> Supprimer le Contrat</h5>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Action irréversible !</strong> La suppression efface définitivement le contrat et toutes les données associées (locataires, documents, logs). Cette action ne peut pas être annulée.
            </div>
            <form method="POST" action="" onsubmit="return confirm('⚠️ ATTENTION\n\nVous êtes sur le point de supprimer définitivement ce contrat.\n\nCette action est IRRÉVERSIBLE.\n\nÊtes-vous absolument certain de vouloir continuer ?');">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn-outline-danger">
                    <i class="bi bi-trash3"></i> Supprimer définitivement ce contrat
                </button>
            </form>
        </div>

        <!-- Departure confirmation & End of contract actions -->
        <?php if ($contrat['statut'] === 'valide' && !empty($contrat['date_demande_depart'])): ?>
        <div class="detail-card mt-4">
            <h5><i class="bi bi-door-open"></i> Procédure de Départ</h5>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                Le locataire a initié la procédure de départ le
                <strong><?php echo date('d/m/Y à H:i', strtotime($contrat['date_demande_depart'])); ?></strong>.
            </div>
            <div class="row mt-3">
                <div class="col-md-12 mb-3">
                    <div class="card border-info">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0"><i class="bi bi-envelope-check"></i> Confirmation Réception AR24</h6>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Envoyer au locataire la confirmation que nous avons bien reçu son courrier envoyé via AR24.</p>
                            <button type="button" class="btn btn-info w-100"
                                    onclick="openAR24Modal(<?php echo $contractId; ?>, 'detail', '<?php echo htmlspecialchars($contrat['date_fin_prevue'] ?? '', ENT_QUOTES); ?>')">
                                <i class="bi bi-envelope-check"></i> Envoyer confirmation AR24
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Departure confirmation & End of contract actions -->
        <?php if ($contrat['statut'] === 'valide' && !empty($contrat['date_demande_depart'])): ?>
        <div class="detail-card mt-4">
            <h5><i class="bi bi-door-open"></i> Cloôture du contrat</h5>
            <div class="row mt-3">
                <div class="col-md-12 mb-3">
                    <div class="card border-dark">
                        <div class="card-header bg-dark text-white">
                            <h6 class="mb-0"><i class="bi bi-door-closed"></i> Fin de Contrat</h6>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Clôturer le contrat une fois que le locataire a remis les clés du logement.</p>
                            <form method="POST" action="fin-contrat.php"
                                  onsubmit="return confirm('Confirmer la fin du contrat suite à la remise des clés ?\n\nLe contrat sera clôturé et le logement remis en disponibilité.')">
                                <input type="hidden" name="contrat_id" value="<?php echo $contractId; ?>">
                                <input type="hidden" name="source" value="detail">
                                <button type="submit" class="btn btn-dark w-100">
                                    <i class="bi bi-door-closed"></i> Fin de contrat (remise des clés)
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Date Editing Section -->
        <div class="detail-card">
            <h5><i class="bi bi-calendar-date"></i> Modifier les dates du contrat</h5>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_dates">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="date_prise_effet" class="form-label">Date de prise d'effet (date du contrat)</label>
                        <input type="date" class="form-control" id="date_prise_effet" name="date_prise_effet"
                               value="<?php echo htmlspecialchars($contrat['date_prise_effet'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="date_fin_prevue" class="form-label">Date de fin prévue</label>
                        <input type="date" class="form-control" id="date_fin_prevue" name="date_fin_prevue"
                               value="<?php echo htmlspecialchars($contrat['date_fin_prevue'] ?? ''); ?>">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Enregistrer les dates
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

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
                        <input type="hidden" name="source" id="ar24Source" value="detail">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-info text-white"><i class="bi bi-envelope-check"></i> Envoyer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openAR24Modal(contratId, source, dateFin) {
            document.getElementById('ar24ContratId').value = contratId;
            document.getElementById('ar24Source').value = source || 'detail';
            document.getElementById('ar24DateFin').value = dateFin || '';
            var modal = new bootstrap.Modal(document.getElementById('ar24Modal'));
            modal.show();
        }
        function copySignalementLink(locataireId) {
            var input = document.getElementById('sig-link-' + locataireId);
            if (input) {
                input.select();
                navigator.clipboard.writeText(input.value).then(function() {
                    alert('Lien copié dans le presse-papier !');
                }).catch(function() {
                    document.execCommand('copy');
                });
            }
        }
    </script>
</body>
</html>
