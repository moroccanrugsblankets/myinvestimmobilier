<?php
/**
 * Signature - Étape 3 : Upload des documents d'identité
 * My Invest Immobilier
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail-templates.php';

// Vérifier la session
if (!isset($_SESSION['signature_token']) || !isset($_SESSION['contrat_id'])) {
    die('Session invalide. Veuillez utiliser le lien fourni dans votre email.');
}

$contratId = $_SESSION['contrat_id'];

// Si current_locataire_id n'est pas défini, déterminer automatiquement le premier locataire qui a signé mais pas uploadé de documents
// FIX #212: Defensive fallback to ensure tenant 1 uploads documents before tenant 2
// getTenantsByContract() orders by 'ordre ASC', so iteration will find tenant 1 first
if (!isset($_SESSION['current_locataire_id'])) {
    $locatairesExistants = getTenantsByContract($contratId);
    $locataireSansDocuments = null;
    
    foreach ($locatairesExistants as $locataire) {
        // Le locataire doit avoir signé mais pas encore uploadé de documents
        if (!empty($locataire['signature_timestamp']) && empty($locataire['piece_identite_recto'])) {
            $locataireSansDocuments = $locataire;
            break;  // Stop at first tenant without documents (will be tenant with lowest 'ordre')
        }
    }
    
    if (!$locataireSansDocuments) {
        die('Tous les locataires ont déjà uploadé leurs documents.');
    }
    
    $_SESSION['current_locataire_id'] = $locataireSansDocuments['id'];
    $_SESSION['current_locataire_numero'] = $locataireSansDocuments['ordre'];
}

$locataireId = $_SESSION['current_locataire_id'];
$numeroLocataire = $_SESSION['current_locataire_numero'];

// Important: Select c.* first, then explicitly name columns to avoid collision
// Both tables have 'statut' column, and we need contrats.statut, not logements.statut
// Using contrat_logement for frozen data with fallback to logements
$contrat = fetchOne("
    SELECT c.*, 
           COALESCE(cl.reference, l.reference) as reference,
           COALESCE(cl.adresse, l.adresse) as adresse,
           
           COALESCE(cl.type, l.type) as type,
           COALESCE(cl.surface, l.surface) as surface,
           COALESCE(cl.loyer, l.loyer) as loyer,
           COALESCE(cl.charges, l.charges) as charges,
           COALESCE(cl.depot_garantie, l.depot_garantie) as depot_garantie,
           COALESCE(cl.parking, l.parking) as parking,
           COALESCE(cl.dpe_file, l.dpe_file, '') as dpe_file
    FROM contrats c 
    LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
    LEFT JOIN logements l ON c.logement_id = l.id 
    WHERE c.id = ?
", [$contratId]);

if (!$contrat || !isContractValid($contrat)) {
    die('Contrat invalide ou expiré.');
}

$error = '';
$success = '';

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Token CSRF invalide.';
    } else {
        // Vérifier les fichiers uploadés
        $rectoFile = $_FILES['piece_recto'] ?? null;
        $versoFile = $_FILES['piece_verso'] ?? null;
        
        if (!$rectoFile || $rectoFile['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Veuillez télécharger la pièce d\'identité / Passport recto.';
        } else {
            // Valider le fichier recto
            $rectoValidation = validateUploadedFile($rectoFile);
            if (!$rectoValidation['success']) {
                $error = 'Recto : ' . $rectoValidation['error'];
            } else {
                // Valider le fichier verso (optionnel)
                $versoValidation = null;
                if ($versoFile && $versoFile['error'] !== UPLOAD_ERR_NO_FILE) {
                    $versoValidation = validateUploadedFile($versoFile);
                    if (!$versoValidation['success']) {
                        $error = 'Verso : ' . $versoValidation['error'];
                    }
                }
                
                if (empty($error)) {
                    // Sauvegarder le fichier recto (obligatoire)
                    $rectoSaved = saveUploadedFile($rectoFile, $rectoValidation['filename']);
                    
                    // Sauvegarder le fichier verso (optionnel)
                    $versoSaved = true;
                    $versoFilename = null;
                    if ($versoValidation) {
                        $versoSaved = saveUploadedFile($versoFile, $versoValidation['filename']);
                        $versoFilename = $versoValidation['filename'];
                    }
                    
                    if ($rectoSaved && $versoSaved) {
                        
                        // Mettre à jour le locataire
                        if (updateTenantDocuments($locataireId, $rectoValidation['filename'], $versoFilename)) {
                            logAction($contratId, 'upload_documents', "Locataire $numeroLocataire a uploadé ses documents");
                            
                            // Check if there are more tenants who need to upload documents
                            $locatairesExistants = getTenantsByContract($contratId);
                            $hasMoreTenants = false;
                            
                            foreach ($locatairesExistants as $locataire) {
                                // Check if there's a tenant who has signed but hasn't uploaded documents yet
                                if (!empty($locataire['signature_timestamp']) && empty($locataire['piece_identite_recto']) && $locataire['id'] != $locataireId) {
                                    $hasMoreTenants = true;
                                    // Set session for next tenant
                                    $_SESSION['current_locataire_id'] = $locataire['id'];
                                    $_SESSION['current_locataire_numero'] = $locataire['ordre'];
                                    break;
                                }
                            }
                            
                            if ($hasMoreTenants) {
                                // Reload this page for the next tenant
                                header('Location: step3-documents.php');
                                exit;
                            } else {
                                // Finaliser le contrat
                                finalizeContract($contratId);
                                
                                // Générer le PDF
                                require_once __DIR__ . '/../pdf/generate-bail.php';
                                $pdfPath = generateBailPDF($contratId);
                                
                                // Envoyer l'email de finalisation aux locataires
                                $locataires = getTenantsByContract($contratId);
                                foreach ($locataires as $locataire) {
                                    // Préparer le lien pour l'upload du justificatif
                                    $lienUpload = $config['SITE_URL'] . '/envoyer-justificatif.php?token=' . $contrat['token_signature'];
                                    
                                    // Préparer le lien vers le contrat signé (visualisation en ligne)
                                    $lienContratSigne = $config['SITE_URL'] . '/pdf/contrats/bail-' . basename($contrat['reference_unique']) . '.pdf';

                                    // Préparer les variables pour le template
                                    $variables = [
                                        'nom' => $locataire['nom'],
                                        'prenom' => $locataire['prenom'],
                                        'reference' => $contrat['reference_unique'],
                                        'depot_garantie' => formatMontant($contrat['depot_garantie']),
                                        'lien_upload' => $lienUpload,
                                        'lien_contrat_signe' => $lienContratSigne,
                                        'lien_telechargement_dpe' => (!empty($contrat['dpe_file']) && strpos($contrat['dpe_file'], '..') === false && strpos($contrat['dpe_file'], '/') !== 0) ? rtrim($config['SITE_URL'], '/') . '/' . $contrat['dpe_file'] : '',
                                    ];
                                    
                                    // Envoyer l'email de confirmation sans PJ (lien dans le corps)
                                    sendTemplatedEmail('contrat_finalisation_client', $locataire['email'], $variables, null, false, false);
                                    
                                    // Envoyer l'email de demande de justificatif de paiement (en parallèle) avec admin en BCC
                                    sendTemplatedEmail('demande_justificatif_paiement', $locataire['email'], $variables, null, false, true);
                                }
                                
                                // Envoyer une notification aux administrateurs avec le PDF
                                if ($pdfPath && file_exists($pdfPath)) {
                                    // Préparer la liste des locataires
                                    $locatairesNoms = array_map(function($loc) {
                                        return $loc['prenom'] . ' ' . $loc['nom'];
                                    }, $locataires);
                                    $locatairesStr = implode(', ', $locatairesNoms);
                                    
                                    // Construire le lien admin
                                    global $config;
                                    $lienAdmin = $config['SITE_URL'] . '/admin-v2/contrat-detail.php?id=' . $contratId;
                                    
                                    // Préparer les variables pour le template admin
                                    $adminVariables = [
                                        'reference' => $contrat['reference_unique'],
                                        'logement' => $contrat['adresse'],
                                        'locataires' => $locatairesStr,
                                        'date_signature' => date('d/m/Y à H:i'),
                                        'date_finalisation' => date('d/m/Y à H:i'),
                                        'depot_garantie' => formatMontant($contrat['depot_garantie']),
                                        'lien_admin' => $lienAdmin
                                    ];
                                    
                                    // Envoyer l'email admin avec le template HTML - TO admin email, not client
                                    sendTemplatedEmail('contrat_finalisation_admin', getAdminEmail(), $adminVariables, $pdfPath, true);
                                }
                                
                                logAction($contratId, 'finalisation_contrat', 'Contrat finalisé et emails envoyés (confirmation + demande justificatif)');
                                
                                // Rediriger vers la confirmation
                                header('Location: confirmation.php');
                                exit;
                            }
                        } else {
                            $error = 'Erreur lors de l\'enregistrement des documents.';
                        }
                    } else {
                        $error = 'Erreur lors de la sauvegarde des fichiers.';
                    }
                }
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
    <title>Vérification d'identité - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .upload-zone {
            border: 2px dashed #3498db;
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background-color 0.2s;
            background: #f8fbff;
        }
        .upload-zone:hover, .upload-zone.drag-over {
            border-color: #1a6fb0;
            background-color: #e8f4fd;
        }
        .upload-zone input[type="file"] { display: none; }
        .file-preview {
            display: flex;
            align-items: center;
            background: #f1f3f5;
            border-radius: 6px;
            padding: 8px 12px;
            margin-top: 8px;
        }
        .file-preview .file-name { flex: 1; font-size: 0.9rem; word-break: break-all; }
        .file-preview .btn-remove { flex-shrink: 0; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="text-center mb-4">
            <img src="../assets/images/logo.png" alt="My Invest Immobilier" class="logo mb-3" 
                 onerror="this.style.display='none'">
            <h1 class="h2">Vérification d'identité du ou des locataires</h1>
        </div>

        <!-- Barre de progression -->
        <div class="mb-4">
            <div class="progress" style="height: 30px;">
                <div class="progress-bar bg-success" role="progressbar" style="width: 100%;">
                    Étape 3/3 - Documents d'identité
                </div>
            </div>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-body">
                        <h4 class="card-title mb-3">
                            Justificatif(s) d'identité
                        </h4>

                        <div class="alert alert-info mb-4">
                            <p class="mb-2">
                                Conformément à la réglementation en vigueur et afin de finaliser le dossier de location, nous vous remercions de nous transmettre une copie de la pièce d'identité de chaque titulaire du bail (carte nationale d'identité ou passeport).
                            </p>
                            <p class="mb-0">
                                Ces documents sont nécessaires afin de vérifier que les signataires du bail sont bien les personnes qui louent le logement. Les données transmises sont traitées de manière strictement confidentielle.
                            </p>
                        </div>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                        <?php endif; ?>

                        <form method="POST" action="" enctype="multipart/form-data" id="docForm">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            
                            <h5 class="mb-3">Locataire <?= $numeroLocataire ?></h5>
                            
                            <div class="mb-4">
                                <label class="form-label fw-semibold">
                                    Pièce d'identité / Passport — Recto <span class="text-danger">*</span>
                                </label>
                                <div class="upload-zone" id="zone-recto" onclick="document.getElementById('piece_recto').click()">
                                    <i class="bi bi-cloud-arrow-up fs-2 text-primary"></i>
                                    <p class="mb-1 mt-2">Cliquez ou glissez-déposez votre fichier ici</p>
                                    <small class="text-muted">JPG, PNG, PDF — max 5 Mo</small>
                                    <input type="file" id="piece_recto" name="piece_recto" accept=".jpg,.jpeg,.png,.pdf" required>
                                </div>
                                <div id="preview-recto"></div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold">
                                    Pièce d'identité / Passport — Verso <span class="text-muted">(optionnel)</span>
                                </label>
                                <div class="upload-zone" id="zone-verso" onclick="document.getElementById('piece_verso').click()">
                                    <i class="bi bi-cloud-arrow-up fs-2 text-secondary"></i>
                                    <p class="mb-1 mt-2">Cliquez ou glissez-déposez votre fichier ici</p>
                                    <small class="text-muted">JPG, PNG, PDF — max 5 Mo (optionnel pour les passeports)</small>
                                    <input type="file" id="piece_verso" name="piece_verso" accept=".jpg,.jpeg,.png,.pdf">
                                </div>
                                <div id="preview-verso"></div>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    Finaliser →
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    function initUploadZone(zoneId, inputId, previewId) {
        var zone = document.getElementById(zoneId);
        var input = document.getElementById(inputId);
        var preview = document.getElementById(previewId);
        if (!zone || !input || !preview) return;

        function showPreview(file) {
            preview.innerHTML = '';
            if (!file) return;
            var div = document.createElement('div');
            div.className = 'file-preview';
            div.innerHTML = '<span class="file-name">📄 ' + file.name + ' (' + (file.size/1024).toFixed(1) + ' Ko)</span>'
                + '<button type="button" class="btn btn-sm btn-outline-danger btn-remove ms-2">✕</button>';
            div.querySelector('.btn-remove').addEventListener('click', function() {
                input.value = '';
                preview.innerHTML = '';
                zone.style.borderColor = '';
                zone.style.backgroundColor = '';
            });
            preview.appendChild(div);
            zone.style.borderColor = '#198754';
            zone.style.backgroundColor = '#d1e7dd';
        }

        input.addEventListener('change', function() {
            if (this.files.length) showPreview(this.files[0]);
        });

        var counter = 0;
        zone.addEventListener('dragenter', function(e) { e.preventDefault(); counter++; zone.classList.add('drag-over'); });
        zone.addEventListener('dragleave', function(e) { e.preventDefault(); if (--counter <= 0) { counter = 0; zone.classList.remove('drag-over'); } });
        zone.addEventListener('dragover', function(e) { e.preventDefault(); });
        zone.addEventListener('drop', function(e) {
            e.preventDefault();
            counter = 0;
            zone.classList.remove('drag-over');
            var files = e.dataTransfer.files;
            if (files.length) {
                // Assign dropped file to the hidden input via DataTransfer
                try {
                    var dt = new DataTransfer();
                    dt.items.add(files[0]);
                    input.files = dt.files;
                } catch(ex) { /* fallback: no-op */ }
                showPreview(files[0]);
            }
        });
    }

    initUploadZone('zone-recto', 'piece_recto', 'preview-recto');
    initUploadZone('zone-verso', 'piece_verso', 'preview-verso');
})();
</script>
</body>
</html>
