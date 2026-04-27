<?php
/**
 * Cron Job - Process Rental Applications
 * 
 * This script processes applications that are 4 business days old and sends
 * automated acceptance or rejection emails based on criteria.
 * 
 * Setup: Run this script daily via cron
 * Example: 0 9 * * * /usr/bin/php /path/to/process-candidatures.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail-templates.php';

// Log file for cron execution
$logFile = __DIR__ . '/cron-log.txt';

function logMessage($message, $echoMessage = true) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] $message\n";
    file_put_contents($logFile, $logLine, FILE_APPEND);
    
    // Also echo to stdout for cron job visibility
    if ($echoMessage) {
        echo $message . "\n";
    }
}

logMessage("=== Starting candidature processing ===");

try {
    // Get flexible delay parameters from database (used for candidatures without scheduled_response_date)
    $delaiValeur = (int)getParameter('delai_reponse_valeur', 4);
    $delaiUnite = getParameter('delai_reponse_unite', 'jours');
    
    logMessage("Using automatic response delay: $delaiValeur $delaiUnite");
    
    // Get all candidatures pending automatic response
    // New behavior: Use scheduled_response_date if available, otherwise calculate from created_at
    // This query handles both old candidatures (no scheduled_response_date) and new ones
    $query = "
        SELECT c.* 
        FROM candidatures c
        WHERE c.reponse_automatique = 'en_attente'
        AND (
            (c.scheduled_response_date IS NOT NULL AND c.scheduled_response_date <= NOW())
            OR (c.scheduled_response_date IS NULL AND TIMESTAMPDIFF(HOUR, c.created_at, NOW()) >= ?)
        )
        ORDER BY c.created_at ASC
    ";
    
    // Calculate the delay in hours based on the unit (for backward compatibility)
    // Note: For 'jours', we use calendar days (24 hours) for the fallback calculation
    // New candidatures with scheduled_response_date will use business days (Mon-Fri)
    $hoursDelay = 0;
    if ($delaiUnite === 'jours') {
        $hoursDelay = $delaiValeur * 24;
    } elseif ($delaiUnite === 'heures') {
        $hoursDelay = $delaiValeur;
    } elseif ($delaiUnite === 'minutes') {
        $hoursDelay = $delaiValeur / 60;
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$hoursDelay]);
    $candidatures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    logMessage("Found " . count($candidatures) . " applications to process");
    
    foreach ($candidatures as $candidature) {
        $id = $candidature['id'];
        $email = $candidature['email'];
        $nom = $candidature['nom'];
        $prenom = $candidature['prenom'];
        $reference = $candidature['reference_unique'] ?? $candidature['reference_candidature'] ?? '';
        
        logMessage("Processing application #$id for $prenom $nom");
        
        // Evaluate acceptance criteria with NEW STRICTER RULES
        $result = evaluateCandidature($candidature);
        $accepted = $result['accepted'];
        $motifRefus = $result['motif'];
        
        if ($accepted) {
            // Send acceptance email using database template
            $logement = isset($candidature['logement_reference']) ? $candidature['logement_reference'] : 'Logement';
            $confirmUrl = $config['SITE_URL'] . "/candidature/confirmer-interet.php?ref=" . urlencode($reference);
            
            $variables = [
                'nom' => $nom,
                'prenom' => $prenom,
                'email' => $email,
                'logement' => $logement,
                'reference' => $reference,
                'date' => date('d/m/Y'),
                'lien_confirmation' => $confirmUrl
            ];
            
            // Send email using template BEFORE updating status
            if (sendTemplatedEmail('candidature_acceptee', $email, $variables)) {
                // Only update status if email was sent successfully
                $updateStmt = $pdo->prepare("UPDATE candidatures SET statut = 'accepte', reponse_automatique = 'accepte', date_reponse_auto = NOW(), date_reponse_envoyee = NOW() WHERE id = ?");
                $updateStmt->execute([$id]);
                
                logMessage("Acceptance email sent to $email for application #$id");
                
                // Log the action
                $logStmt = $pdo->prepare("INSERT INTO logs (type_entite, entite_id, action, details) VALUES (?, ?, ?, ?)");
                $logStmt->execute(['candidature', $id, 'email_acceptation', "Email d'acceptation envoyé à $email"]);
            } else {
                logMessage("ERROR: Failed to send acceptance email to $email - candidature #$id will be retried in next cron run");
                logMessage("Check SMTP configuration in config.php or review logs table for details");
                
                // Log the failure
                $logStmt = $pdo->prepare("INSERT INTO logs (type_entite, entite_id, action, details) VALUES (?, ?, ?, ?)");
                $logStmt->execute(['candidature', $id, 'email_error', "Échec de l'envoi de l'email d'acceptation à $email"]);
            }
            
        } else {
            // Send rejection email using database template
            $variables = [
                'nom' => $nom,
                'prenom' => $prenom,
                'email' => $email
            ];
            
            // Send email using template BEFORE updating status
            if (sendTemplatedEmail('candidature_refusee', $email, $variables, null, false, true)) {
                // Only update status if email was sent successfully
                $updateStmt = $pdo->prepare("UPDATE candidatures SET statut = 'refuse', reponse_automatique = 'refuse', motif_refus = ?, date_reponse_auto = NOW(), date_reponse_envoyee = NOW() WHERE id = ?");
                $updateStmt->execute([$motifRefus, $id]);
                
                logMessage("Rejection email sent to $email for application #$id. Reason: $motifRefus");
                
                // Log the action
                $logStmt = $pdo->prepare("INSERT INTO logs (type_entite, entite_id, action, details) VALUES (?, ?, ?, ?)");
                $logStmt->execute(['candidature', $id, 'email_refus', "Email de refus envoyé à $email. Motif: $motifRefus"]);
            } else {
                logMessage("ERROR: Failed to send rejection email to $email - candidature #$id will be retried in next cron run");
                logMessage("Check SMTP configuration in config.php or review logs table for details");
                
                // Log the failure
                $logStmt = $pdo->prepare("INSERT INTO logs (type_entite, entite_id, action, details) VALUES (?, ?, ?, ?)");
                $logStmt->execute(['candidature', $id, 'email_error', "Échec de l'envoi de l'email de refus à $email. Motif: $motifRefus"]);
            }
        }
    }
    
    logMessage("=== Processing complete ===");
    
} catch (Exception $e) {
    logMessage("ERROR: " . $e->getMessage());
    error_log("Cron error: " . $e->getMessage());
}
?>
