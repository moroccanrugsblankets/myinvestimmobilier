<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/mail-templates.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: candidatures.php');
    exit;
}

$candidature_id = isset($_POST['candidature_id']) ? (int)$_POST['candidature_id'] : 0;
$nouveau_statut = isset($_POST['nouveau_statut']) ? trim($_POST['nouveau_statut']) : '';
$commentaire = isset($_POST['commentaire']) ? trim($_POST['commentaire']) : '';
$send_email = isset($_POST['send_email']);

if (!$candidature_id || !$nouveau_statut) {
    $_SESSION['error'] = "Données invalides";
    header('Location: candidatures.php');
    exit;
}

// Get current candidature (with logement reference for email templates)
$stmt = $pdo->prepare("SELECT c.*, l.reference as logement_reference FROM candidatures c LEFT JOIN logements l ON c.logement_id = l.id WHERE c.id = ?");
$stmt->execute([$candidature_id]);
$candidature = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$candidature) {
    $_SESSION['error'] = "Candidature non trouvée";
    header('Location: candidatures.php');
    exit;
}

$ancien_statut = $candidature['statut'];

// Update status and reponse_automatique field when manually changing to accepted or refused
if ($nouveau_statut === 'accepte' || $nouveau_statut === 'refuse') {
    // Set reponse_automatique to match the new status to prevent duplicate automatic processing
    if ($nouveau_statut === 'refuse') {
        // Calculate and store the scheduled response date for refused candidatures
        $createdDate = new DateTime($candidature['created_at']);
        $scheduledDate = calculateScheduledResponseDate($createdDate);
        $scheduledDateStr = $scheduledDate->format('Y-m-d H:i:s');
        
        $stmt = $pdo->prepare("UPDATE candidatures SET statut = ?, reponse_automatique = ?, date_reponse_auto = NOW(), scheduled_response_date = ? WHERE id = ?");
        $stmt->execute([$nouveau_statut, $nouveau_statut, $scheduledDateStr, $candidature_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE candidatures SET statut = ?, reponse_automatique = ?, date_reponse_auto = NOW() WHERE id = ?");
        $stmt->execute([$nouveau_statut, $nouveau_statut, $candidature_id]);
    }
} else {
    // For other statuses, only update the status field
    $stmt = $pdo->prepare("UPDATE candidatures SET statut = ? WHERE id = ?");
    $stmt->execute([$nouveau_statut, $candidature_id]);
}

// Log the action
$action = "Changement de statut: $ancien_statut → $nouveau_statut";
$details = $commentaire ? "Commentaire: $commentaire" : null;

$stmt = $pdo->prepare("
    INSERT INTO logs (type_entite, entite_id, action, details, ip_address, created_at)
    VALUES (?, ?, ?, ?, ?, NOW())
");
$stmt->execute([
    'candidature',
    $candidature_id,
    $action,
    $details,
    $_SERVER['REMOTE_ADDR']
]);

// Send email if requested
if ($send_email) {
    $to = $candidature['email'];
    $nom_complet = $candidature['prenom'] . ' ' . $candidature['nom'];
    
    // Map status to template identifier
    $templateMap = [
        'accepte' => 'candidature_acceptee',
        'refuse' => 'candidature_refusee',
        'refus_apres_visite' => 'statut_refus_apres_visite',
        'contrat_envoye' => 'statut_contrat_envoye',
        'contrat_signe' => 'statut_contrat_signe'
    ];
    
    $templateId = $templateMap[$nouveau_statut] ?? null;
    
    if ($templateId) {
        // Prepare variables for the template
        $variables = [
            'nom' => $candidature['nom'],
            'prenom' => $candidature['prenom'],
            'email' => $candidature['email'],
            'logement' => $candidature['logement_reference'] ?? '',
            'commentaire' => $commentaire ? '<p style="margin: 15px 0;"><strong>Note :</strong> ' . nl2br(htmlspecialchars($commentaire)) . '</p>' : ''
        ];
        
        // Send templated email (with admin BCC so admins receive a hidden copy)
        $emailSent = sendTemplatedEmail($templateId, $to, $variables, null, false, true, ['contexte' => 'candidature_id=' . $candidature_id]);
        
        if ($emailSent) {
            // Log email sent
            $stmt = $pdo->prepare("
                INSERT INTO logs (type_entite, entite_id, action, details, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                'candidature',
                $candidature_id,
                "Email envoyé",
                "Template: $templateId",
                $_SERVER['REMOTE_ADDR']
            ]);
        } else {
            error_log("Erreur lors de l'envoi de l'email à $to pour le changement de statut");
        }
    }
}

$_SESSION['success'] = "Statut mis à jour avec succès";
header('Location: candidature-detail.php?id=' . $candidature_id);
exit;
