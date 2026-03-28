<?php
/**
 * Signature - Page de confirmation
 * My Invest Immobilier
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Nettoyer la session
if (isset($_SESSION['signature_token'])) {
    unset($_SESSION['signature_token']);
    unset($_SESSION['contrat_id']);
    unset($_SESSION['current_locataire_id']);
    unset($_SESSION['current_locataire_numero']);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmation - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container mt-5">
        <div class="text-center mb-4">
            <img src="../assets/images/logo.png" alt="My Invest Immobilier" class="logo mb-3" 
                 onerror="this.style.display='none'">
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow border-success">
                    <div class="card-body text-center p-5">
                        <div class="mb-4">
                            <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" fill="currentColor" 
                                 class="bi bi-check-circle-fill text-success" viewBox="0 0 16 16">
                                <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                            </svg>
                        </div>

                        <h2 class="text-success mb-4">Félicitations !</h2>
                        
                        <p class="lead mb-4">
                            Votre bail a été signé avec succès.
                        </p>

                        <div class="alert alert-info text-start">
                            <h5>Prochaines étapes :</h5>
                            <ol class="mb-0">
                                <li class="mb-2">
                                    <strong>Vous recevrez 2 emails :</strong>
                                    <ul class="mt-2">
                                        <li>Un email de confirmation avec une copie de votre bail signé</li>
                                        <li>Un email vous demandant de transmettre le justificatif de virement du dépôt de garantie</li>
                                    </ul>
                                </li>
                                <li class="mb-2">
                                    <strong>Effectuez le virement du dépôt de garantie</strong> 
                                    sur le compte suivant :
                                    <div class="mt-2 p-3 bg-light rounded">
                                        <strong><?= $config['BANK_NAME'] ?></strong><br>
                                        IBAN : <?= $config['IBAN'] ?><br>
                                        BIC : <?= $config['BIC'] ?>
                                    </div>
                                </li>
                                <li class="mb-2">
                                    <strong>Transmettez le justificatif de virement</strong> par email à l'adresse indiquée dans l'email que vous recevrez.
                                </li>
                                <li class="mb-2">
                                    <strong>La prise d'effet du bail</strong> ainsi que la <strong>remise des clés</strong> 
                                    interviendront uniquement après réception et vérification du justificatif de paiement.
                                </li>
                            </ol>
                        </div>

                        <div class="alert alert-warning">
                            <strong>⚠️ Important :</strong> 
                            Le virement du dépôt de garantie doit être effectué immédiatement par virement bancaire instantané. Vous devrez ensuite nous transmettre le justificatif par email.
                        </div>

                        <p class="mt-4">
                            Pour toute question, n'hésitez pas à nous contacter :
                        </p>
                        <p>
                            <strong><?= $config['COMPANY_NAME'] ?></strong><br>
                            Email : <a href="mailto:<?= $config['COMPANY_EMAIL'] ?>"><?= $config['COMPANY_EMAIL'] ?></a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
