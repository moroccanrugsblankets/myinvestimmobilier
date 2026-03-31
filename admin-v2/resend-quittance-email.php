<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../pdf/generate-quittance.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: quittances.php');
    exit;
}

// Get quittance ID
$quittance_id = isset($_POST['quittance_id']) ? (int)$_POST['quittance_id'] : 0;

if (!$quittance_id) {
    $_SESSION['error'] = "ID de quittance invalide";
    header('Location: quittances.php');
    exit;
}

// Get quittance details
$stmt = $pdo->prepare("
    SELECT q.*, 
           c.reference_unique as contrat_ref,
           c.id as contrat_id,
           l.reference as logement_ref,
           l.adresse as logement_adresse,
           l.loyer,
           l.charges
    FROM quittances q
    INNER JOIN contrats c ON q.contrat_id = c.id
    LEFT JOIN logements l ON c.logement_id = l.id
    WHERE q.id = ?
");
$stmt->execute([$quittance_id]);
$quittance = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quittance) {
    $_SESSION['error'] = "Quittance non trouvée";
    header('Location: quittances.php');
    exit;
}

// Get locataires
$stmt = $pdo->prepare("
    SELECT * FROM locataires 
    WHERE contrat_id = ? 
    ORDER BY ordre
");
$stmt->execute([$quittance['contrat_id']]);
$locataires = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($locataires)) {
    $_SESSION['error'] = "Aucun locataire trouvé pour ce contrat";
    header('Location: quittances.php');
    exit;
}

// Month names
$nomsMois = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// Prepare email variables
$periode = $nomsMois[$quittance['mois']] . ' ' . $quittance['annee'];
$montantLoyer = number_format((float)$quittance['montant_loyer'], 2, ',', ' ');
$montantCharges = number_format((float)$quittance['montant_charges'], 2, ',', ' ');
$montantTotal = number_format((float)$quittance['montant_total'], 2, ',', ' ');

// Regenerate PDF with current template to ensure template modifications are applied
$result = generateQuittancePDF($quittance['contrat_id'], $quittance['mois'], $quittance['annee']);
if ($result) {
    $pdfPath = $result['filepath'];
    error_log("PDF régénéré avec succès pour le renvoi: " . $pdfPath);
} else {
    // Fallback to existing PDF if regeneration fails
    $pdfPath = $quittance['fichier_pdf'];
    error_log("Échec de la régénération du PDF, utilisation du fichier existant: " . $pdfPath);
}

$emailsSent = 0;
$emailsFailed = 0;

// Use physical URL for the quittance PDF download link
$lienQuittance = '';
if ($pdfPath && file_exists($pdfPath)) {
    $lienQuittance = documentPathToUrl($pdfPath);
}

// Send email to each tenant
foreach ($locataires as $locataire) {
    // Send email to tenant with BCC to administrators (no direct attachment)
    $emailSent = sendTemplatedEmail('quittance_envoyee', $locataire['email'], [
        'locataire_nom'                 => $locataire['nom'],
        'locataire_prenom'              => $locataire['prenom'],
        'adresse'                       => $quittance['logement_adresse'],
        'periode'                       => $periode,
        'montant_loyer'                 => $montantLoyer,
        'montant_charges'               => $montantCharges,
        'montant_total'                 => $montantTotal,
        'signature'                     => getParameter('email_signature', ''),
        'lien_telechargement_quittance' => $lienQuittance,
    ], null, false, true, ['contexte' => 'quittance_id=' . $quittance_id]);
    
    if ($emailSent) {
        $emailsSent++;
    } else {
        $emailsFailed++;
        error_log("Erreur renvoi email quittance à " . $locataire['email']);
    }
}

// Update quittance record
if ($emailsSent > 0) {
    $stmt = $pdo->prepare("UPDATE quittances SET email_envoye = 1, date_envoi_email = NOW() WHERE id = ?");
    $stmt->execute([$quittance_id]);
    
    // Log the action
    $stmt = $pdo->prepare("
        INSERT INTO logs (type_entite, entite_id, action, details, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        'autre',
        $quittance_id,
        'renvoi_quittance',
        "Renvoi de la quittance " . $quittance['reference_unique'] . " à " . $emailsSent . " locataire(s)"
    ]);
}

// Display success/error messages
if ($emailsSent > 0) {
    $_SESSION['success'] = "Email(s) renvoyé(s) avec succès à $emailsSent locataire(s)";
} else {
    $_SESSION['error'] = "Échec de l'envoi des emails";
}

if ($emailsFailed > 0) {
    $_SESSION['warning'] = "$emailsFailed email(s) n'ont pas pu être envoyés";
}

// Redirect back to the contract's quittances page if contrat_id is available
$redirect = 'quittances.php';
if (!empty($quittance['contrat_id'])) {
    $redirect = 'quittances.php?contrat_id=' . (int)$quittance['contrat_id'];
}
header('Location: ' . $redirect);
exit;
