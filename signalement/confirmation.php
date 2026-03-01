<?php
/**
 * Confirmation après soumission d'un signalement — Portail locataire
 *
 * URL: /signalement/confirmation.php?ref=ID&token=XXXXX
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$sigId = isset($_GET['ref']) ? (int)$_GET['ref'] : 0;

if (empty($token) || !preg_match('/^[a-f0-9]{64}$/', $token) || $sigId <= 0) {
    http_response_code(404);
    die('Lien invalide.');
}

try {
    $stmt = $pdo->prepare("
        SELECT sig.reference, sig.titre, sig.priorite, sig.date_signalement,
               l.adresse
        FROM signalements sig
        INNER JOIN logements l ON sig.logement_id = l.id
        INNER JOIN locataires loc ON sig.locataire_id = loc.id
        WHERE sig.id = ?
          AND loc.token_signalement = ?
        LIMIT 1
    ");
    $stmt->execute([$sigId, $token]);
    $sig = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('signalement/confirmation.php DB error: ' . $e->getMessage());
    http_response_code(500);
    die('Erreur interne.');
}

if (!$sig) {
    http_response_code(404);
    die('Signalement introuvable.');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signalement enregistré — <?php echo htmlspecialchars($config['COMPANY_NAME']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f0f4f8; }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card shadow border-0 rounded-4">
                <div class="card-body p-5 text-center">
                    <div class="text-success mb-3" style="font-size:72px;">✅</div>
                    <h2 class="text-success mb-2">Signalement enregistré !</h2>
                    <p class="text-muted mb-4">
                        Votre signalement a bien été transmis à l'équipe de gestion.
                        Un suivi vous sera communiqué dans les meilleurs délais.
                    </p>
                    <div class="alert alert-light border text-start">
                        <div class="mb-1">
                            <strong>Référence :</strong>
                            <span class="font-monospace"><?php echo htmlspecialchars($sig['reference']); ?></span>
                        </div>
                        <div class="mb-1">
                            <strong>Titre :</strong> <?php echo htmlspecialchars($sig['titre']); ?>
                        </div>
                        <div class="mb-1">
                            <strong>Priorité :</strong>
                            <?php if ($sig['priorite'] === 'urgent'): ?>
                                <span class="badge bg-danger">Urgent</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Normal</span>
                            <?php endif; ?>
                        </div>
                        <div class="mb-1">
                            <strong>Logement :</strong> <?php echo htmlspecialchars($sig['adresse']); ?>
                        </div>
                        <div>
                            <strong>Date :</strong>
                            <?php echo date('d/m/Y à H:i', strtotime($sig['date_signalement'])); ?>
                        </div>
                    </div>
                    <p class="text-muted small mt-3">
                        Conservez cette référence <strong><?php echo htmlspecialchars($sig['reference']); ?></strong>
                        pour tout suivi auprès de votre gestionnaire.
                    </p>
                    <a href="form.php?token=<?php echo urlencode($token); ?>" class="btn btn-outline-secondary mt-2">
                        <i class="bi bi-plus-circle me-1"></i>Nouveau signalement
                    </a>
                </div>
            </div>
            <p class="text-center mt-3 text-muted small">
                <?php echo htmlspecialchars($config['COMPANY_NAME']); ?>
            </p>
        </div>
    </div>
</div>
</body>
</html>
