<?php
/**
 * Signature - Étape 2 : Signature électronique
 * My Invest Immobilier
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérifier la session
if (!isset($_SESSION['signature_token']) || !isset($_SESSION['contrat_id'])) {
    die('Session invalide. Veuillez utiliser le lien fourni dans votre email.');
}

$contratId = $_SESSION['contrat_id'];

// Si current_locataire_id n'est pas défini, déterminer automatiquement le premier locataire non signé
// FIX #212: Defensive fallback to ensure tenant 1 is always shown before tenant 2
// getTenantsByContract() orders by 'ordre ASC', so iteration will find tenant 1 first
if (!isset($_SESSION['current_locataire_id'])) {
    $locatairesExistants = getTenantsByContract($contratId);
    $locataireNonSigne = null;
    
    foreach ($locatairesExistants as $locataire) {
        if (empty($locataire['signature_timestamp'])) {
            $locataireNonSigne = $locataire;
            break;  // Stop at first unsigned tenant (will be tenant with lowest 'ordre')
        }
    }
    
    if (!$locataireNonSigne) {
        die('Tous les locataires ont déjà signé.');
    }
    
    $_SESSION['current_locataire_id'] = $locataireNonSigne['id'];
    $_SESSION['current_locataire_numero'] = $locataireNonSigne['ordre'];
}

$locataireId = $_SESSION['current_locataire_id'];
$numeroLocataire = $_SESSION['current_locataire_numero'];

// Defensive logging to track tenant identity
error_log("=== STEP2 PAGE LOAD ===");
error_log("Session current_locataire_id: $locataireId");
error_log("Session current_locataire_numero: $numeroLocataire");
error_log("Contrat ID: $contratId");

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
           COALESCE(cl.parking, l.parking) as parking
    FROM contrats c 
    LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
    LEFT JOIN logements l ON c.logement_id = l.id 
    WHERE c.id = ?
", [$contratId]);

if (!$contrat || !isContractValid($contrat)) {
    die('Contrat invalide ou expiré.');
}

$error = '';
$signatureSaved = false;

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Token CSRF invalide.';
    } else {
        // Check if this is the second tenant question submission
        if (isset($_POST['second_locataire'])) {
            $secondLocataire = $_POST['second_locataire'];
            
            if ($secondLocataire === 'oui') {
                // Redirect to step1 for second tenant
                unset($_SESSION['current_locataire_id']);
                unset($_SESSION['current_locataire_numero']);
                header('Location: step1-info.php');
                exit;
            } else {
                // No second tenant, go to documents
                header('Location: step3-documents.php');
                exit;
            }
        }
        
        // Otherwise, process the signature
        $signatureData = $_POST['signature_data'] ?? '';
        $certifieExact = isset($_POST['certifie_exact']) ? 1 : 0;
        
        // Log: Signature client reçue
        error_log("Step2-Signature: === RÉCEPTION SIGNATURE CLIENT ===");
        error_log("Step2-Signature: Locataire ID: $locataireId, Numéro: $numeroLocataire");
        error_log("Step2-Signature: Signature data length: " . strlen($signatureData) . " octets");
        error_log("Step2-Signature: Certifié exact: " . ($certifieExact ? 'OUI' : 'NON'));
        if (!empty($signatureData)) {
            error_log("Step2-Signature: Début data URI: " . substr($signatureData, 0, 60) . "...");
        }
        
        // Validation
        if (empty($signatureData)) {
            error_log("Step2-Signature: ERREUR - Signature vide");
            $error = 'Veuillez apposer votre signature.';
        } elseif (!$certifieExact) {
            error_log("Step2-Signature: ERREUR - Case 'Certifié exact' non cochée");
            $error = 'Veuillez cocher la case "Certifié exact" pour continuer.';
        } else {
            // Log: Validation de la signature
            if (preg_match('/^data:image\/(png|jpeg|jpg);base64,/', $signatureData, $matches)) {
                $imageFormat = $matches[1];
                error_log("Step2-Signature: Format image validé: $imageFormat");
            } else {
                error_log("Step2-Signature: AVERTISSEMENT - Format data URI non reconnu");
            }
            
            // Enregistrer la signature
            error_log("Step2-Signature: Enregistrement de la signature en base de données...");
            error_log("Step2-Signature: Saving signature for Locataire ID=$locataireId, Numero=$numeroLocataire");
            
            // Double-check that we're saving to the correct tenant
            $tenantCheck = fetchOne("SELECT id, ordre, nom, prenom FROM locataires WHERE id = ?", [$locataireId]);
            if (!$tenantCheck) {
                error_log("Step2-Signature: ✗ ERREUR CRITIQUE - Locataire ID=$locataireId n'existe pas!");
                $error = 'Erreur: locataire introuvable.';
            } elseif ($tenantCheck['ordre'] != $numeroLocataire) {
                error_log("Step2-Signature: ✗ ERREUR CRITIQUE - Mismatch! Session numero=$numeroLocataire but DB ordre=" . $tenantCheck['ordre']);
                $error = 'Erreur: incohérence de session.';
            } else {
                error_log("Step2-Signature: ✓ Verification OK - Tenant: {$tenantCheck['prenom']} {$tenantCheck['nom']}, Ordre: {$tenantCheck['ordre']}");
                
                // Additional check: If this is tenant 2, ensure tenant 1 has already signed
                if ($numeroLocataire == 2) {
                    $tenant1 = fetchOne("SELECT id, signature_timestamp FROM locataires WHERE contrat_id = ? AND ordre = 1", [$contratId]);
                    if (!$tenant1 || empty($tenant1['signature_timestamp'])) {
                        error_log("Step2-Signature: ✗ ERREUR - Tenant 2 trying to sign but tenant 1 hasn't signed yet!");
                        $error = 'Erreur: Le locataire 1 doit signer en premier.';
                    } else {
                        error_log("Step2-Signature: ✓ Tenant 1 already signed, proceeding with tenant 2 signature");
                    }
                }
                
                if (empty($error) && updateTenantSignature($locataireId, $signatureData, null, $certifieExact)) {
                    error_log("Step2-Signature: ✓ Signature enregistrée avec succès");
                    logAction($contratId, 'signature_locataire', "Locataire $numeroLocataire a signé");
                    
                    // Check if we need to ask about second tenant
                    // Only ask if this is locataire 1 and contract allows multiple tenants
                    if ($numeroLocataire === 1 && $contrat['nb_locataires'] > 1) {
                        // Show the second tenant question
                        $signatureSaved = true;
                    } else {
                        // Locataire 2 or single tenant contract - go directly to documents
                        // Clear session to let step3 defensive fallback find the first tenant without documents
                        unset($_SESSION['current_locataire_id']);
                        unset($_SESSION['current_locataire_numero']);
                        header('Location: step3-documents.php');
                        exit;
                    }
                } else {
                    error_log("Step2-Signature: ✗ ERREUR lors de l'enregistrement de la signature");
                    $error = 'Erreur lors de l\'enregistrement de la signature.';
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
    <title>Signature électronique - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container mt-5">
        <div class="text-center mb-4">
            <img src="../assets/images/logo.png" alt="My Invest Immobilier" class="logo mb-3" 
                 onerror="this.style.display='none'">
            <h1 class="h2">Signature électronique</h1>
        </div>

        <!-- Barre de progression -->
        <div class="mb-4">
            <div class="progress" style="height: 30px;">
                <div class="progress-bar bg-success" role="progressbar" style="width: 66%;">
                    Étape 2/3 - Signature
                </div>
            </div>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            Signature du locataire <?= $numeroLocataire ?>
                        </h4>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <?php if ($signatureSaved): ?>
                            <!-- Second tenant question after signature is saved -->
                            <div class="alert alert-success mb-4">
                                <i class="bi bi-check-circle"></i> Votre signature a été enregistrée avec succès !
                            </div>

                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                
                                <div class="mb-4">
                                    <label class="form-label"><strong>Y a-t-il un second locataire ?</strong> *</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="second_locataire" 
                                               id="second_oui" value="oui" required>
                                        <label class="form-check-label" for="second_oui">
                                            Oui, il y a un second locataire
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="second_locataire" 
                                               id="second_non" value="non">
                                        <label class="form-check-label" for="second_non">
                                            Non, je suis le seul locataire
                                        </label>
                                    </div>
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        Continuer →
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <!-- Signature form -->
                            <form method="POST" action="" id="signatureForm">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="signature_data" id="signature_data">
                                
                                <div class="mb-4">
                                    <label class="form-label">Veuillez signer dans le cadre ci-dessous :</label>
                                    <div class="signature-container" style="max-width: 300px;">
                                        <canvas id="signatureCanvas" width="300" height="150" style="background: transparent; border: none; outline: none; padding: 0;"></canvas>
                                    </div>
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-warning" onclick="clearSignature()">
                                            Effacer
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="certifie_exact" 
                                               name="certifie_exact" value="1" required>
                                        <label class="form-check-label" for="certifie_exact">
                                            <strong>Certifié exact</strong> *
                                        </label>
                                    </div>
                                    <small class="form-text text-muted">Cette case est obligatoire pour valider votre signature</small>
                                </div>

                                <div class="alert alert-info">
                                    <small>
                                        <strong>Information :</strong> Votre signature sera horodatée et votre adresse IP enregistrée 
                                        pour des raisons de sécurité et de conformité légale.
                                    </small>
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        Valider la signature →
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$signatureSaved): ?>
    <script src="../assets/js/signature.js"></script>
    <script>
        // Initialiser le canvas de signature au chargement
        window.addEventListener('DOMContentLoaded', function() {
            console.log('Step2-Signature: Page chargée, initialisation du canvas...');
            initSignature();
        });

        // Valider le formulaire
        document.getElementById('signatureForm').addEventListener('submit', function(e) {
            console.log('Step2-Signature: Soumission du formulaire...');
            
            const signatureData = getSignatureData();
            
            if (!signatureData || signatureData === getEmptyCanvasData()) {
                e.preventDefault();
                console.error('Step2-Signature: Signature vide détectée');
                alert('Veuillez apposer votre signature avant de continuer.');
                return false;
            }
            
            console.log('Step2-Signature: ✓ Signature valide, envoi au serveur');
            console.log('Step2-Signature: Taille signature:', signatureData.length, 'bytes');
            
            document.getElementById('signature_data').value = signatureData;
        });
    </script>
    <?php endif; ?>
</body>
</html>
