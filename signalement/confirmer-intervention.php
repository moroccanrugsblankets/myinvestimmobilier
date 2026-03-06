<?php
/**
 * Page de confirmation d'intervention par le locataire
 *
 * URL: /signalement/confirmer-intervention.php?sig=xxx&token=xxx
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail-templates.php';

$sigId = (int)($_GET['sig'] ?? 0);
$token = trim($_GET['token'] ?? '');

if ($sigId <= 0 || empty($token)) {
    http_response_code(400);
    die('Lien invalide.');
}

// Charger le signalement et valider le token locataire
$stmt = $pdo->prepare("
    SELECT sig.id, sig.reference, sig.titre, sig.statut,
           l.adresse,
           l.reference as logement_reference,
           loc.token_signalement,
           CONCAT(loc.prenom, ' ', loc.nom) AS locataire_nom
    FROM signalements sig
    INNER JOIN logements l ON sig.logement_id = l.id
    LEFT JOIN locataires loc ON sig.locataire_id = loc.id
    WHERE sig.id = ? AND loc.token_signalement = ?
    LIMIT 1
");
$stmt->execute([$sigId, $token]);
$sig = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sig) {
    http_response_code(404);
    die('Lien invalide ou expiré.');
}

$alreadyConfirmed = ($sig['statut'] === 'clos');
$confirmed        = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyConfirmed) {
    // Clore le signalement
    $pdo->prepare("UPDATE signalements SET statut = 'clos', date_cloture = COALESCE(date_cloture, NOW()), updated_at = NOW() WHERE id = ?")
        ->execute([$sigId]);
    $pdo->prepare("
        INSERT INTO signalements_actions (signalement_id, type_action, description, acteur, ip_address)
        VALUES (?, 'cloture', 'Intervention confirmée par le locataire — dossier clos', ?, ?)
    ")->execute([$sigId, $sig['locataire_nom'], $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    $confirmed = true;

    // ── Notifier les admins et le service technique ────────────────────────
    $siteUrl     = rtrim($config['SITE_URL'] ?? '', '/');
    $lienAdmin   = $siteUrl . '/admin-v2/signalement-detail.php?id=' . $sigId;
    $companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';

    $notifVars = [
        'reference'       => $sig['reference'],
        'titre'           => $sig['titre'],
        'adresse'         => $sig['adresse'],
        'locataire_nom'   => $sig['locataire_nom'],
        'date_confirmation' => date('d/m/Y à H:i'),
        'lien_admin'      => $lienAdmin,
        'company'         => $companyName,
    ];

    // Send to all admins (isAdminEmail=true adds all admins as BCC automatically)
    $allAdminEmails = [];
    try {
        $stmtAdm = $pdo->query("SELECT email FROM administrateurs WHERE actif = 1 AND email IS NOT NULL AND email != ''");
        $allAdminEmails = $stmtAdm->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        error_log('confirmer-intervention: could not fetch admin emails: ' . $e->getMessage());
    }
    $configAdminEmail = $config['ADMIN_EMAIL'] ?? '';
    if (!empty($configAdminEmail) && !in_array(strtolower($configAdminEmail), array_map('strtolower', $allAdminEmails))) {
        array_unshift($allAdminEmails, $configAdminEmail);
    }

    if (!empty($allAdminEmails)) {
        $primaryAdmin = $allAdminEmails[0];
        if (filter_var($primaryAdmin, FILTER_VALIDATE_EMAIL)) {
            sendTemplatedEmail(
                'signalement_intervention_confirmee_admin',
                $primaryAdmin,
                $notifVars,
                null, true, false,
                ['contexte' => 'intervention_confirmee_locataire;sig_id=' . $sigId]
            );
        }
    }

    // Notify service technique separately
    $stEmail = getServiceTechniqueEmail();
    $allAdminEmailsLower = array_map('strtolower', $allAdminEmails);
    if ($stEmail && filter_var($stEmail, FILTER_VALIDATE_EMAIL)
        && !in_array(strtolower($stEmail), $allAdminEmailsLower)) {
        sendTemplatedEmail(
            'signalement_intervention_confirmee_admin',
            $stEmail,
            $notifVars,
            null, false, false,
            ['contexte' => 'intervention_confirmee_locataire_st;sig_id=' . $sigId]
        );
    }
}

$companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmation d'intervention</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .confirm-card { max-width: 560px; margin: 60px auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.12); overflow: hidden; }
        .confirm-header { padding: 30px; text-align: center; color: #fff; background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%); }
        .confirm-body { padding: 30px; }
    </style>
</head>
<body>
    <div class="confirm-card">
        <div class="confirm-header">
            <h1 class="h3 mb-1">✅ Confirmation d'intervention</h1>
            <p class="mb-0 opacity-75"><?php echo htmlspecialchars($companyName); ?></p>
        </div>
        <div class="confirm-body">
            <div class="mb-4">
                <small class="text-muted d-block">Référence</small>
                <strong class="font-monospace"><?php echo htmlspecialchars($sig['reference']); ?></strong>
                <br>
                <small class="text-muted d-block mt-2">Titre</small>
                <strong><?php echo htmlspecialchars($sig['titre']); ?></strong>
                <br>
                <small class="text-muted d-block mt-2">Logement</small>
                <?php echo htmlspecialchars($sig['adresse']); ?>
                <?php if (!empty($sig['logement_reference'])): ?>
                    &nbsp;<span class="badge bg-secondary font-monospace"><?php echo htmlspecialchars($sig['logement_reference']); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($confirmed || $alreadyConfirmed): ?>
                <div class="alert alert-success text-center py-4">
                    <i class="bi bi-check-circle-fill" style="font-size:2.5rem;display:block;margin-bottom:10px;"></i>
                    <strong>Merci !</strong><br>
                    Votre confirmation a bien été enregistrée. Le dossier est maintenant clos.
                </div>
            <?php else: ?>
                <p>En cliquant sur le bouton ci-dessous, vous confirmez que l'intervention a bien été réalisée à votre satisfaction.</p>
                <form method="POST" class="d-grid">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-check-circle me-2"></i>Confirmer l'intervention
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
