<?php
/**
 * Garant / Garantie – Formulaire de déclaration par le locataire
 *
 * Accessible via : /garant/index.php?token=<token_garantie>
 *
 * Le locataire choisit le type de garantie (Visale ou Caution solidaire)
 * et, pour la caution solidaire, saisit les informations du garant.
 * Un email est ensuite envoyé au garant avec un lien sécurisé.
 *
 * My Invest Immobilier
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// ------------------------------------------------------------------
// Validation du token
// ------------------------------------------------------------------
$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    die('Token manquant. Veuillez utiliser le lien fourni dans votre email.');
}

// Le token_garantie est stocké dans la table contrats
$contrat = fetchOne("
    SELECT c.*,
           l.adresse,
           l.reference  AS ref_logement,
           l.loyer,
           l.charges
    FROM contrats c
    INNER JOIN logements l ON c.logement_id = l.id
    WHERE c.token_garantie = ?
", [$token]);

if (!$contrat) {
    die('Lien invalide ou expiré. Veuillez contacter My Invest Immobilier.');
}

if (!in_array($contrat['statut'], ['valide', 'actif', 'signe'], true)) {
    die('Ce lien n\'est plus disponible.');
}

// Locataire principal (ordre 1)
$locataire = fetchOne("
    SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 1
", [$contrat['id']]);

// Garant déjà existant pour ce contrat ?
$garantExistant = getGarantByContratId($contrat['id']);

$error   = '';
$success = false;

// ------------------------------------------------------------------
// Traitement POST
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Token CSRF invalide.';
    } else {
        $typeGarantie = $_POST['type_garantie'] ?? '';

        if (!in_array($typeGarantie, ['visale', 'caution_solidaire'], true)) {
            $error = 'Veuillez choisir un type de garantie.';
        } elseif ($typeGarantie === 'visale') {
            // ---- Visale ----
            $numeroVisale = trim($_POST['numero_visale'] ?? '');
            if (empty($numeroVisale)) {
                $error = 'Le numéro de visa certifié est obligatoire pour la garantie Visale.';
            } else {
                $garant = createGarant($contrat['id'], 'visale', [
                    'numero_visale' => $numeroVisale,
                    'nom'           => $locataire ? $locataire['nom']    : '',
                    'prenom'        => $locataire ? $locataire['prenom'] : '',
                    'email'         => $locataire ? $locataire['email']  : '',
                ]);

                if (!$garant) {
                    $error = 'Erreur lors de l\'enregistrement. Veuillez réessayer.';
                } else {
                    // Sauvegarder document Visale si fourni
                    if (!empty($_FILES['document_visale']['name']) && $_FILES['document_visale']['error'] !== UPLOAD_ERR_NO_FILE) {
                        $validation = validateUploadedFile($_FILES['document_visale']);
                        if ($validation['success']) {
                            if (saveUploadedFile($_FILES['document_visale'], $validation['filename'])) {
                                executeQuery("UPDATE garants SET document_visale = ? WHERE id = ?", [$validation['filename'], $garant['id']]);
                            }
                        }
                    }

                    // Notification admin (BCC)
                    $adminVars = [
                        'reference'         => $contrat['reference_unique'],
                        'adresse_logement'  => $contrat['adresse'],
                        'prenom_locataire'  => $locataire ? $locataire['prenom'] : '',
                        'nom_locataire'     => $locataire ? $locataire['nom']    : '',
                        'type_garantie'     => 'Garantie Visale',
                        'prenom_garant'     => $locataire ? $locataire['prenom'] : '',
                        'nom_garant'        => $locataire ? $locataire['nom']    : '',
                        'email_garant'      => $locataire ? $locataire['email']  : '',
                        'date_envoi'        => date('d/m/Y à H:i'),
                        'lien_admin'        => $config['SITE_URL'] . '/admin-v2/contrat-detail.php?id=' . $contrat['id'],
                    ];
                    sendTemplatedEmail('garant_notification_admin', getAdminEmail(), $adminVars, null, true, false, ['contexte' => 'contrat_id=' . $contrat['id']]);

                    logAction($contrat['id'], 'garantie_visale_soumise', 'Numéro Visale: ' . $numeroVisale);
                    $success = true;
                }
            }
        } else {
            // ---- Caution solidaire ----
            $nom           = trim($_POST['nom']           ?? '');
            $prenom        = trim($_POST['prenom']        ?? '');
            $dateNaissance = trim($_POST['date_naissance'] ?? '');
            $emailGarant   = trim($_POST['email']         ?? '');
            $telephone     = trim($_POST['telephone']     ?? '');
            $adresse       = trim($_POST['adresse']       ?? '');
            $ville         = trim($_POST['ville']         ?? '');
            $codePostal    = trim($_POST['code_postal']   ?? '');

            if (empty($nom) || empty($prenom) || empty($dateNaissance) || empty($emailGarant) || empty($adresse) || empty($ville)) {
                $error = 'Veuillez remplir tous les champs obligatoires.';
            } elseif (!filter_var($emailGarant, FILTER_VALIDATE_EMAIL)) {
                $error = 'L\'adresse email du garant est invalide.';
            } else {
                $garant = createGarant($contrat['id'], 'caution_solidaire', [
                    'nom'            => $nom,
                    'prenom'         => $prenom,
                    'date_naissance' => $dateNaissance,
                    'email'          => $emailGarant,
                    'telephone'      => $telephone,
                    'adresse'        => $adresse,
                    'ville'          => $ville,
                    'code_postal'    => $codePostal,
                ]);

                if (!$garant) {
                    $error = 'Erreur lors de l\'enregistrement. Veuillez réessayer.';
                } else {
                    $lienGarant      = $config['SITE_URL'] . '/garant-parcours/index.php?token=' . urlencode($garant['token']);
                    $emailContact    = $config['COMPANY_EMAIL'] ?? getAdminEmail();
                    $prenomLocataire = $locataire ? $locataire['prenom'] : '';
                    $nomLocataire    = $locataire ? $locataire['nom']    : '';

                    // Email au garant
                    sendTemplatedEmail('garant_invitation', $emailGarant, [
                        'prenom_garant'     => $prenom,
                        'nom_garant'        => $nom,
                        'prenom_locataire'  => $prenomLocataire,
                        'nom_locataire'     => $nomLocataire,
                        'adresse_logement'  => $contrat['adresse'],
                        'lien_garant'       => $lienGarant,
                        'email_contact'     => $emailContact,
                    ], null, false, false, ['contexte' => 'contrat_id=' . $contrat['id']]);

                    // Email de confirmation au locataire
                    if ($locataire && !empty($locataire['email'])) {
                        sendTemplatedEmail('garant_confirmation_locataire', $locataire['email'], [
                            'prenom_locataire' => $prenomLocataire,
                            'nom_locataire'    => $nomLocataire,
                            'prenom_garant'    => $prenom,
                            'nom_garant'       => $nom,
                            'email_garant'     => $emailGarant,
                            'email_contact'    => $emailContact,
                        ], null, false, false, ['contexte' => 'contrat_id=' . $contrat['id']]);
                    }

                    // Notification admin (BCC)
                    $adminVars = [
                        'reference'         => $contrat['reference_unique'],
                        'adresse_logement'  => $contrat['adresse'],
                        'prenom_locataire'  => $prenomLocataire,
                        'nom_locataire'     => $nomLocataire,
                        'type_garantie'     => 'Caution solidaire',
                        'prenom_garant'     => $prenom,
                        'nom_garant'        => $nom,
                        'email_garant'      => $emailGarant,
                        'date_envoi'        => date('d/m/Y à H:i'),
                        'lien_admin'        => $config['SITE_URL'] . '/admin-v2/contrat-detail.php?id=' . $contrat['id'],
                    ];
                    sendTemplatedEmail('garant_notification_admin', getAdminEmail(), $adminVars, null, true, false, ['contexte' => 'contrat_id=' . $contrat['id']]);

                    logAction($contrat['id'], 'garant_invite', "Garant: $prenom $nom ($emailGarant)");
                    $success = true;
                }
            }
        }
    }
}

$csrfToken = generateCsrfToken();
$typeGarantiePost = htmlspecialchars($_POST['type_garantie'] ?? 'caution_solidaire', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Déclaration de garant – My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="container mt-5 mb-5">
    <div class="text-center mb-4">
        <img src="../assets/images/logo.png" alt="My Invest Immobilier" class="logo mb-3"
             onerror="this.style.display='none'">
        <h1 class="h2">Déclaration de garant</h1>
    </div>

    <div class="row justify-content-center">
        <div class="col-md-8">

            <?php if ($success): ?>
            <div class="card shadow border-success">
                <div class="card-body text-center p-5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="70" height="70" fill="currentColor"
                         class="bi bi-check-circle-fill text-success mb-4" viewBox="0 0 16 16">
                        <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                    </svg>
                    <h2 class="text-success mb-3">Demande envoyée avec succès !</h2>
                    <p class="lead">Votre demande a bien été transmise.</p>
                    <p>Vous recevrez un email de confirmation. Votre garant sera également contacté avec un lien sécurisé pour finaliser son dossier.</p>
                </div>
            </div>

            <?php elseif ($garantExistant): ?>
            <div class="alert alert-info">
                <h5>Demande déjà soumise</h5>
                <p class="mb-0">
                    Une demande de garant est déjà enregistrée pour ce contrat.<br>
                    <strong>Statut :</strong> <?= htmlspecialchars(formatGarantStatut($garantExistant['statut'])) ?>
                </p>
            </div>

            <?php else: ?>
            <div class="card shadow">
                <div class="card-body">
                    <h4 class="card-title mb-4">Déclarer un garant</h4>

                    <div class="alert alert-info mb-4">
                        <p class="mb-1"><strong>Logement :</strong> <?= htmlspecialchars($contrat['adresse']) ?></p>
                        <?php if ($locataire): ?>
                        <p class="mb-0"><strong>Locataire :</strong> <?= htmlspecialchars($locataire['prenom'] . ' ' . $locataire['nom']) ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="" enctype="multipart/form-data" id="garantForm">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                        <!-- Choix du type de garantie -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">Type de garantie <span class="text-danger">*</span></label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="type_garantie" id="type_visale"
                                       value="visale" <?= $typeGarantiePost === 'visale' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="type_visale">
                                    Garantie Visale
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="type_garantie" id="type_caution"
                                       value="caution_solidaire" <?php $isCautionSelected = ($typeGarantiePost === 'caution_solidaire' || $typeGarantiePost === ''); echo $isCautionSelected ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="type_caution">
                                    Caution solidaire
                                </label>
                            </div>
                        </div>

                        <!-- Section Visale -->
                        <div id="section-visale" style="display:none;">
                            <hr>
                            <h5 class="mb-3">Garantie Visale</h5>
                            <div class="mb-3">
                                <label for="numero_visale" class="form-label">
                                    Numéro de visa certifié <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="numero_visale" name="numero_visale"
                                       placeholder="Ex : VS-XXXXXXXX"
                                       value="<?= htmlspecialchars($_POST['numero_visale'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label for="document_visale" class="form-label">
                                    Document justificatif Visale <span class="text-muted">(optionnel)</span>
                                </label>
                                <input type="file" class="form-control" id="document_visale" name="document_visale"
                                       accept=".jpg,.jpeg,.png,.pdf">
                                <small class="form-text text-muted">JPG, PNG ou PDF – max 5 Mo</small>
                            </div>
                        </div>

                        <!-- Section Caution solidaire -->
                        <div id="section-caution">
                            <hr>
                            <h5 class="mb-3">Informations du garant</h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="nom" class="form-label">Nom <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="nom" name="nom"
                                           value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="prenom" class="form-label">Prénom <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="prenom" name="prenom"
                                           value="<?= htmlspecialchars($_POST['prenom'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="date_naissance" class="form-label">Date de naissance <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="date_naissance" name="date_naissance"
                                       value="<?= htmlspecialchars($_POST['date_naissance'] ?? '') ?>">
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="email" name="email"
                                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="telephone" class="form-label">Téléphone</label>
                                    <input type="tel" class="form-control" id="telephone" name="telephone"
                                           value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="adresse" class="form-label">Adresse postale <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="adresse" name="adresse"
                                       value="<?= htmlspecialchars($_POST['adresse'] ?? '') ?>">
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="code_postal" class="form-label">Code postal</label>
                                    <input type="text" class="form-control" id="code_postal" name="code_postal"
                                           value="<?= htmlspecialchars($_POST['code_postal'] ?? '') ?>">
                                </div>
                                <div class="col-md-8 mb-3">
                                    <label for="ville" class="form-label">Ville <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="ville" name="ville"
                                           value="<?= htmlspecialchars($_POST['ville'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-primary btn-lg">
                                Envoyer la demande →
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var visaleRadio  = document.getElementById('type_visale');
    var cautionRadio = document.getElementById('type_caution');
    var secVisale    = document.getElementById('section-visale');
    var secCaution   = document.getElementById('section-caution');
    var numVisale    = document.getElementById('numero_visale');
    var fields       = ['nom','prenom','date_naissance','email','adresse','ville'];

    function toggle() {
        var isVisale = visaleRadio && visaleRadio.checked;
        if (secVisale)  secVisale.style.display  = isVisale ? '' : 'none';
        if (secCaution) secCaution.style.display = isVisale ? 'none' : '';

        // required attributes
        if (numVisale) numVisale.required = isVisale;
        fields.forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.required = !isVisale;
        });
    }

    if (visaleRadio)  visaleRadio.addEventListener('change', toggle);
    if (cautionRadio) cautionRadio.addEventListener('change', toggle);

    toggle(); // initial state
}());
</script>
</body>
</html>
