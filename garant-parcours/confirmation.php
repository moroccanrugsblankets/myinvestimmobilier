<?php
/**
 * Garant – Page de confirmation finale
 *
 * Affiche soit la confirmation de finalisation, soit le refus d'engagement.
 *
 * My Invest Immobilier
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$isRefusal = !empty($_SESSION['garant_refused']);
unset($_SESSION['garant_refused']);

$garant = null;
if (!$isRefusal) {
    if (empty($_SESSION['garant_id'])) {
        die('Session invalide.');
    }
    $garantId = (int)$_SESSION['garant_id'];
    $garant   = fetchOne("
        SELECT g.*,
               l.adresse AS adresse_logement
        FROM garants g
        INNER JOIN contrats c ON g.contrat_id = c.id
        INNER JOIN logements l ON c.logement_id = l.id
        WHERE g.id = ?
    ", [$garantId]);

    if (!$garant) {
        die('Données introuvables.');
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmation garant – My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="container mt-5 mb-5">
    <div class="text-center mb-4">
        <img src="../assets/images/logo.png" alt="My Invest Immobilier" class="logo mb-3"
             onerror="this.style.display='none'">
        <h1 class="h2">Espace garant</h1>
    </div>

    <div class="row justify-content-center">
        <div class="col-md-8">
            <?php if ($isRefusal): ?>
            <div class="card shadow border-warning">
                <div class="card-body text-center p-5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="70" height="70" fill="currentColor"
                         class="bi bi-x-circle-fill text-warning mb-4" viewBox="0 0 16 16">
                        <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293 5.354 4.646z"/>
                    </svg>
                    <h2 class="text-warning mb-3">Refus enregistré</h2>
                    <p class="lead">Votre refus a été pris en compte.</p>
                    <p>Le locataire et l'agence seront informés. La procédure est terminée.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="card shadow border-success">
                <div class="card-body text-center p-5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" fill="currentColor"
                         class="bi bi-patch-check-fill text-success mb-4" viewBox="0 0 16 16">
                        <path d="M10.067.87a2.89 2.89 0 0 0-4.134 0l-.622.638-.89-.011a2.89 2.89 0 0 0-2.924 2.924l.01.89-.636.622a2.89 2.89 0 0 0 0 4.134l.637.622-.011.89a2.89 2.89 0 0 0 2.924 2.924l.89-.01.622.636a2.89 2.89 0 0 0 4.134 0l.622-.637.89.011a2.89 2.89 0 0 0 2.924-2.924l-.01-.89.636-.622a2.89 2.89 0 0 0 0-4.134l-.637-.622.011-.89a2.89 2.89 0 0 0-2.924-2.924l-.89.01-.622-.636zm.287 5.984-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7 8.793l2.646-2.647a.5.5 0 0 1 .708.708z"/>
                    </svg>

                    <h2 class="text-success mb-3">Dossier garant validé !</h2>
                    <p class="lead mb-4">Votre dossier de caution solidaire a été finalisé avec succès.</p>

                    <div class="alert alert-light text-start d-inline-block" style="min-width:280px;">
                        <p class="mb-1"><strong>Logement :</strong> <?= htmlspecialchars($garant['adresse_logement']) ?></p>
                        <p class="mb-1"><strong>Garant :</strong> <?= htmlspecialchars($garant['prenom'] . ' ' . $garant['nom']) ?></p>
                        <p class="mb-0"><strong>Statut :</strong>
                            <span class="badge <?= htmlspecialchars(getGarantStatutBadgeClass($garant['statut'])) ?>">
                                <?= htmlspecialchars(formatGarantStatut($garant['statut'])) ?>
                            </span>
                        </p>
                    </div>

                    <p class="mt-4">
                        Vous recevrez par email un lien pour consulter le document de caution solidaire.
                    </p>
                    <p class="text-muted">
                        Pour toute question, contactez-nous à
                        <a href="mailto:<?= htmlspecialchars($config['COMPANY_EMAIL'] ?? '') ?>">
                            <?= htmlspecialchars($config['COMPANY_EMAIL'] ?? $config['MAIL_FROM'] ?? '') ?>
                        </a>.
                    </p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
