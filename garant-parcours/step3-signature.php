<?php
/**
 * Garant – Étape 3 : Signature électronique
 *
 * Le garant signe électroniquement le document de caution solidaire.
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
           l.adresse          AS adresse_logement,
           l.loyer,
           l.charges
    FROM garants g
    INNER JOIN contrats c ON g.contrat_id = c.id
    INNER JOIN logements l ON c.logement_id = l.id
    WHERE g.id = ?
", [$garantId]);

if (!$garant) {
    die('Session invalide. Veuillez utiliser le lien fourni dans votre email.');
}

if (!in_array($garant['statut'], ['engage', 'signe'], true)) {
    header('Location: index.php?token=' . urlencode($_SESSION['garant_token']));
    exit;
}

// Récupérer le locataire principal
$locataire = fetchOne("
    SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 1
", [$garant['contrat_id']]);

$error = '';

// ------------------------------------------------------------------
// Traitement POST
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Token CSRF invalide.';
    } else {
        $signatureData = $_POST['signature_data'] ?? '';
        $certifieExact = isset($_POST['certifie_exact']) ? 1 : 0;

        if (empty($signatureData)) {
            $error = 'Veuillez apposer votre signature.';
        } elseif (!$certifieExact) {
            $error = 'Veuillez cocher la case "Certifié exact" pour continuer.';
        } else {
            if (saveGarantSignature($garantId, $signatureData, $certifieExact)) {
                // Générer le document de caution solidaire PDF
                require_once __DIR__ . '/../pdf/generate-caution-solidaire.php';
                $pdfPath = generateCautionSolidairePDF($garantId);
                if ($pdfPath) {
                    executeQuery("UPDATE garants SET document_caution = ? WHERE id = ?", [$pdfPath, $garantId]);
                }

                logAction($garant['contrat_id'], 'garant_signature', "Garant ID $garantId a signé");
                header('Location: step4-documents.php');
                exit;
            } else {
                $error = 'Erreur lors de l\'enregistrement de la signature. Veuillez réessayer.';
            }
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
    <title>Signature garant – My Invest Immobilier</title>
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
            <div class="progress-bar bg-primary" role="progressbar" style="width:75%">
                Étape 3/4 – Signature électronique
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-body">
                    <h4 class="card-title mb-4">Signature électronique</h4>

                    <div class="alert alert-secondary mb-4">
                        <p class="mb-1"><strong>Logement :</strong> <?= htmlspecialchars($garant['adresse_logement']) ?></p>
                        <p class="mb-0">
                            <strong>Garant :</strong> <?= htmlspecialchars($garant['prenom'] . ' ' . $garant['nom']) ?><br>
                            <?php if ($locataire): ?>
                            <strong>Locataire :</strong> <?= htmlspecialchars($locataire['prenom'] . ' ' . $locataire['nom']) ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <p>En signant, vous confirmez votre engagement en tant que caution solidaire pour ce contrat de location.</p>

                    <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="" id="signatureForm">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="signature_data" id="signature_data">

                        <div class="mb-4">
                            <label class="form-label">Signez dans le cadre ci-dessous :</label>
                            <div class="signature-container" style="max-width:300px;">
                                <canvas id="signatureCanvas" width="300" height="150"
                                        style="background:transparent;border:none;outline:none;padding:0;"></canvas>
                            </div>
                            <div class="mt-2">
                                <button type="button" class="btn btn-warning btn-sm" onclick="clearSignature()">
                                    Effacer
                                </button>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="certifie_exact"
                                       name="certifie_exact" value="1" required>
                                <label class="form-check-label" for="certifie_exact">
                                    <strong>Certifié exact</strong> – Je confirme mon engagement en tant que caution solidaire. *
                                </label>
                            </div>
                        </div>

                        <div class="alert alert-info">
                            <small>
                                <strong>Information :</strong> Votre signature sera horodatée et votre adresse IP
                                enregistrée pour des raisons de sécurité et de conformité légale.
                            </small>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">
                                Valider la signature →
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/signature.js"></script>
<script>
window.addEventListener('DOMContentLoaded', function () {
    initSignature();
});

document.getElementById('signatureForm').addEventListener('submit', function (e) {
    var sigData = getSignatureData();
    if (!sigData || sigData === getEmptyCanvasData()) {
        e.preventDefault();
        alert('Veuillez apposer votre signature avant de continuer.');
        return false;
    }
    document.getElementById('signature_data').value = sigData;
});
</script>
</body>
</html>
