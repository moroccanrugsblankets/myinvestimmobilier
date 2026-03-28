<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/mail-templates.php';

// Ensure this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

// Get contract ID from POST data
$data = json_decode(file_get_contents('php://input'), true);
$contrat_id = isset($data['contrat_id']) ? (int)$data['contrat_id'] : 0;

if (!$contrat_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de contrat manquant']);
    exit;
}

try {
    // Fetch contract information
    $stmt = $pdo->prepare("
        SELECT c.*, l.adresse, l.type_contrat, COALESCE(l.dpe_file, '') as dpe_file,
               ca.email, ca.nom, ca.prenom
        FROM contrats c
        LEFT JOIN logements l ON c.logement_id = l.id
        LEFT JOIN candidatures ca ON c.candidature_id = ca.id
        WHERE c.id = ?
    ");
    $stmt->execute([$contrat_id]);
    $contrat = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contrat) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Contrat non trouvé']);
        exit;
    }
    
    // Only allow resending for contracts in 'en_attente' status
    if ($contrat['statut'] !== 'en_attente') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Le contrat n\'est plus en attente de signature']);
        exit;
    }
    
    // Check if we have an email
    if (empty($contrat['email'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Aucun email associé au contrat']);
        exit;
    }
    
    // Check if token exists, if not create one
    $token = $contrat['token_signature'] ?? $contrat['reference_unique'];
    
    // If no token exists, generate one
    if (empty($token)) {
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("UPDATE contrats SET token_signature = ? WHERE id = ?");
        $stmt->execute([$token, $contrat_id]);
    }
    
    // Update expiration date using parameter value
    $expiryHours = getParameter('delai_expiration_lien_contrat', 24);
    $date_expiration = (new DateTime())->modify('+' . $expiryHours . ' hours')->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare("UPDATE contrats SET date_expiration = ? WHERE id = ?");
    $stmt->execute([$date_expiration, $contrat_id]);
    
    // Create signature link
    $signature_link = $config['SITE_URL'] . '/signature/index.php?token=' . $token;
    
    // Format expiration date for email (e.g., "02/02/2026 à 15:30")
    $date_expiration_formatted = date('d/m/Y à H:i', strtotime($date_expiration));

    // Compute duree_garantie dynamically from type_contrat
    $dureeGarantie = getDureeGarantie($contrat['type_contrat'] ?? 'meuble') . ' mois';

    // Generate a secure download link for the DPE file (no direct attachment)
    $lienDpe = '';
    if (!empty($contrat['dpe_file'])) {
        $dpePath = dirname(__DIR__) . '/' . $contrat['dpe_file'];
        $tokenUrl = createDocumentToken($dpePath, 'dpe', 'DPE.pdf');
        if ($tokenUrl) {
            $lienDpe = $tokenUrl;
        }
    }
    
    // Préparer les variables pour le template
    $variables = [
        'nom' => $contrat['nom'],
        'prenom' => $contrat['prenom'],
        'email' => $contrat['email'],
        'adresse' => $contrat['adresse'],
        'lien_signature' => $signature_link,
        'date_expiration_lien_contrat' => $date_expiration_formatted,
        'duree_garantie' => $dureeGarantie,
        'lien_telechargement_dpe' => $lienDpe,
    ];

    // Send invitation email (no attachment – DPE download link is in the template variable)
    $emailSent = sendTemplatedEmail('contrat_signature', $contrat['email'], $variables, null, true, false, ['contexte' => 'contrat_id=' . $contrat_id]);
    
    if ($emailSent) {
        // Log the action
        $stmt = $pdo->prepare("
            INSERT INTO logs (type_entite, entite_id, action, details, ip_address, created_at)
            VALUES ('contrat', ?, 'Lien renvoyé', ?, ?, NOW())
        ");
        $stmt->execute([
            $contrat_id,
            "Lien de signature renvoyé à " . $contrat['email'],
            $_SERVER['REMOTE_ADDR']
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Le lien de signature a été renvoyé avec succès à ' . $contrat['email']
        ]);
    } else {
        throw new Exception('Erreur lors de l\'envoi de l\'email');
    }
    
} catch (Exception $e) {
    error_log("Erreur lors du renvoi du lien: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erreur lors de l\'envoi: ' . $e->getMessage()
    ]);
}
