<?php
/**
 * Garant – Étape 2 : Informations personnelles
 *
 * Le garant vérifie et peut corriger les informations saisies par le locataire.
 *
 * My Invest Immobilier
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// ------------------------------------------------------------------
// Vérification de session
// ------------------------------------------------------------------
if (empty($_SESSION['garant_token']) || empty($_SESSION['garant_id'])) {
    header('Location: index.php');
    exit;
}

$garantId = (int)$_SESSION['garant_id'];
$garant   = fetchOne("
    SELECT g.*,
           c.reference_unique AS reference_contrat,
           l.adresse          AS adresse_logement
    FROM garants g
    INNER JOIN contrats c ON g.contrat_id = c.id
    INNER JOIN logements l ON c.logement_id = l.id
    WHERE g.id = ?
", [$garantId]);

if (!$garant) {
    die('Session invalide. Veuillez utiliser le lien fourni dans votre email.');
}

if (!in_array($garant['statut'], ['engage', 'signe', 'documents_recus'], true)) {
    header('Location: index.php?token=' . urlencode($_SESSION['garant_token']));
    exit;
}

$error = '';

// ------------------------------------------------------------------
// Traitement POST
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Token CSRF invalide.';
    } else {
        $nom           = trim($_POST['nom']            ?? '');
        $prenom        = trim($_POST['prenom']         ?? '');
        $dateNaissance = trim($_POST['date_naissance'] ?? '');
        $email         = trim($_POST['email']          ?? '');
        $telephone     = trim($_POST['telephone']      ?? '');
        $adresse       = trim($_POST['adresse']        ?? '');
        $ville         = trim($_POST['ville']          ?? '');
        $codePostal    = trim($_POST['code_postal']    ?? '');

        if (empty($nom) || empty($prenom) || empty($dateNaissance) || empty($email) || empty($adresse) || empty($ville)) {
            $error = 'Veuillez remplir tous les champs obligatoires.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Adresse email invalide.';
        } else {
            $stmt = executeQuery("
                UPDATE garants
                SET nom = ?, prenom = ?, date_naissance = ?, email = ?,
                    telephone = ?, adresse = ?, ville = ?, code_postal = ?
                WHERE id = ?
            ", [$nom, $prenom, $dateNaissance, $email, $telephone, $adresse, $ville, $codePostal, $garantId]);

            if ($stmt) {
                logAction($garant['contrat_id'], 'garant_info_mise_a_jour', "Garant ID $garantId");
                header('Location: step3-signature.php');
                exit;
            } else {
                $error = 'Erreur lors de la mise à jour. Veuillez réessayer.';
            }
        }
    }
}

// Pré-remplissage
$valNom           = htmlspecialchars($_POST['nom']            ?? $garant['nom']            ?? '');
$valPrenom        = htmlspecialchars($_POST['prenom']         ?? $garant['prenom']         ?? '');
$valDateNaissance = htmlspecialchars($_POST['date_naissance'] ?? $garant['date_naissance'] ?? '');
$valEmail         = htmlspecialchars($_POST['email']          ?? $garant['email']          ?? '');
$valTelephone     = htmlspecialchars($_POST['telephone']      ?? $garant['telephone']      ?? '');
$valAdresse       = htmlspecialchars($_POST['adresse']        ?? $garant['adresse']        ?? '');
$valVille         = htmlspecialchars($_POST['ville']          ?? $garant['ville']          ?? '');
$valCodePostal    = htmlspecialchars($_POST['code_postal']    ?? $garant['code_postal']    ?? '');

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informations garant – My Invest Immobilier</title>
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

    <!-- Barre de progression -->
    <div class="mb-4">
        <div class="progress" style="height: 28px;">
            <div class="progress-bar bg-primary" role="progressbar" style="width:50%">
                Étape 2/4 – Informations personnelles
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-body">
                    <h4 class="card-title mb-4">Vos informations personnelles</h4>

                    <div class="alert alert-secondary mb-4">
                        <strong>Logement :</strong> <?= htmlspecialchars($garant['adresse_logement']) ?>
                    </div>

                    <p class="text-muted">Ces informations ont été saisies par le locataire. Vérifiez-les et corrigez si nécessaire.</p>

                    <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="nom" class="form-label">Nom <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="nom" name="nom"
                                       value="<?= $valNom ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="prenom" class="form-label">Prénom <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="prenom" name="prenom"
                                       value="<?= $valPrenom ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="date_naissance" class="form-label">
                                Date de naissance <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control" id="date_naissance" name="date_naissance"
                                   value="<?= $valDateNaissance ?>" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email"
                                       value="<?= $valEmail ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="telephone" class="form-label">Téléphone</label>
                                <input type="tel" class="form-control" id="telephone" name="telephone"
                                       value="<?= $valTelephone ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="adresse" class="form-label">Adresse postale <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="adresse" name="adresse"
                                   value="<?= $valAdresse ?>" required>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="code_postal" class="form-label">Code postal</label>
                                <input type="text" class="form-control" id="code_postal" name="code_postal"
                                       value="<?= $valCodePostal ?>">
                            </div>
                            <div class="col-md-8 mb-3">
                                <label for="ville" class="form-label">Ville <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="ville" name="ville"
                                       value="<?= $valVille ?>" required>
                            </div>
                        </div>

                        <div class="d-grid mt-3">
                            <button type="submit" class="btn btn-primary btn-lg">
                                Continuer → Signature
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
