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
    <style>
        /* Aperçu modal – responsive */
        @media (max-width: 767.98px) {
            #modalApercuDocument .modal-dialog { margin: 0; max-width: 100%; height: 100%; }
            #modalApercuDocument .modal-content { height: 100%; border-radius: 0; }
            #modalApercuDocument .modal-body { height: calc(100vh - 120px) !important; }
        }
    </style>
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

                    <!-- Bouton aperçu -->
                    <div class="text-center mb-4">
                        <button type="button" class="btn btn-warning btn-lg fw-semibold shadow-sm px-4"
                                data-bs-toggle="modal" data-bs-target="#modalApercuDocument"
                                style="font-size:1.05rem;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-eye me-2" viewBox="0 0 16 16">
                                <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
                                <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                            </svg>
                            Voir l'aperçu du contrat avant signature
                        </button>
                        <p class="text-muted small mt-2 mb-0">Consultez le document complet avant de signer.</p>
                    </div>

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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Modal aperçu du document de caution solidaire -->
<div class="modal fade" id="modalApercuDocument" tabindex="-1" aria-labelledby="modalApercuDocumentLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalApercuDocumentLabel">Aperçu du document de caution solidaire</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body p-0" style="height:75vh;">
                <iframe id="iframeApercuDocument"
                        src="about:blank"
                        style="width:100%;height:100%;border:none;"
                        title="Aperçu du document de caution solidaire"></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<script>
window.addEventListener('DOMContentLoaded', function () {
    initSignature();

    // Charger l'aperçu dans l'iframe au premier affichage du modal
    const apercuModal = document.getElementById('modalApercuDocument');
    if (apercuModal) {
        let iframeLoaded = false;
        apercuModal.addEventListener('show.bs.modal', function () {
            if (!iframeLoaded) {
                document.getElementById('iframeApercuDocument').src =
                    '../apercu-caution.php?token=' + <?= json_encode(urlencode($_SESSION['garant_token'])) ?>;
                iframeLoaded = true;
            }
        });
    }
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
