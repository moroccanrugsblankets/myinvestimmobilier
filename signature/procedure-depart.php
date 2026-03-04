<?php
/**
 * Procédure de départ - Page publique
 * Accessible via le lien envoyé dans l'email "Contrat validé"
 *
 * URL: /signature/procedure-depart.php?token=<contrat_token>
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail-templates.php';

$token = trim($_GET['token'] ?? '');
$error = '';
$success = false;

if (empty($token)) {
    $error = 'Lien invalide. Veuillez utiliser le lien fourni dans votre email.';
} else {
    // Find the contract by its token (reference_unique)
    $stmt = $pdo->prepare("
        SELECT c.*, l.reference as logement_ref, l.adresse as logement_adresse
        FROM contrats c
        INNER JOIN logements l ON c.logement_id = l.id
        WHERE c.reference_unique = ? AND c.statut = 'valide'
    ");
    $stmt->execute([$token]);
    $contrat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contrat) {
        $error = 'Contrat introuvable ou inactif. Veuillez contacter votre gestionnaire.';
    }
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Token CSRF invalide.';
    } else {
        $contratId = $contrat['id'];

        // Fetch the first tenant for this contract
        $stmtTenant = $pdo->prepare("
            SELECT nom, prenom, email FROM locataires
            WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 1
        ");
        $stmtTenant->execute([$contratId]);
        $tenant = $stmtTenant->fetch(PDO::FETCH_ASSOC);

        // Store departure request date on the contract
        $pdo->prepare("UPDATE contrats SET date_demande_depart = NOW() WHERE id = ?")->execute([$contratId]);

        // Log the departure request
        $stmtLog = $pdo->prepare("
            INSERT INTO logs (type_entite, entite_id, action, details, ip_address, created_at)
            VALUES ('contrat', ?, 'demande_procedure_depart', ?, ?, NOW())
        ");
        $stmtLog->execute([
            $contratId,
            'Demande de procédure de départ initiée par le locataire',
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);

        $logement = $contrat['logement_ref'] . ' - ' . $contrat['logement_adresse'];
        $dateDemande = date('d/m/Y');

        // Send confirmation email to the tenant with admin BCC (admins hidden via BCC)
        if ($tenant && !empty($tenant['email'])) {
            $templateSent = sendTemplatedEmail(
                'procedure_depart_client',
                $tenant['email'],
                [
                    'prenom'       => $tenant['prenom'],
                    'nom'          => $tenant['nom'],
                    'logement'     => $logement,
                    'reference'    => $contrat['reference_unique'],
                    'date_demande' => $dateDemande,
                ],
                null,
                false,
                true, // addAdminBcc
                ['contexte' => 'contrat_id=' . $contratId]
            );
            // Fallback to basic email if template not found
            if ($templateSent === false) {
                $tenantSubject = 'Confirmation de votre demande de procédure de départ';
                $tenantBody = '<p>Bonjour ' . htmlspecialchars($tenant['prenom']) . ',</p>'
                    . '<p>Nous avons bien reçu votre demande de procédure de départ pour le logement :</p>'
                    . '<p><strong>' . htmlspecialchars($logement) . '</strong><br>'
                    . 'Référence contrat : ' . htmlspecialchars($contrat['reference_unique']) . '<br>'
                    . 'Date de la demande : ' . $dateDemande . '</p>'
                    . '<p>Notre équipe prendra contact avec vous prochainement pour organiser :</p>'
                    . '<ul><li>L\'état des lieux de sortie</li><li>La restitution des clés</li><li>Le remboursement du dépôt de garantie</li></ul>'
                    . '<p>Cordialement,<br>My Invest Immobilier</p>';
                sendEmail($tenant['email'], $tenantSubject, $tenantBody, null, true, false, null, null, true, ['contexte' => 'contrat_id=' . $contratId]);
            }
        }

        $success = true;
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procédure de départ - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container mt-5">
        <div class="text-center mb-4">
            <img src="../assets/images/logo.png" alt="My Invest Immobilier" class="logo mb-3"
                 onerror="this.style.display='none'">
            <h1 class="h2">Procédure de départ</h1>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-7">
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php elseif ($success): ?>
                    <div class="card shadow">
                        <div class="card-body text-center p-5">
                            <div class="mb-3" style="font-size: 4rem;">✅</div>
                            <h3 class="text-success">Demande enregistrée</h3>
                            <p class="mt-3">Nous venons de vous adresser un e-mail vous informant de la procédure à suivre pour votre départ du logement.</p>
                            <p>Merci de bien vouloir en prendre connaissance.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card shadow">
                        <div class="card-body">
                            <p>Vous êtes sur le point d'initier la procédure de départ pour le logement :</p>
                            <div class="alert alert-info">
                                <strong><?= htmlspecialchars($contrat['logement_ref'] . ' - ' . $contrat['logement_adresse']) ?></strong>
                            </div>
                            <p>En confirmant, vous lancerez la procédure de départ.</p>

                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        🚪 Confirmer la demande de départ
                                    </button>
                                    <a href="/index.php" class="btn btn-outline-secondary">
                                        Annuler
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
