<?php
/**
 * Signature - Page d'accueil avec validation du lien
 * My Invest Immobilier
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Récupérer le token
$token = $_GET['token'] ?? '';

if (empty($token)) {
    die('Token manquant. Veuillez utiliser le lien fourni dans votre email.');
}

// Récupérer le contrat
$contrat = getContractByToken($token);

if (!$contrat) {
    die('Contrat non trouvé. Le lien est invalide.');
}

// Vérifier si le contrat est valide
if (DEBUG_MODE) {
    error_log("DEBUG: Validating contract expiration");
    error_log("DEBUG: Contract ID: " . ($contrat['id'] ?? 'N/A'));
    error_log("DEBUG: Contract status: " . ($contrat['statut'] ?? 'N/A'));
    error_log("DEBUG: Expiration date: " . ($contrat['date_expiration'] ?? 'N/A'));
    error_log("DEBUG: Current time: " . date('Y-m-d H:i:s'));
    error_log("DEBUG: Expiration timestamp: " . (isset($contrat['date_expiration']) ? strtotime($contrat['date_expiration']) : 'N/A'));
    error_log("DEBUG: Current timestamp: " . time());
}

if (!isContractValid($contrat)) {
    if ($contrat['statut'] === 'signe') {
        die('Ce contrat a déjà été signé.');
    } elseif ($contrat['statut'] === 'expire') {
        die('Ce lien a expiré. Veuillez contacter My Invest Immobilier.');
    } else {
        die('Ce lien a expiré. Il était valide jusqu\'au ' . formatDateFr($contrat['date_expiration'], 'd/m/Y à H:i'));
    }
}

// Stocker le token en session
$_SESSION['signature_token'] = $token;
$_SESSION['contrat_id'] = $contrat['id'];

// Clear any previous tenant session data to ensure proper flow
// This allows the defensive fallback in step1/step2/step3 to correctly determine next step
unset($_SESSION['current_locataire_id']);
unset($_SESSION['current_locataire_numero']);

// Traitement de l'acceptation/refus
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Token CSRF invalide.';
    } else {
        $choix = $_POST['choix'] ?? '';
        
        if ($choix === 'refuse') {
            // Enregistrer le refus
            executeQuery("UPDATE contrats SET statut = 'annule' WHERE id = ?", [$contrat['id']]);
            logAction($contrat['id'], 'refus_contrat', 'Le locataire a refusé le contrat');
            
            echo '<!DOCTYPE html>
            <html lang="fr">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Refus du contrat</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            </head>
            <body>
                <div class="container mt-5">
                    <div class="text-center">
                        <h1>Refus enregistré</h1>
                        <p class="lead">Votre refus a été enregistré. La procédure est terminée.</p>
                        <p>Si vous avez des questions, veuillez contacter My Invest Immobilier.</p>
                    </div>
                </div>
            </body>
            </html>';
            exit;
        } elseif ($choix === 'accepte') {
            // Rediriger vers l'étape 1
            logAction($contrat['id'], 'acceptation_contrat', 'Le locataire a accepté de poursuivre');
            header('Location: step1-info.php');
            exit;
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signature de bail - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container mt-5">
        <div class="text-center mb-4">
            <img src="../assets/images/logo.png" alt="My Invest Immobilier" class="logo mb-3" 
                 onerror="this.style.display='none'">
            <h1 class="h2">Signature de contrat de bail</h1>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-body">
                        <h4 class="card-title mb-4">Bienvenue dans le processus de signature</h4>
                        
                        <div class="alert alert-info">
                            <strong>Logement :</strong> <?= htmlspecialchars($contrat['reference']) ?><br>
                            <strong>Adresse :</strong> <?= htmlspecialchars($contrat['adresse']) ?><br>
                            <strong>Type :</strong> <?= htmlspecialchars($contrat['type']) ?><br>
                            <strong>Loyer :</strong> <?= formatMontant($contrat['loyer']) ?> + <?= formatMontant($contrat['charges']) ?> de charges
                        </div>

                        <div class="mb-4">
                            <h5>Procédure de signature</h5>
                            <p>Pour finaliser votre bail, vous devrez :</p>
                            <ol>
                                <li>La signature du contrat de bail en ligne</li>
                                <li>La transmission d'une pièce d'identité en cours de validité (carte nationale d'identité ou passeport)</li>
                                <li>Le règlement immédiat du dépôt de garantie, par virement bancaire instantané</li>
                            </ol>
                            <p>
                                La prise d'effet du bail ainsi que la remise des clés interviendront uniquement après réception complète de l'ensemble des éléments ci-dessus.
                            </p>
                            <p>
                                À défaut de réception complète du dossier dans le délai indiqué, la réservation du logement pourra être annulée et remise à la disponibilité d'autres clients.
                            </p>
                            <p class="text-danger">
                                <strong>⚠️ Important :</strong> Ce lien expire le 
                                <?= formatDateFr($contrat['date_expiration'], 'd/m/Y à H:i') ?>
                            </p>
                        </div>

                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            
                            <div class="mb-4">
                                <p><strong>Validation de la procédure</strong></p>
                                
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="radio" name="choix" id="accepte" value="accepte" required>
                                    <label class="form-check-label" for="accepte">
                                        J'accepte la procédure ci-dessus et m'engage à la compléter dans le délai de 24 heures.
                                    </label>
                                </div>
                                
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="radio" name="choix" id="refuse" value="refuse">
                                    <label class="form-check-label" for="refuse">
                                        Je refuse la procédure et renonce à la poursuite de la location du logement.
                                    </label>
                                </div>
                                
                                <div class="d-grid gap-2 mt-4">
                                    <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                        Valider
                                    </button>
                                </div>
                            </div>
                        </form>
                        
                        <div class="mt-3 text-center">
                            <p class="text-muted">Nous restons à votre disposition en cas de question.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Changer la couleur du bouton selon le choix
        document.querySelectorAll('input[name="choix"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const btn = document.getElementById('submitBtn');
                if (this.value === 'accepte') {
                    btn.className = 'btn btn-success btn-lg';
                    btn.textContent = 'J\'accepte et je continue';
                } else {
                    btn.className = 'btn btn-danger btn-lg';
                    btn.textContent = 'Je refuse';
                }
            });
        });
    </script>
</body>
</html>
