<?php
/**
 * Upload documents assurance habitation + sélection type de garantie
 * + parcours complet du garant (caution solidaire)
 *
 * Tout le parcours se déroule sur cette page :
 *   envoyer-assurance.php?token=XXXXX
 *
 * - Si le token correspond à un token_assurance (contrat) → formulaire locataire
 * - Si le token correspond à un token_garant (garants) → parcours garant
 *
 * My Invest Immobilier
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mail-templates.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    die('Token manquant. Veuillez utiliser le lien fourni dans votre email.');
}

// ---------------------------------------------------------------
// Détection du mode : locataire (token_assurance) ou garant
// ---------------------------------------------------------------
$contrat = fetchOne("
    SELECT c.*, l.adresse, l.reference as ref_logement
    FROM contrats c
    INNER JOIN logements l ON c.logement_id = l.id
    WHERE c.token_assurance = ?
", [$token]);

$mode   = 'assurance';
$garant = null;

if (!$contrat) {
    $garant = getGarantByToken($token);
    if ($garant) {
        $mode   = 'garant';
        $contrat = fetchOne("
            SELECT c.*, l.adresse, l.reference as ref_logement
            FROM contrats c
            INNER JOIN logements l ON c.logement_id = l.id
            WHERE c.id = ?
        ", [$garant['contrat_id']]);
    }
}

if (!$contrat && !$garant) {
    die('Lien invalide ou expiré. Veuillez contacter My Invest Immobilier.');
}

if ($mode === 'assurance' && $contrat['statut'] !== 'valide') {
    die('Ce lien n\'est plus valide.');
}

$error        = '';
$success      = false;
$currentStep  = '';  // utilisé en mode garant

// ================================================================
// MODE GARANT : engagement → info → signature → documents
// ================================================================
if ($mode === 'garant') {
    if ($garant['type_garantie'] !== 'caution_solidaire') {
        die('Ce lien n\'est pas applicable à votre type de garantie.');
    }

    $stepKey     = 'garant_step_' . $garant['id'];
    $statutGarant = $garant['statut'];

    // Déterminer l'étape à afficher selon le statut DB + session
    if ($statutGarant === 'documents_recus') {
        $currentStep = 'done';
    } elseif ($statutGarant === 'signe') {
        $currentStep = 'documents';
        $_SESSION[$stepKey] = 'documents';
    } elseif ($statutGarant === 'engage') {
        $currentStep = $_SESSION[$stepKey] ?? 'info';
        if (!in_array($currentStep, ['info', 'signature'], true)) {
            $currentStep = 'info';
        }
    } else {
        // en_attente_garant
        $currentStep = 'engagement';
        unset($_SESSION[$stepKey]);
    }

    // Traitement POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
            $error = 'Token CSRF invalide.';
        } else {
            $postAction = $_POST['garant_action'] ?? '';

            // --- Étape 1 : Engagement ---
            if ($postAction === 'engagement') {
                $choix = $_POST['choix'] ?? '';
                if ($choix === 'refuse') {
                    updateGarantStatut($garant['id'], 'en_attente_garant');
                    logAction($garant['contrat_id'], 'garant_refus', 'Le garant a refusé l\'engagement');
                    $currentStep = 'refused';

                    // Emails de notification du refus
                    $locataireRefus  = fetchOne("SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 1", [$garant['contrat_id']]);
                    $emailContact    = $config['COMPANY_EMAIL'] ?? getAdminEmail();
                    $refusVarsBase   = [
                        'prenom_garant'    => $garant['prenom'],
                        'nom_garant'       => $garant['nom'],
                        'prenom_locataire' => $locataireRefus ? $locataireRefus['prenom'] : '',
                        'nom_locataire'    => $locataireRefus ? $locataireRefus['nom']    : '',
                        'adresse_logement' => $garant['adresse_logement'],
                        'email_contact'    => $emailContact,
                    ];

                    // Email au garant
                    sendTemplatedEmail('garant_refus_notification', $garant['email'], array_merge($refusVarsBase, [
                        'prenom_destinataire' => $garant['prenom'],
                        'nom_destinataire'    => $garant['nom'],
                    ]), null, false, true, ['contexte' => 'contrat_id=' . $garant['contrat_id']]);

                    // Email au locataire
                    if ($locataireRefus && !empty($locataireRefus['email'])) {
                        sendTemplatedEmail('garant_refus_notification', $locataireRefus['email'], array_merge($refusVarsBase, [
                            'prenom_destinataire' => $locataireRefus['prenom'],
                            'nom_destinataire'    => $locataireRefus['nom'],
                        ]), null, false, true, ['contexte' => 'contrat_id=' . $garant['contrat_id']]);
                    }
                } elseif ($choix === 'accepte') {
                    updateGarantStatut($garant['id'], 'engage');
                    logAction($garant['contrat_id'], 'garant_engagement', 'Le garant a accepté d\'être garant');
                    $_SESSION[$stepKey] = 'info';
                    header('Location: envoyer-assurance.php?token=' . urlencode($token));
                    exit;
                }

            // --- Étape 2 : Informations ---
            } elseif ($postAction === 'info') {
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
                    executeQuery("
                        UPDATE garants
                        SET nom = ?, prenom = ?, date_naissance = ?, email = ?,
                            telephone = ?, adresse = ?, ville = ?, code_postal = ?
                        WHERE id = ?
                    ", [$nom, $prenom, $dateNaissance, $email, $telephone, $adresse, $ville, $codePostal, $garant['id']]);
                    logAction($garant['contrat_id'], 'garant_info_mise_a_jour', "Garant ID {$garant['id']}");
                    $_SESSION[$stepKey] = 'signature';
                    header('Location: envoyer-assurance.php?token=' . urlencode($token));
                    exit;
                }

            // --- Étape 3 : Signature ---
            } elseif ($postAction === 'signature') {
                $signatureData = $_POST['signature_data'] ?? '';
                $certifieExact = isset($_POST['certifie_exact']) ? 1 : 0;

                if (empty($signatureData)) {
                    $error = 'Veuillez apposer votre signature.';
                } elseif (!$certifieExact) {
                    $error = 'Veuillez cocher la case "Certifié exact" pour continuer.';
                } else {
                    if (saveGarantSignature($garant['id'], $signatureData, $certifieExact)) {
                        require_once __DIR__ . '/pdf/generate-caution-solidaire.php';
                        $pdfPath = generateCautionSolidairePDF($garant['id']);
                        if ($pdfPath) {
                            executeQuery("UPDATE garants SET document_caution = ? WHERE id = ?", [$pdfPath, $garant['id']]);
                        }
                        logAction($garant['contrat_id'], 'garant_signature', "Garant ID {$garant['id']} a signé");
                        $_SESSION[$stepKey] = 'documents';
                        header('Location: envoyer-assurance.php?token=' . urlencode($token));
                        exit;
                    } else {
                        $error = 'Erreur lors de la sauvegarde de la signature. Veuillez réessayer.';
                    }
                }

            // --- Étape 4 : Justificatifs ---
            } elseif ($postAction === 'documents') {
                // Fichiers obligatoires
                $requiredFiles = [
                    'piece_identite_recto' => 'Veuillez télécharger votre pièce d\'identité (recto).',
                    'bulletin_salaire_1'   => 'Veuillez télécharger votre 1er bulletin de salaire.',
                    'bulletin_salaire_2'   => 'Veuillez télécharger votre 2ème bulletin de salaire.',
                    'bulletin_salaire_3'   => 'Veuillez télécharger votre 3ème bulletin de salaire.',
                    'fiche_imposition'     => 'Veuillez télécharger votre dernière fiche d\'imposition.',
                    'justificatif_domicile'=> 'Veuillez télécharger votre justificatif de domicile (moins de 3 mois).',
                ];

                $validatedFiles = [];

                foreach ($requiredFiles as $field => $errorMsg) {
                    $file = $_FILES[$field] ?? null;
                    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                        $error = $errorMsg;
                        break;
                    }
                    $validation = validateUploadedFile($file);
                    if (!$validation['success']) {
                        $error = $validation['error'] . ' (' . $field . ')';
                        break;
                    }
                    $validatedFiles[$field] = ['file' => $file, 'filename' => $validation['filename']];
                }

                // Pièce d'identité verso (optionnelle)
                if (!$error) {
                    $versoFile = $_FILES['piece_identite_verso'] ?? null;
                    if ($versoFile && $versoFile['error'] !== UPLOAD_ERR_NO_FILE) {
                        $validationVerso = validateUploadedFile($versoFile);
                        if (!$validationVerso['success']) {
                            $error = 'Pièce d\'identité (verso) : ' . $validationVerso['error'];
                        } else {
                            $validatedFiles['piece_identite_verso'] = ['file' => $versoFile, 'filename' => $validationVerso['filename']];
                        }
                    }
                }

                if (!$error) {
                    // Sauvegarder tous les fichiers
                    $savedFilenames = [];
                    foreach ($validatedFiles as $field => $data) {
                        if (!saveUploadedFile($data['file'], $data['filename'])) {
                            $error = 'Erreur lors de la sauvegarde du fichier (' . $field . ').';
                            break;
                        }
                        $savedFilenames[$field] = $data['filename'];
                    }

                    if (!$error) {
                        executeQuery(
                            "UPDATE garants
                             SET piece_identite_recto  = ?,
                                 piece_identite_verso   = ?,
                                 bulletin_salaire_1     = ?,
                                 bulletin_salaire_2     = ?,
                                 bulletin_salaire_3     = ?,
                                 fiche_imposition       = ?,
                                 justificatif_domicile  = ?,
                                 statut                 = 'documents_recus',
                                 date_documents         = NOW()
                             WHERE id = ?",
                            [
                                $savedFilenames['piece_identite_recto'],
                                $savedFilenames['piece_identite_verso'] ?? null,
                                $savedFilenames['bulletin_salaire_1'],
                                $savedFilenames['bulletin_salaire_2'],
                                $savedFilenames['bulletin_salaire_3'],
                                $savedFilenames['fiche_imposition'],
                                $savedFilenames['justificatif_domicile'],
                                $garant['id'],
                            ]
                        );
                        logAction($garant['contrat_id'], 'garant_documents_recus', "Documents reçus pour garant ID {$garant['id']}");

                            // Emails de finalisation
                            $locataireG     = fetchOne("SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 1", [$garant['contrat_id']]);
                            $emailContact    = $config['COMPANY_EMAIL'] ?? getAdminEmail();
                            $dateFinalisation = date('d/m/Y à H:i');
                            $lienDocument    = '';
                            if (!empty($garant['document_caution'])) {
                                $lienDocument = documentPathToUrl($garant['document_caution']);
                            }
                            if (empty($lienDocument)) {
                                $lienDocument = $config['SITE_URL'] . '/admin-v2/contrat-detail.php?id=' . $garant['contrat_id'];
                            }

                            $vars = [
                                'prenom_garant'     => $garant['prenom'],
                                'nom_garant'        => $garant['nom'],
                                'prenom_locataire'  => $locataireG ? $locataireG['prenom'] : '',
                                'nom_locataire'     => $locataireG ? $locataireG['nom']    : '',
                                'adresse_logement'  => $garant['adresse_logement'],
                                'date_finalisation' => $dateFinalisation,
                                'lien_document'     => $lienDocument,
                                'email_contact'     => $emailContact,
                            ];

                            sendTemplatedEmail('garant_finalisation', $garant['email'], array_merge($vars, [
                                'prenom_destinataire' => $garant['prenom'],
                                'nom_destinataire'    => $garant['nom'],
                            ]), null, false, true, ['contexte' => 'contrat_id=' . $garant['contrat_id']]);

                            if ($locataireG && !empty($locataireG['email'])) {
                                sendTemplatedEmail('garant_finalisation', $locataireG['email'], array_merge($vars, [
                                    'prenom_destinataire' => $locataireG['prenom'],
                                    'nom_destinataire'    => $locataireG['nom'],
                                ]), null, false, true, ['contexte' => 'contrat_id=' . $garant['contrat_id']]);
                            }

                            $_SESSION[$stepKey] = 'done';
                            header('Location: envoyer-assurance.php?token=' . urlencode($token));
                            exit;
                    }
                }
            }
        }
    }

    // Recharger le garant après traitement pour actualiser les données
    $garant = getGarantByToken($token);
    $locataireGarant = fetchOne("SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 1", [$garant['contrat_id']]);

    // Recalculer l'étape après traitement (en cas d'erreur, on reste sur la même étape)
    if (empty($error)) {
        $statutGarant = $garant['statut'];
        if ($statutGarant === 'documents_recus') {
            $currentStep = 'done';
        } elseif ($statutGarant === 'signe') {
            $currentStep = 'documents';
        } elseif ($statutGarant === 'engage') {
            $currentStep = $_SESSION[$stepKey] ?? 'info';
            if (!in_array($currentStep, ['info', 'signature'], true)) {
                $currentStep = 'info';
            }
        } elseif ($currentStep !== 'refused') {
            $currentStep = 'engagement';
        }
    }
}

// ================================================================
// MODE ASSURANCE : formulaire locataire (assurance + type garantie)
// ================================================================
elseif ($mode === 'assurance') {
    // Si l'assurance a déjà été soumise, afficher directement le succès (anti-doublon)
    if (!empty($contrat['assurance_habitation'])) {
        $success = true;
    }

    if (!$success && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
            $error = 'Token CSRF invalide.';
        } else {
            $assuranceFile  = $_FILES['assurance_habitation'] ?? null;
            $typeGarantie   = $_POST['type_garantie'] ?? 'visale';

            // Validation : attestation d'assurance habitation toujours obligatoire
            if (!$assuranceFile || $assuranceFile['error'] === UPLOAD_ERR_NO_FILE) {
                $error = 'Veuillez télécharger votre attestation d\'assurance habitation.';
            } else {
                $validationAssurance = validateUploadedFile($assuranceFile);
                if (!$validationAssurance['success']) {
                    $error = 'Attestation d\'assurance : ' . $validationAssurance['error'];
                }
            }

            // Validation selon type de garantie
            if (empty($error)) {
                if ($typeGarantie === 'visale') {
                    $visaFile = $_FILES['visa_certifie'] ?? null;
                    if (!$visaFile || $visaFile['error'] === UPLOAD_ERR_NO_FILE) {
                        $error = 'Le visa certifié est obligatoire pour la garantie Visale.';
                    } else {
                        $validationVisa = validateUploadedFile($visaFile);
                        if (!$validationVisa['success']) {
                            $error = 'Visa certifié : ' . $validationVisa['error'];
                        }
                    }
                } elseif ($typeGarantie === 'caution_solidaire') {
                    $csNom           = trim($_POST['cs_nom']           ?? '');
                    $csPrenom        = trim($_POST['cs_prenom']        ?? '');
                    $csEmail         = trim($_POST['cs_email']         ?? '');

                    if (empty($csNom) || empty($csPrenom) || empty($csEmail)) {
                        $error = 'Veuillez remplir tous les champs obligatoires du garant.';
                    } elseif (!filter_var($csEmail, FILTER_VALIDATE_EMAIL)) {
                        $error = 'L\'adresse email du garant est invalide.';
                    }
                }
            }

            if (empty($error)) {
                // Sauvegarder l'attestation d'assurance
                if (!saveUploadedFile($assuranceFile, $validationAssurance['filename'])) {
                    $error = 'Erreur lors de la sauvegarde du fichier d\'assurance.';
                } else {
                    $visaCertifieFilename = null;
                    $numeroVisale         = trim($_POST['numero_visale'] ?? '');

                    if ($typeGarantie === 'visale' && isset($validationVisa)) {
                        if (saveUploadedFile($visaFile, $validationVisa['filename'])) {
                            $visaCertifieFilename = $validationVisa['filename'];
                        }
                    }

                    // Mettre à jour le contrat
                    $stmt = $pdo->prepare("
                        UPDATE contrats
                        SET assurance_habitation = ?,
                            numero_visale = ?,
                            visa_certifie = ?,
                            date_envoi_assurance = NOW()
                        WHERE id = ?
                    ");

                    if (!$stmt->execute([
                        $validationAssurance['filename'],
                        $numeroVisale,
                        $visaCertifieFilename,
                        $contrat['id'],
                    ])) {
                        $error = 'Erreur lors de l\'enregistrement des informations.';
                    } else {
                        logAction($contrat['id'], 'assurance_visale_recu', 'Documents assurance/Visale uploadés');

                        // Supprimer les garants en attente avant d'en créer un nouveau
                        deleteGarantsPending($contrat['id']);

                        // Créer le garant selon le type choisi
                        if ($typeGarantie === 'visale') {
                            $locatairePrincipal = fetchOne("SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 1", [$contrat['id']]);
                            $garantRec = createGarant($contrat['id'], 'visale', [
                                'numero_visale' => $numeroVisale,
                                'nom'           => $locatairePrincipal ? $locatairePrincipal['nom']    : '',
                                'prenom'        => $locatairePrincipal ? $locatairePrincipal['prenom'] : '',
                                'email'         => $locatairePrincipal ? $locatairePrincipal['email']  : '',
                            ]);
                            if ($garantRec && $visaCertifieFilename) {
                                executeQuery("UPDATE garants SET document_visale = ? WHERE id = ?", [$visaCertifieFilename, $garantRec['id']]);
                            }

                            // Notification admin
                            $locatairePrincipal = $locatairePrincipal ?? fetchOne("SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 1", [$contrat['id']]);
                            sendTemplatedEmail('garant_notification_admin', getAdminEmail(), [
                                'reference'         => $contrat['reference_unique'],
                                'adresse_logement'  => $contrat['adresse'],
                                'prenom_locataire'  => $locatairePrincipal ? $locatairePrincipal['prenom'] : '',
                                'nom_locataire'     => $locatairePrincipal ? $locatairePrincipal['nom']    : '',
                                'type_garantie'     => 'Institutionnelle (ex: Visale)',
                                'prenom_garant'     => $locatairePrincipal ? $locatairePrincipal['prenom'] : '',
                                'nom_garant'        => $locatairePrincipal ? $locatairePrincipal['nom']    : '',
                                'email_garant'      => $locatairePrincipal ? $locatairePrincipal['email']  : '',
                                'date_envoi'        => date('d/m/Y à H:i'),
                                'lien_admin'        => $config['SITE_URL'] . '/admin-v2/contrat-detail.php?id=' . $contrat['id'],
                            ], null, true, false, ['contexte' => 'contrat_id=' . $contrat['id']]);

                            logAction($contrat['id'], 'garantie_visale_soumise', 'Numéro Visale: ' . $numeroVisale);

                        } elseif ($typeGarantie === 'caution_solidaire') {
                            $garantRec = createGarant($contrat['id'], 'caution_solidaire', [
                                'nom'    => $csNom,
                                'prenom' => $csPrenom,
                                'email'  => $csEmail,
                            ]);

                            if ($garantRec) {
                                $lienGarant      = $config['SITE_URL'] . '/envoyer-assurance.php?token=' . urlencode($garantRec['token']);
                                $emailContact    = $config['COMPANY_EMAIL'] ?? getAdminEmail();
                                $locatairePrinc  = fetchOne("SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 1", [$contrat['id']]);
                                $prenomLocataire = $locatairePrinc ? $locatairePrinc['prenom'] : '';
                                $nomLocataire    = $locatairePrinc ? $locatairePrinc['nom']    : '';

                                // Email d'invitation au garant
                                sendTemplatedEmail('garant_invitation', $csEmail, [
                                    'prenom_garant'    => $csPrenom,
                                    'nom_garant'       => $csNom,
                                    'prenom_locataire' => $prenomLocataire,
                                    'nom_locataire'    => $nomLocataire,
                                    'adresse_logement' => $contrat['adresse'],
                                    'lien_garant'      => $lienGarant,
                                    'email_contact'    => $emailContact,
                                ], null, false, false, ['contexte' => 'contrat_id=' . $contrat['id']]);

                                // Confirmation au locataire
                                if ($locatairePrinc && !empty($locatairePrinc['email'])) {
                                    sendTemplatedEmail('garant_confirmation_locataire', $locatairePrinc['email'], [
                                        'prenom_locataire' => $prenomLocataire,
                                        'nom_locataire'    => $nomLocataire,
                                        'prenom_garant'    => $csPrenom,
                                        'nom_garant'       => $csNom,
                                        'email_garant'     => $csEmail,
                                        'email_contact'    => $emailContact,
                                    ], null, false, true, ['contexte' => 'contrat_id=' . $contrat['id']]);
                                }

                                // Notification admin
                                sendTemplatedEmail('garant_notification_admin', getAdminEmail(), [
                                    'reference'         => $contrat['reference_unique'],
                                    'adresse_logement'  => $contrat['adresse'],
                                    'prenom_locataire'  => $prenomLocataire,
                                    'nom_locataire'     => $nomLocataire,
                                    'type_garantie'     => 'Solidaire (personne physique)',
                                    'prenom_garant'     => $csPrenom,
                                    'nom_garant'        => $csNom,
                                    'email_garant'      => $csEmail,
                                    'date_envoi'        => date('d/m/Y à H:i'),
                                    'lien_admin'        => $config['SITE_URL'] . '/admin-v2/contrat-detail.php?id=' . $contrat['id'],
                                ], null, true, false, ['contexte' => 'contrat_id=' . $contrat['id']]);

                                logAction($contrat['id'], 'garant_invite', "Garant: $csPrenom $csNom ($csEmail)");
                            }
                        }

                        // Notifications standard assurance (locataires uniquement)
                        $locataires = getTenantsByContract($contrat['id']);
                        if (!empty($locataires)) {
                            foreach ($locataires as $loc) {
                                if (!empty($loc['email'])) {
                                    sendTemplatedEmail('confirmation_assurance_visale_locataire', $loc['email'], [
                                        'nom'       => $loc['nom'],
                                        'prenom'    => $loc['prenom'],
                                        'reference' => $contrat['reference_unique'],
                                    ], null, false, false);
                                }
                            }
                        }

                        $success = true;
                        // PRG : rediriger pour éviter la re-soumission du formulaire
                        header('Location: envoyer-assurance.php?token=' . urlencode($token));
                        exit;
                    }
                }
            }
        }
    }
}

$csrfToken = generateCsrfToken();

// Valeurs POST pour pré-remplissage en cas d'erreur (mode assurance)
$postTypeGarantie = htmlspecialchars($_POST['type_garantie'] ?? 'visale', ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents requis – My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="container mt-5 mb-5">
    <div class="text-center mb-4">
        <img src="assets/images/logo.png" alt="My Invest Immobilier" class="logo mb-3"
             onerror="this.style.display='none'">
        <h1 class="h2">
            <?= $mode === 'garant' ? 'Espace garant' : 'Envoi des documents requis' ?>
        </h1>
    </div>

    <?php /* ========================================================
           MODE GARANT
           ======================================================== */
    if ($mode === 'garant'): ?>

    <!-- Barre de progression (caution solidaire uniquement) -->
    <?php
    $progressMap = ['engagement' => 25, 'info' => 50, 'signature' => 75, 'documents' => 100, 'done' => 100, 'refused' => 0];
    $progressLabels = ['engagement' => 'Étape 1/4 – Engagement', 'info' => 'Étape 2/4 – Informations', 'signature' => 'Étape 3/4 – Signature', 'documents' => 'Étape 4/4 – Justificatifs', 'done' => 'Dossier finalisé', 'refused' => ''];
    $progressPct   = $progressMap[$currentStep] ?? 0;
    $progressLabel = $progressLabels[$currentStep] ?? '';
    $progressColor = ($currentStep === 'done') ? 'bg-success' : 'bg-primary';
    if ($progressPct > 0):
    ?>
    <div class="mb-4">
        <div class="progress" style="height: 28px;">
            <div class="progress-bar <?= $progressColor ?>" role="progressbar" style="width:<?= $progressPct ?>%">
                <?= htmlspecialchars($progressLabel) ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-md-8">

        <?php if ($currentStep === 'done'): ?>
        <!-- ---- Étape finale : Confirmation ---- -->
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
                    <p class="mb-0"><strong>Garant :</strong> <?= htmlspecialchars($garant['prenom'] . ' ' . $garant['nom']) ?></p>
                </div>
                <p class="mt-4">Vous recevrez un email avec le lien vers votre document de caution solidaire.</p>
                <p class="text-muted">
                    Pour toute question : <a href="mailto:<?= htmlspecialchars($config['COMPANY_EMAIL'] ?? '') ?>"><?= htmlspecialchars($config['COMPANY_EMAIL'] ?? '') ?></a>
                </p>
            </div>
        </div>

        <?php elseif ($currentStep === 'refused'): ?>
        <!-- ---- Refus enregistré ---- -->
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

        <?php elseif ($currentStep === 'engagement'): ?>
        <!-- ---- Étape 1 : Engagement ---- -->
        <div class="card shadow">
            <div class="card-body">
                <h4 class="card-title mb-4">Confirmation d'engagement</h4>

                <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="alert alert-info">
                    <p class="mb-1">Vous avez été désigné(e) comme <strong>garant(e)</strong> pour la location du logement suivant :</p>
                    <ul class="mb-0">
                        <li><strong>Logement :</strong> <?= htmlspecialchars($garant['adresse_logement']) ?></li>
                        <?php if ($locataireGarant): ?>
                        <li><strong>Locataire :</strong> <?= htmlspecialchars($locataireGarant['prenom'] . ' ' . $locataireGarant['nom']) ?></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <p>En tant que <strong>caution solidaire</strong>, vous vous engagez à payer le loyer et les charges si le locataire ne peut pas le faire.</p>

                <div class="alert alert-warning">
                    <strong>⚠️ Rappel de vos obligations :</strong>
                    <ul class="mb-0 mt-2">
                        <li>En cas de défaillance du locataire, le bailleur pourra se retourner directement contre vous.</li>
                        <li>Votre engagement est valable pour toute la durée du bail et ses renouvellements.</li>
                        <li>Un document de caution solidaire vous sera soumis à signature électronique.</li>
                    </ul>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="garant_action" value="engagement">
                    <div class="d-flex gap-3 mt-4">
                        <button type="submit" name="choix" value="accepte" class="btn btn-primary btn-lg flex-grow-1">
                            J'accepte d'être garant →
                        </button>
                        <button type="submit" name="choix" value="refuse" class="btn btn-outline-danger btn-lg">
                            Refuser
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php elseif ($currentStep === 'info'): ?>
        <!-- ---- Étape 2 : Informations personnelles ---- -->
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
                    <input type="hidden" name="garant_action" value="info">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="nom" class="form-label">Nom <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="nom" name="nom"
                                   value="<?= htmlspecialchars($_POST['nom'] ?? $garant['nom'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="prenom" class="form-label">Prénom <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="prenom" name="prenom"
                                   value="<?= htmlspecialchars($_POST['prenom'] ?? $garant['prenom'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="date_naissance" class="form-label">Date de naissance <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="date_naissance" name="date_naissance"
                               value="<?= htmlspecialchars($_POST['date_naissance'] ?? $garant['date_naissance'] ?? '') ?>" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= htmlspecialchars($_POST['email'] ?? $garant['email'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="telephone" class="form-label">Téléphone</label>
                            <input type="tel" class="form-control" id="telephone" name="telephone"
                                   value="<?= htmlspecialchars($_POST['telephone'] ?? $garant['telephone'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="adresse" class="form-label">Adresse postale <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="adresse" name="adresse"
                               value="<?= htmlspecialchars($_POST['adresse'] ?? $garant['adresse'] ?? '') ?>" required>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="code_postal" class="form-label">Code postal</label>
                            <input type="text" class="form-control" id="code_postal" name="code_postal"
                                   value="<?= htmlspecialchars($_POST['code_postal'] ?? $garant['code_postal'] ?? '') ?>">
                        </div>
                        <div class="col-md-8 mb-3">
                            <label for="ville" class="form-label">Ville <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="ville" name="ville"
                                   value="<?= htmlspecialchars($_POST['ville'] ?? $garant['ville'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="d-grid mt-3">
                        <button type="submit" class="btn btn-primary btn-lg">Continuer → Signature</button>
                    </div>
                </form>
            </div>
        </div>

        <?php elseif ($currentStep === 'signature'): ?>
        <!-- ---- Étape 3 : Signature électronique ---- -->
        <div class="card shadow">
            <div class="card-body">
                <h4 class="card-title mb-4">Signature électronique</h4>

                <div class="alert alert-secondary mb-4">
                    <p class="mb-1"><strong>Logement :</strong> <?= htmlspecialchars($garant['adresse_logement']) ?></p>
                    <p class="mb-0">
                        <strong>Garant :</strong> <?= htmlspecialchars($garant['prenom'] . ' ' . $garant['nom']) ?>
                        <?php if ($locataireGarant): ?>
                        &nbsp;|&nbsp; <strong>Locataire :</strong> <?= htmlspecialchars($locataireGarant['prenom'] . ' ' . $locataireGarant['nom']) ?>
                        <?php endif; ?>
                    </p>
                </div>

                <p>En signant, vous confirmez votre engagement en tant que caution solidaire pour ce contrat de location.</p>

                <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="" id="signatureForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="garant_action" value="signature">
                    <input type="hidden" name="signature_data" id="signature_data">

                    <div class="mb-4">
                        <label class="form-label">Signez dans le cadre ci-dessous :</label>
                        <div class="signature-container" style="max-width:300px;">
                            <canvas id="signatureCanvas" width="300" height="150"
                                    style="background:transparent;border:none;outline:none;padding:0;"></canvas>
                        </div>
                        <div class="mt-2">
                            <button type="button" class="btn btn-warning btn-sm" onclick="clearSignature()">Effacer</button>
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
                        <small>Votre signature sera horodatée et votre adresse IP enregistrée pour des raisons de sécurité et de conformité légale.</small>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">Valider la signature →</button>
                    </div>
                </form>
            </div>
        </div>

        <?php elseif ($currentStep === 'documents'): ?>
        <!-- ---- Étape 4 : Justificatifs ---- -->
        <div class="card shadow">
            <div class="card-body">
                <h4 class="card-title mb-4">Justificatifs</h4>

                <div class="alert alert-secondary mb-4">
                    <strong>Logement :</strong> <?= htmlspecialchars($garant['adresse_logement']) ?><br>
                    <strong>Garant :</strong> <?= htmlspecialchars($garant['prenom'] . ' ' . $garant['nom']) ?>
                </div>

                <p>Merci de télécharger l'ensemble des justificatifs ci-dessous. Les champs marqués <span class="text-danger">*</span> sont obligatoires.</p>

                <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="garant_action" value="documents">

                    <!-- Pièce d'identité recto -->
                    <div class="mb-3">
                        <label for="piece_identite_recto" class="form-label fw-semibold">
                            Pièce d'identité (recto) <span class="text-danger">*</span>
                        </label>
                        <input type="file" class="form-control" id="piece_identite_recto" name="piece_identite_recto"
                               accept=".jpg,.jpeg,.png,.pdf" required>
                        <small class="form-text text-muted">
                            Carte nationale d'identité, passeport ou titre de séjour – JPG, PNG ou PDF – max 5 Mo
                        </small>
                    </div>

                    <!-- Pièce d'identité verso (optionnel) -->
                    <div class="mb-4">
                        <label for="piece_identite_verso" class="form-label fw-semibold">
                            Pièce d'identité (verso) <span class="text-muted fw-normal">(optionnel – non requis pour le passeport)</span>
                        </label>
                        <input type="file" class="form-control" id="piece_identite_verso" name="piece_identite_verso"
                               accept=".jpg,.jpeg,.png,.pdf">
                        <small class="form-text text-muted">JPG, PNG ou PDF – max 5 Mo</small>
                    </div>

                    <!-- 3 derniers bulletins de salaire -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            Trois derniers bulletins de salaire <span class="text-danger">*</span>
                        </label>
                        <div class="mb-2">
                            <small class="text-muted d-block mb-1">1er bulletin de salaire</small>
                            <input type="file" class="form-control" id="bulletin_salaire_1" name="bulletin_salaire_1"
                                   accept=".jpg,.jpeg,.png,.pdf" required aria-label="1er bulletin de salaire">
                        </div>
                        <div class="mb-2">
                            <small class="text-muted d-block mb-1">2ème bulletin de salaire</small>
                            <input type="file" class="form-control" id="bulletin_salaire_2" name="bulletin_salaire_2"
                                   accept=".jpg,.jpeg,.png,.pdf" required aria-label="2ème bulletin de salaire">
                        </div>
                        <div>
                            <small class="text-muted d-block mb-1">3ème bulletin de salaire</small>
                            <input type="file" class="form-control" id="bulletin_salaire_3" name="bulletin_salaire_3"
                                   accept=".jpg,.jpeg,.png,.pdf" required aria-label="3ème bulletin de salaire">
                        </div>
                        <small class="form-text text-muted mt-1 d-block">JPG, PNG ou PDF – max 5 Mo par fichier</small>
                    </div>

                    <!-- Dernière fiche d'imposition -->
                    <div class="mb-4">
                        <label for="fiche_imposition" class="form-label fw-semibold">
                            Dernière fiche d'imposition <span class="text-danger">*</span>
                        </label>
                        <input type="file" class="form-control" id="fiche_imposition" name="fiche_imposition"
                               accept=".jpg,.jpeg,.png,.pdf" required>
                        <small class="form-text text-muted">
                            Avis d'imposition ou de non-imposition – JPG, PNG ou PDF – max 5 Mo
                        </small>
                    </div>

                    <!-- Justificatif de domicile -->
                    <div class="mb-4">
                        <label for="justificatif_domicile" class="form-label fw-semibold">
                            Justificatif de domicile (moins de 3 mois) <span class="text-danger">*</span>
                        </label>
                        <input type="file" class="form-control" id="justificatif_domicile" name="justificatif_domicile"
                               accept=".jpg,.jpeg,.png,.pdf" required>
                        <small class="form-text text-muted">
                            Facture d'énergie, quittance de loyer ou relevé bancaire – JPG, PNG ou PDF – max 5 Mo
                        </small>
                    </div>

                    <div class="alert alert-info">
                        <strong>Documents acceptés pour chaque champ :</strong>
                        <ul class="mb-0 mt-1">
                            <li>Format : JPG, PNG ou PDF</li>
                            <li>Taille maximale : 5 Mo par fichier</li>
                        </ul>
                    </div>

                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-success btn-lg">Finaliser mon dossier ✓</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        </div>
    </div>

    <?php /* ========================================================
           MODE ASSURANCE (locataire)
           ======================================================== */
    else: ?>

    <div class="row justify-content-center">
        <div class="col-md-8">
            <?php if ($success): ?>
            <!-- ---- Succès ---- -->
            <div class="card shadow border-success">
                <div class="card-body text-center p-5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" fill="currentColor"
                         class="bi bi-check-circle-fill text-success mb-4" viewBox="0 0 16 16">
                        <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                    </svg>
                    <h2 class="text-success mb-4">Documents envoyés avec succès !</h2>
                    <p class="lead mb-4">Vos documents ont bien été transmis à notre équipe.</p>
                    <div class="alert alert-info text-start">
                        <h5>Prochaines étapes :</h5>
                        <ol class="mb-0">
                            <li class="mb-2">Notre équipe va vérifier vos documents</li>
                            <li class="mb-2">Vous serez contacté(e) pour organiser l'entrée dans les lieux</li>
                        </ol>
                    </div>
                    <p class="mt-4">Pour toute question :</p>
                    <p>
                        <strong><?= htmlspecialchars($config['COMPANY_NAME']) ?></strong><br>
                        Email : <a href="mailto:<?= htmlspecialchars($config['COMPANY_EMAIL']) ?>"><?= htmlspecialchars($config['COMPANY_EMAIL']) ?></a>
                    </p>
                </div>
            </div>

            <?php else: ?>
            <!-- ---- Formulaire locataire ---- -->
            <div class="card shadow">
                <div class="card-body">
                    <h4 class="card-title mb-3">Documents requis</h4>

                    <div class="alert alert-info mb-4">
                        <p class="mb-2"><strong>📋 Référence du contrat :</strong> <?= htmlspecialchars($contrat['reference_unique']) ?></p>
                        <p class="mb-0">Merci de transmettre les documents suivants afin de finaliser définitivement votre dossier.</p>
                    </div>

                    <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="" enctype="multipart/form-data" id="assuranceForm">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                        <!-- Attestation assurance (toujours obligatoire) -->
                        <div class="mb-4">
                            <label for="assurance_habitation" class="form-label fw-semibold">
                                Attestation d'assurance habitation <span class="text-danger">*</span>
                            </label>
                            <input type="file" class="form-control" id="assurance_habitation"
                                   name="assurance_habitation" accept=".jpg,.jpeg,.png,.pdf" required>
                            <small class="form-text text-muted">Formats : JPG, PNG, PDF – Taille max : 5 Mo</small>
                        </div>

                        <hr class="my-4">

                        <!-- Type de garantie -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Type de garantie</label>
                            <div class="d-flex flex-column gap-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="type_garantie"
                                           id="tg_visale" value="visale"
                                           <?= $postTypeGarantie === 'visale' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="tg_visale">Institutionnelle (ex: Visale)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="type_garantie"
                                           id="tg_caution" value="caution_solidaire"
                                           <?= $postTypeGarantie === 'caution_solidaire' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="tg_caution">Solidaire (personne physique)</label>
                                </div>
                            </div>
                        </div>

                        <!-- Champs Visale (affichés si Visale sélectionné) -->
                        <div id="visale-fields" class="<?= $postTypeGarantie === 'visale' ? '' : 'd-none' ?>">
                            <div class="card border-primary mb-4">
                                <div class="card-header bg-primary text-white">Institutionnelle (ex: Visale)</div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="visa_certifie" class="form-label">
                                            Visa certifié <span class="text-danger">*</span>
                                        </label>
                                        <input type="file" class="form-control" id="visa_certifie"
                                               name="visa_certifie" accept=".jpg,.jpeg,.png,.pdf">
                                        <small class="form-text text-muted">Formats : JPG, PNG, PDF – Taille max : 5 Mo</small>
                                    </div>
                                    <div class="mb-3">
                                        <label for="numero_visale" class="form-label">Numéro de garantie Visale</label>
                                        <input type="text" class="form-control" id="numero_visale"
                                               name="numero_visale" placeholder="Ex: VS-XXXXXXXX"
                                               value="<?= htmlspecialchars($_POST['numero_visale'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Champs Caution solidaire -->
                        <div id="caution-fields" class="<?= $postTypeGarantie === 'caution_solidaire' ? '' : 'd-none' ?>">
                            <div class="card border-warning mb-4">
                                <div class="card-header bg-warning">Informations du garant (solidaire – personne physique)</div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="cs_nom" class="form-label">Nom du garant <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="cs_nom" name="cs_nom"
                                                   value="<?= htmlspecialchars($_POST['cs_nom'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="cs_prenom" class="form-label">Prénom du garant <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="cs_prenom" name="cs_prenom"
                                                   value="<?= htmlspecialchars($_POST['cs_prenom'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="cs_email" class="form-label">Email <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" id="cs_email" name="cs_email"
                                               value="<?= htmlspecialchars($_POST['cs_email'] ?? '') ?>">
                                    </div>
                                    <p class="text-muted small mb-0">
                                        Un email sera envoyé au garant avec un lien pour compléter et signer son dossier.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">Envoyer mes documents →</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php if ($mode === 'garant' && $currentStep === 'signature'): ?>
<script src="assets/js/signature.js"></script>
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
<?php endif; ?>

<?php if ($mode === 'assurance' && !$success): ?>
<script>
(function () {
    var radios = document.querySelectorAll('input[name="type_garantie"]');
    var visaleFields  = document.getElementById('visale-fields');
    var cautionFields = document.getElementById('caution-fields');
    var visaInput     = document.getElementById('visa_certifie');

    function updateFields() {
        var checked = document.querySelector('input[name="type_garantie"]:checked');
        var val = checked ? checked.value : 'visale';
        visaleFields.classList.toggle('d-none', val !== 'visale');
        cautionFields.classList.toggle('d-none', val !== 'caution_solidaire');

        // Gérer required sur le visa certifié
        if (visaInput) {
            visaInput.required = (val === 'visale');
        }

        // Gérer required sur les champs caution solidaire
        var requiredCautionIds = ['cs_nom', 'cs_prenom', 'cs_email'];
        requiredCautionIds.forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.required = (val === 'caution_solidaire');
        });
    }

    radios.forEach(function (r) { r.addEventListener('change', updateFields); });
    updateFields();
})();
</script>
<?php endif; ?>

</body>
</html>
