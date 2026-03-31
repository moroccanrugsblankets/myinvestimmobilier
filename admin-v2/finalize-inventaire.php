<?php
/**
 * Finalize and Send Inventaire
 * My Invest Immobilier
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Get inventaire ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Handle finalization BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'finalize') {
    error_log("=== FINALIZE INVENTAIRE - POST REQUEST ===");
    error_log("Action: finalize");
    
    // Need to fetch inventaire data for processing
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT inv.*, 
                       c.id as contrat_id,
                       c.reference_unique as contrat_ref,
                       l.adresse as logement_adresse
                FROM inventaires inv
                LEFT JOIN contrats c ON inv.contrat_id = c.id
                LEFT JOIN logements l ON inv.logement_id = l.id
                WHERE inv.id = ?
            ");
            $stmt->execute([$id]);
            $inventaire = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inventaire) {
                error_log("Starting transaction...");
                $pdo->beginTransaction();
                
                // Fetch all tenants associated with this inventaire
                $stmt = $pdo->prepare("SELECT * FROM inventaire_locataires WHERE inventaire_id = ? ORDER BY id ASC");
                $stmt->execute([$id]);
                $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($tenants)) {
                    error_log("ERROR: No tenants found for inventaire ID: $id");
                    throw new Exception("Aucun locataire trouvé pour cet inventaire");
                }
                
                // Check if PDF generation function exists
                if (!file_exists(__DIR__ . '/../pdf/generate-inventaire.php')) {
                    error_log("ERROR: PDF generation file not found");
                    throw new Exception("La génération PDF pour les inventaires n'est pas encore implémentée. Veuillez d'abord créer le fichier pdf/generate-inventaire.php");
                }
                
                require_once __DIR__ . '/../pdf/generate-inventaire.php';
                
                // Generate PDF
                error_log("Generating PDF for inventaire_id: " . $inventaire['id'] . ", type: " . $inventaire['type']);
                $pdfPath = generateInventairePDF($inventaire['id']);
                
                if (!$pdfPath || !file_exists($pdfPath)) {
                    error_log("ERROR: PDF generation failed. Path returned: " . ($pdfPath ?? 'NULL'));
                    throw new Exception("Erreur lors de la génération du PDF");
                }
                
                error_log("PDF generated successfully: " . $pdfPath);
                error_log("PDF file size: " . filesize($pdfPath) . " bytes");
                
                // Determine template ID based on inventory type
                $templateId = ($inventaire['type'] === 'sortie') ? 'inventaire_sortie_envoye' : 'inventaire_entree_envoye';
                error_log("Using email template: " . $templateId);
                
                // Determine type label for email
                $typeLabel = ($inventaire['type'] === 'entree') ? 'Entrée' : 'Sortie';

                // Use physical URL for the PDF download link
                $lienTelechargement = documentPathToUrl($pdfPath);
                
                // Send email to each tenant with admin in BCC
                $emailsSent = [];
                $emailsFailed = [];
                
                foreach ($tenants as $tenant) {
                    if (empty($tenant['email'])) {
                        error_log("WARNING: Tenant ID " . (int)$tenant['id'] . " has no email address");
                        continue;
                    }
                    
                    $emailVariables = [
                        'locataire_nom' => trim($tenant['prenom'] . ' ' . $tenant['nom']),
                        'adresse' => $inventaire['adresse'],
                        'date_inventaire' => date('d/m/Y', strtotime($inventaire['date_inventaire'])),
                        'reference' => $inventaire['reference_unique'] ?? 'N/A',
                        'type' => $typeLabel,
                        'lien_telechargement' => $lienTelechargement,
                    ];
                    
                    // Sanitize email for logging to prevent log injection
                    $safeEmail = str_replace(["\r", "\n"], '', $tenant['email']);
                    error_log("Sending email to tenant: " . $safeEmail . " with template: $templateId");
                    
                    // Send email to tenant using template with admin in BCC (no attachment)
                    $emailSent = sendTemplatedEmail($templateId, $tenant['email'], $emailVariables, null, false, true);
                    
                    if ($emailSent) {
                        $emailsSent[] = $tenant['email'];
                        error_log("Email sent successfully to tenant: " . $safeEmail);
                    } else {
                        $emailsFailed[] = $tenant['email'];
                        error_log("ERROR: Failed to send email to tenant: " . $safeEmail);
                    }
                }
                
                if (empty($emailsSent)) {
                    error_log("ERROR: Failed to send emails to any tenants");
                    throw new Exception("Erreur lors de l'envoi des emails aux locataires");
                }
                
                // Sanitize email arrays for logging to prevent log injection
                $sanitizedEmailsSent = array_map(function($email) {
                    return str_replace(["\r", "\n"], '', $email);
                }, $emailsSent);
                
                if (!empty($emailsFailed)) {
                    $sanitizedEmailsFailed = array_map(function($email) {
                        return str_replace(["\r", "\n"], '', $email);
                    }, $emailsFailed);
                    error_log("WARNING: Some emails failed to send: " . implode(', ', $sanitizedEmailsFailed));
                }
                
                error_log("Emails sent successfully to: " . implode(', ', $sanitizedEmailsSent) . " with admin in BCC!");
                
                // Update status
                error_log("Updating database status...");
                $stmt = $pdo->prepare("
                    UPDATE inventaires 
                    SET statut = 'envoye', 
                        email_envoye = TRUE, 
                        date_envoi_email = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$id]);
                error_log("Database updated successfully");
                
                $pdo->commit();
                error_log("Transaction committed");
                
                // Clean up temporary PDF if needed
                if (strpos($pdfPath, '/tmp/') !== false) {
                    error_log("Cleaning up temporary PDF: " . $pdfPath);
                    @unlink($pdfPath);
                }
                
                error_log("=== FINALIZE INVENTAIRE - SUCCESS ===");
                $sanitizedEmails = array_map('htmlspecialchars', $emailsSent);
                $successMsg = "Inventaire finalisé et envoyé avec succès à " . implode(', ', $sanitizedEmails);
                if (!empty($emailsFailed)) {
                    $sanitizedFailedEmails = array_map('htmlspecialchars', $emailsFailed);
                    $successMsg .= " (Échec pour : " . implode(', ', $sanitizedFailedEmails) . ")";
                }
                $_SESSION['success'] = $successMsg;
                header('Location: inventaires.php');
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                error_log("Rolling back transaction...");
                $pdo->rollBack();
            }
            error_log("=== FINALIZE INVENTAIRE - ERROR ===");
            error_log("Exception type: " . get_class($e));
            error_log("Error message: " . $e->getMessage());
            error_log("Error code: " . $e->getCode());
            error_log("Error file: " . $e->getFile() . ":" . $e->getLine());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            $_SESSION['error'] = "Erreur lors de la finalisation: " . $e->getMessage();
        }
    }
}

// Log the request
error_log("=== FINALIZE INVENTAIRE - START ===");
error_log("Requested ID: " . $id);

if ($id < 1) {
    error_log("ERROR: Invalid ID provided - " . $id);
    $_SESSION['error'] = "ID de l'inventaire invalide";
    header('Location: inventaires.php');
    exit;
}

// Get inventaire details
try {
    error_log("Fetching inventaire from database with ID: " . $id);
    
    $stmt = $pdo->prepare("
        SELECT inv.*, 
               c.id as contrat_id,
               c.reference_unique as contrat_ref,
               l.adresse as logement_adresse
        FROM inventaires inv
        LEFT JOIN contrats c ON inv.contrat_id = c.id
        LEFT JOIN logements l ON inv.logement_id = l.id
        WHERE inv.id = ?
    ");
    $stmt->execute([$id]);
    $inventaire = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inventaire) {
        error_log("ERROR: Inventaire not found in database for ID: " . $id);
        $_SESSION['error'] = "Inventaire non trouvé";
        header('Location: inventaires.php');
        exit;
    }
    
    // Log retrieved data for debugging
    error_log("Inventaire found - ID: " . $inventaire['id']);
    error_log("Contrat ID: " . ($inventaire['contrat_id'] ?? 'NULL'));
    error_log("Type: " . ($inventaire['type'] ?? 'NULL'));
    error_log("Reference unique: " . ($inventaire['reference_unique'] ?? 'NULL'));
    error_log("Locataire email: " . ($inventaire['locataire_email'] ?? 'NULL'));
    error_log("Locataire nom complet: " . ($inventaire['locataire_nom_complet'] ?? 'NULL'));
    error_log("Adresse: " . ($inventaire['adresse'] ?? 'NULL'));
    error_log("Date inventaire: " . ($inventaire['date_inventaire'] ?? 'NULL'));
    error_log("Contrat ref: " . ($inventaire['contrat_ref'] ?? 'NULL'));
    
    // Fix missing address from logement if available
    $needsUpdate = false;
    $fieldsToUpdate = [];
    
    if (empty($inventaire['adresse']) && !empty($inventaire['logement_adresse'])) {
        error_log("Address is NULL, populating from logement: " . $inventaire['logement_adresse']);
        $inventaire['adresse'] = $inventaire['logement_adresse'];
        $fieldsToUpdate['adresse'] = $inventaire['adresse'];
        $needsUpdate = true;
    }
    
    // Update database with all missing fields in a single query
    if ($needsUpdate) {
        // Whitelist of allowed fields to prevent SQL injection
        $allowedFields = ['adresse'];
        
        $setParts = [];
        $params = [];
        foreach ($fieldsToUpdate as $field => $value) {
            // Only allow whitelisted fields
            if (in_array($field, $allowedFields, true)) {
                $setParts[] = "`$field` = ?";
                $params[] = $value;
            }
        }
        
        if (!empty($setParts)) {
            $params[] = $id;
            $sql = "UPDATE inventaires SET " . implode(', ', $setParts) . " WHERE id = ?";
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute($params);
            error_log("Updated database with: " . implode(', ', array_keys($fieldsToUpdate)));
        }
    }
    
    // Fetch all tenants associated with this inventaire
    $stmt = $pdo->prepare("SELECT * FROM inventaire_locataires WHERE inventaire_id = ? ORDER BY id ASC");
    $stmt->execute([$id]);
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($tenants)) {
        error_log("WARNING: No tenants found in inventaire_locataires for inventaire ID: $id");
        // Fallback to single tenant from inventaire table if available
        if (!empty($inventaire['locataire_nom_complet']) && !empty($inventaire['locataire_email'])) {
            $tenants = [[
                'id' => null,
                'nom' => $inventaire['locataire_nom_complet'],
                'prenom' => '',
                'email' => $inventaire['locataire_email']
            ]];
        }
    }
    
    // Check for missing required fields
    $missingFields = [];
    $requiredFields = ['contrat_id', 'type', 'locataire_email', 'locataire_nom_complet', 'adresse', 'date_inventaire'];
    foreach ($requiredFields as $field) {
        if (empty($inventaire[$field])) {
            $missingFields[] = $field;
        }
    }
    
    if (!empty($missingFields)) {
        error_log("WARNING: Missing required fields: " . implode(', ', $missingFields));
    }
    
} catch (PDOException $e) {
    error_log("DATABASE ERROR while fetching inventaire: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $_SESSION['error'] = "Erreur de base de données: " . $e->getMessage();
    header('Location: inventaires.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finaliser Inventaire</title>
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
        .finalize-card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .info-item {
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .info-item:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            color: #6c757d;
            width: 200px;
            display: inline-block;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4>
                        <i class="bi bi-send-check"></i> Finaliser l'inventaire
                    </h4>
                    <p class="text-muted mb-0">Vérification avant envoi</p>
                </div>
                <a href="edit-inventaire.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Retour
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="finalize-card">
            <h5 class="mb-4">Récapitulatif</h5>
            
            <div class="info-item">
                <span class="info-label">Référence:</span>
                <?php echo htmlspecialchars($inventaire['reference_unique']); ?>
            </div>
            
            <div class="info-item">
                <span class="info-label">Date:</span>
                <?php echo date('d/m/Y', strtotime($inventaire['date_inventaire'])); ?>
            </div>
            
            <div class="info-item">
                <span class="info-label">Adresse:</span>
                <?php echo htmlspecialchars($inventaire['adresse']); ?>
            </div>
            
            <?php if (!empty($tenants)): ?>
                <?php if (count($tenants) === 1): ?>
                    <div class="info-item">
                        <span class="info-label">Locataire:</span>
                        <?php echo htmlspecialchars(trim($tenants[0]['prenom'] . ' ' . $tenants[0]['nom'])); ?>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Email du locataire:</span>
                        <?php echo htmlspecialchars($tenants[0]['email']); ?>
                    </div>
                <?php else: ?>
                    <div class="info-item">
                        <span class="info-label">Locataires:</span>
                        <ul class="mb-0 mt-1">
                            <?php foreach ($tenants as $tenant): ?>
                                <li><?php echo htmlspecialchars(trim($tenant['prenom'] . ' ' . $tenant['nom'])); ?> - <?php echo htmlspecialchars($tenant['email']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if (!empty($missingFields)): ?>
            <div class="alert alert-warning mt-3">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Attention:</strong> Champs manquants: <strong><?php echo implode(', ', $missingFields); ?></strong>
                <br><small>Il est recommandé de compléter ces informations avant de finaliser l'inventaire.</small>
            </div>
            <?php endif; ?>
            
            <hr class="my-4">
            
            <h5 class="mb-3">Envoi du document</h5>
            
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                <strong>Le PDF sera envoyé automatiquement à:</strong>
                <ul class="mb-0 mt-2">
                    <?php if (!empty($tenants)): ?>
                        <?php if (count($tenants) === 1): ?>
                            <li>Locataire: <?php echo htmlspecialchars($tenants[0]['email']); ?></li>
                        <?php else: ?>
                            <?php foreach ($tenants as $tenant): ?>
                                <li>Locataire: <?php echo htmlspecialchars($tenant['email']); ?></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="finalize">
                
                <div class="d-flex justify-content-between mt-4">
                    <a href="edit-inventaire.php?id=<?php echo $id; ?>" class="btn btn-secondary">
                        <i class="bi bi-pencil"></i> Modifier
                    </a>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-send-check"></i> Finaliser et envoyer par email
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
