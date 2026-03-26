<?php
/**
 * Interest Confirmation Page
 * 
 * Allows accepted candidates to confirm their interest after receiving acceptance email
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$error = '';
$success = false;
$candidature = null;

if (isset($_GET['ref'])) {
    $reference = $_GET['ref'];
    
    try {
        // Get candidature by reference
        $stmt = $pdo->prepare("SELECT * FROM candidatures WHERE reference_candidature = ? AND statut = 'Accepté'");
        $stmt->execute([$reference]);
        $candidature = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$candidature) {
            $error = "Candidature non trouvée ou déjà traitée.";
        } elseif (isset($_POST['confirmer'])) {
            // Update status to "Refus après visite"
            $updateStmt = $pdo->prepare("UPDATE candidatures SET statut = 'refus_apres_visite', date_confirmation_interet = NOW() WHERE id = ?");
            $updateStmt->execute([$candidature['id']]);
            
            // Log the action
            $logStmt = $pdo->prepare("INSERT INTO logs (candidature_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $logStmt->execute([
                $candidature['id'],
                'confirmation_interet',
                "Le candidat a confirmé son intérêt",
                $_SERVER['REMOTE_ADDR']
            ]);
            
            $success = true;
        }
        
    } catch (Exception $e) {
        $error = "Une erreur est survenue. Veuillez réessayer.";
        error_log("Error in confirmer-interet.php: " . $e->getMessage());
    }
} else {
    $error = "Référence manquante.";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmation d'intérêt - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .card { box-shadow: 0 10px 30px rgba(0,0,0,0.2); border-radius: 15px; }
        .success-icon { font-size: 64px; color: #28a745; }
        .logo-section { text-align: center; margin-bottom: 30px; }
        .logo-section h1 { color: white; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="logo-section">
            <h1>My Invest Immobilier</h1>
        </div>
        
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body p-5">
                        <?php if ($error): ?>
                            <div class="text-center">
                                <div class="mb-4">
                                    <i class="bi bi-x-circle" style="font-size: 64px; color: #dc3545;"></i>
                                </div>
                                <h3 class="mb-3">Erreur</h3>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                                <a href="<?php echo $config['SITE_URL']; ?>" class="btn btn-primary">Retour à l'accueil</a>
                            </div>
                        <?php elseif ($success): ?>
                            <div class="text-center">
                                <div class="success-icon mb-4">✓</div>
                                <h3 class="mb-3">Intérêt confirmé !</h3>
                                <p class="lead">Nous vous remercions pour votre confirmation.</p>
                                <div class="alert alert-success">
                                    <strong>Prochaine étape :</strong><br>
                                    Nous allons vous contacter très prochainement via WhatsApp pour organiser une visite du logement.
                                </div>
                                <p class="text-muted mt-4">
                                    <small>Veuillez garder votre téléphone à portée de main. Nous vous contacterons dans les 24-48 heures.</small>
                                </p>
                            </div>
                        <?php elseif ($candidature): ?>
                            <h3 class="mb-4">Confirmation d'intérêt</h3>
                            
                            <div class="alert alert-info">
                                <strong>Bonjour <?php echo htmlspecialchars($candidature['prenom'] . ' ' . $candidature['nom']); ?>,</strong><br>
                                Votre candidature a été acceptée !
                            </div>
                            
                            <p>Pour finaliser le processus, merci de confirmer votre intérêt ci-dessous.</p>
                            
                            <div class="card bg-light mb-4">
                                <div class="card-body">
                                    <h5>Prochaines étapes après confirmation :</h5>
                                    <ol class="mb-0">
                                        <li>Vous recevrez un contact via WhatsApp sous 24-48h</li>
                                        <li>Nous organiserons ensemble une visite du logement</li>
                                        <li>Si la visite est concluante, le contrat vous sera envoyé</li>
                                    </ol>
                                </div>
                            </div>
                            
                            <form method="POST">
                                <div class="d-grid">
                                    <button type="submit" name="confirmer" class="btn btn-success btn-lg">
                                        Oui, je confirme mon intérêt
                                    </button>
                                </div>
                            </form>
                            
                            <p class="text-center text-muted mt-3">
                                <small>Référence : <?php echo htmlspecialchars($candidature['reference_candidature']); ?></small>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
