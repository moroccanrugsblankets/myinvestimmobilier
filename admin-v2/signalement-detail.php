<?php
/**
 * Détail et gestion d'un signalement d'anomalie — Interface admin
 * My Invest Immobilier
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/mail-templates.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: signalements.php');
    exit;
}

// Charger le signalement
// Use contrat_logement for frozen loyer/charges with fallback to logements
$stmt = $pdo->prepare("
    SELECT sig.*,
           COALESCE(cl.adresse, l.adresse) as adresse,
           COALESCE(cl.reference, l.reference) as logement_ref,
           COALESCE(cl.loyer, l.loyer) as loyer,
           COALESCE(cl.charges, l.charges) as charges,
           c.reference_unique as contrat_ref,
           CONCAT(loc.prenom, ' ', loc.nom) as locataire_nom,
           loc.prenom as locataire_prenom,
           loc.email as locataire_email, loc.telephone as locataire_telephone,
           loc.token_signalement
    FROM signalements sig
    INNER JOIN logements l ON sig.logement_id = l.id
    LEFT JOIN contrat_logement cl ON cl.contrat_id = sig.contrat_id
    INNER JOIN contrats c ON sig.contrat_id = c.id
    LEFT JOIN locataires loc ON sig.locataire_id = loc.id
    WHERE sig.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$sig = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sig) {
    header('Location: signalements.php');
    exit;
}

// Photos
$photos = $pdo->prepare("SELECT * FROM signalements_photos WHERE signalement_id = ? ORDER BY uploaded_at");
$photos->execute([$id]);
$photos = $photos->fetchAll(PDO::FETCH_ASSOC);

// Timeline
$actions = $pdo->prepare("SELECT * FROM signalements_actions WHERE signalement_id = ? ORDER BY created_at ASC");
$actions->execute([$id]);
$actions = $actions->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$successMsg = '';
$isClos = ($sig['statut'] === 'clos');

// Charger la liste des collaborateurs actifs (nécessaire avant le traitement du formulaire)
$serviceTechniqueMetier = 'service technique';
try {
    $collabListStmt = $pdo->query("SELECT id, nom, metier, email, telephone FROM collaborateurs WHERE actif = 1 ORDER BY nom ASC");
    $collabList = $collabListStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $collabList = []; // Table absente si migration non appliquée
}

// ── Traitement des formulaires ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token CSRF invalide. Veuillez recharger la page.';
    } else {
        $action = $_POST['action'];
        $adminName = $_SESSION['admin_nom'] ?? 'Administrateur';

        // ── Changement de statut ─────────────────────────────────────────────
        if ($action === 'change_statut' && !$isClos) {
            $newStatut = $_POST['statut'] ?? '';
            $validStatuts = ['nouveau', 'en_cours', 'pris_en_charge', 'sur_place', 'en_attente', 'reporte', 'resolu', 'clos'];
            if (!in_array($newStatut, $validStatuts)) {
                $errors[] = 'Statut invalide.';
            } else {
                $oldStatut = $sig['statut'];
                $extraFields = [];
                $extraParams = [];

                if ($newStatut === 'clos') {
                    $extraFields[] = 'date_cloture = NOW()';
                } elseif ($newStatut === 'resolu') {
                    $extraFields[] = 'date_resolution = NOW()';
                } elseif ($newStatut === 'en_cours' && empty($sig['date_intervention'])) {
                    $extraFields[] = 'date_intervention = NOW()';
                }

                $setClause = 'statut = ?' . (empty($extraFields) ? '' : ', ' . implode(', ', $extraFields));
                $pdo->prepare("UPDATE signalements SET $setClause, updated_at = NOW() WHERE id = ?")
                    ->execute(array_merge([$newStatut], $extraParams, [$id]));

                $pdo->prepare("
                    INSERT INTO signalements_actions (signalement_id, type_action, description, acteur, ancienne_valeur, nouvelle_valeur, ip_address)
                    VALUES (?, 'statut_change', ?, ?, ?, ?, ?)
                ")->execute([$id,
                    "Statut mis à jour : $oldStatut → $newStatut",
                    $adminName, $oldStatut, $newStatut,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                // Rechargement
                header("Location: signalement-detail.php?id=$id&success=statut");
                exit;
            }
        }

        // ── Changement de contrat ────────────────────────────────────────────
        if ($action === 'change_contrat' && !$isClos) {
            $newContratId = (int)($_POST['new_contrat_id'] ?? 0);
            if ($newContratId <= 0) {
                $errors[] = 'Veuillez sélectionner un contrat valide.';
            } else {
                // Récupérer les infos du nouveau contrat
                $stmtNewContrat = $pdo->prepare("
                    SELECT c.id, c._id, l.adresse, l.reference as _ref,
                           c.reference_unique as contrat_ref
                    FROM contrats c
                    INNER JOIN s l ON c._id = l.id
                    WHERE c.id = ? AND c.statut = 'valide'
                    LIMIT 1
                ");
                $stmtNewContrat->execute([$newContratId]);
                $newContrat = $stmtNewContrat->fetch(PDO::FETCH_ASSOC);

                if (!$newContrat) {
                    $errors[] = 'Contrat introuvable ou invalide.';
                } else {
                    $oldContratRef = $sig['contrat_ref'];
                    $pdo->prepare("
                        UPDATE signalements
                        SET contrat_id = ?, _id = ?, updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$newContratId, $newContrat['_id'], $id]);

                    $pdo->prepare("
                        INSERT INTO signalements_actions (signalement_id, type_action, description, acteur, ancienne_valeur, nouvelle_valeur, ip_address)
                        VALUES (?, 'contrat_change', ?, ?, ?, ?, ?)
                    ")->execute([$id,
                        "Contrat modifié : $oldContratRef → {$newContrat['contrat_ref']}",
                        $adminName, $oldContratRef, $newContrat['contrat_ref'],
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    ]);

                    header("Location: signalement-detail.php?id=$id&success=contrat");
                    exit;
                }
            }
        }


        if ($action === 'set_responsabilite' && !$isClos) {
            $responsabilite = $_POST['responsabilite'] ?? '';
            if (!in_array($responsabilite, ['locataire', 'proprietaire', 'non_determine'])) {
                $errors[] = 'Responsabilité invalide.';
            } else {
                $old = $sig['responsabilite'];

                // When locataire is responsible, do NOT auto-close: tenant must accept first
                $pdo->prepare("UPDATE signalements SET responsabilite = ?, responsabilite_confirmee_admin = 1, updated_at = NOW() WHERE id = ?")
                    ->execute([$responsabilite, $id]);

                $pdo->prepare("
                    INSERT INTO signalements_actions (signalement_id, type_action, description, acteur, ancienne_valeur, nouvelle_valeur, ip_address)
                    VALUES (?, 'responsabilite', ?, ?, ?, ?, ?)
                ")->execute([$id,
                    "Responsabilité définie : $old → $responsabilite",
                    $adminName, $old, $responsabilite,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                // Send email to tenant when responsibility is confirmed
                if ($responsabilite !== 'non_determine' && !empty($sig['locataire_email'])) {
                    $companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';
                    $siteUrl = rtrim($config['SITE_URL'] ?? '', '/');
                    $emailVars = [
                        'prenom'             => $sig['locataire_prenom'] ?? $sig['locataire_nom'],
                        'nom'                => $sig['locataire_nom'],
                        'reference'          => $sig['reference'],
                        'titre'              => $sig['titre'],
                        'adresse'            => $sig['adresse'],
                        'logement_reference' => $sig['logement_ref'] ?? '',
                        'company'            => $companyName,
                        'responsabilite'     => ['locataire' => 'Locataire', 'proprietaire' => 'Propriétaire', 'non_determine' => 'Non déterminée'][$responsabilite] ?? $responsabilite,
                    ];
                    $templateId = ($responsabilite === 'proprietaire')
                        ? 'confirmation_responsabilite_proprietaire'
                        : 'confirmation_responsabilite_locataire';

                    // For locataire responsibility: build acceptance link
                    if ($responsabilite === 'locataire') {
                        $tenantToken = $sig['token_signalement'] ?? '';
                        // Generate and persist token if missing
                        if (empty($tenantToken)) {
                            $tenantToken = bin2hex(random_bytes(32));
                            $pdo->prepare("UPDATE locataires SET token_signalement = ? WHERE id = ?")
                                ->execute([$tenantToken, $sig['locataire_id']]);
                        }
                        $lienAcceptation = $siteUrl . '/signalement/accepter-intervention.php?sig=' . $id . '&token=' . urlencode($tenantToken);
                        $emailVars['lien_acceptation'] = $lienAcceptation;
                    }

                    // Send to tenant with admin BCC
                    sendTemplatedEmail(
                        $templateId,
                        $sig['locataire_email'],
                        $emailVars,
                        null,
                        false,
                        true, // addAdminBcc
                        ['contexte' => 'responsabilite_' . $responsabilite . ';sig_id=' . $id]
                    );

                    // For proprietaire: notify service technique with 4 action buttons
                    if ($responsabilite === 'proprietaire') {
                        $stEmail = getServiceTechniqueEmail();
                        if ($stEmail) {
                            $stToken = getOrCreateServiceTechniqueToken($id);
                            $actionButtonsHtml = '';
                            if ($stToken) {
                                $siteUrlBase   = rtrim($config['SITE_URL'], '/');
                                $baseActionUrl = $siteUrlBase . '/signalement/collab-action.php?token=' . urlencode($stToken);
                                $termineUrl    = $siteUrlBase . '/signalement/intervention-terminee.php?token=' . urlencode($stToken);
                                $actionButtonsHtml = buildSignalementActionButtonsHtml($baseActionUrl, $termineUrl);
                            }
                            sendTemplatedEmail(
                                'responsabilite_proprietaire_service_technique',
                                $stEmail,
                                [
                                    'reference'           => $sig['reference'],
                                    'titre'               => $sig['titre'],
                                    'adresse'             => $sig['adresse'],
                                    'logement_reference'  => $sig['logement_ref'] ?? '',
                                    'locataire_nom'       => $sig['locataire_nom'] ?? '',
                                    'locataire_telephone' => $sig['locataire_telephone'] ?? '',
                                    'action_buttons_html' => $actionButtonsHtml,
                                ],
                                null,
                                false,
                                false,
                                ['contexte' => 'responsabilite_proprietaire_st;sig_id=' . $id]
                            );
                        }
                    }
                    // For locataire: service technique does not receive a notification at this stage
                }

                header("Location: signalement-detail.php?id=$id&success=responsabilite");
                exit;
            }
        }

        // ── Attribution à un/plusieurs collaborateur(s) ──────────────────────
        if ($action === 'attribuer' && !$isClos) {
            $modeNotif = $_POST['mode_notification'] ?? 'email';
            // Collecter les IDs sélectionnés (multi-sélection)
            $selectedCollabIds = array_filter(array_map('intval', (array)($_POST['collab_ids'] ?? [])));
            // Saisie manuelle (si pas de sélection dans la liste)
            $manualNom   = trim($_POST['collaborateur_nom'] ?? '');
            $manualEmail = trim($_POST['collaborateur_email'] ?? '');
            $manualTel   = trim($_POST['collaborateur_telephone'] ?? '');

            // Construire la liste des collaborateurs à attribuer
            $toAttribuer = [];
            if (!empty($selectedCollabIds) && !empty($collabList)) {
                $collabMap = array_column($collabList, null, 'id');
                foreach ($selectedCollabIds as $cid) {
                    if (isset($collabMap[$cid])) {
                        $cl = $collabMap[$cid];
                        $toAttribuer[] = [
                            'id'    => $cid,
                            'nom'   => $cl['nom'],
                            'email' => $cl['email'] ?? '',
                            'tel'   => $cl['telephone'] ?? '',
                        ];
                    }
                }
            } elseif (!empty($manualNom)) {
                $toAttribuer[] = ['id' => null, 'nom' => $manualNom, 'email' => $manualEmail, 'tel' => $manualTel];
            }

            if (empty($toAttribuer)) {
                $errors[] = 'Veuillez sélectionner au moins un collaborateur.';
            } else {
                $siteUrl = rtrim($config['SITE_URL'], '/');
                $signalementUrl = $siteUrl . '/admin-v2/signalement-detail.php?id=' . $id;
                $nomsList = implode(', ', array_column($toAttribuer, 'nom'));

                // Update signalements avec le 1er collaborateur (rétrocompatibilité)
                $primary = $toAttribuer[0];
                try {
                    $pdo->prepare("
                        UPDATE signalements
                        SET collaborateur_nom = ?, collaborateur_email = ?, collaborateur_telephone = ?,
                            mode_notification_collab = ?,
                            collaborateur_id = ?,
                            date_attribution = COALESCE(date_attribution, NOW()),
                            statut = CASE WHEN statut = 'nouveau' THEN 'en_cours' ELSE statut END,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$nomsList, $primary['email'], $primary['tel'], $modeNotif, $primary['id'], $id]);
                } catch (Exception $e) {
                    $pdo->prepare("
                        UPDATE signalements
                        SET collaborateur_nom = ?, collaborateur_email = ?, collaborateur_telephone = ?,
                            mode_notification_collab = ?,
                            date_attribution = COALESCE(date_attribution, NOW()),
                            statut = CASE WHEN statut = 'nouveau' THEN 'en_cours' ELSE statut END,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$nomsList, $primary['email'], $primary['tel'], $modeNotif, $id]);
                }

                // Insérer dans signalements_collaborateurs (si table disponible)
                foreach ($toAttribuer as $collab) {
                    try {
                        // Générer un token d'action unique pour ce collaborateur
                        $actionToken = bin2hex(random_bytes(32));
                        $pdo->prepare("
                            INSERT INTO signalements_collaborateurs
                                (signalement_id, collaborateur_id, collaborateur_nom, collaborateur_email,
                                 collaborateur_telephone, mode_notification, attribue_par, action_token)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                collaborateur_nom = VALUES(collaborateur_nom),
                                collaborateur_email = VALUES(collaborateur_email),
                                collaborateur_telephone = VALUES(collaborateur_telephone),
                                action_token = COALESCE(action_token, VALUES(action_token))
                        ")->execute([$id, $collab['id'], $collab['nom'], $collab['email'], $collab['tel'], $modeNotif, $adminName, $actionToken]);
                    } catch (Exception $e) {
                        // Table absente si migration 089/091 non appliquée — ignorer
                    }
                }

                $pdo->prepare("
                    INSERT INTO signalements_actions (signalement_id, type_action, description, acteur, nouvelle_valeur, ip_address)
                    VALUES (?, 'attribution', ?, ?, ?, ?)
                ")->execute([$id,
                    "Attribué à $nomsList (mode : $modeNotif)",
                    $adminName,
                    json_encode(array_map(function($c) use ($modeNotif) { return ['nom' => $c['nom'], 'email' => $c['email'], 'tel' => $c['tel'], 'mode' => $modeNotif]; }, $toAttribuer)),
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                // Notifier chaque collaborateur
                foreach ($toAttribuer as $collab) {
                    // Envoyer email si mode email ou les_deux
                    if (in_array($modeNotif, ['email', 'les_deux']) && !empty($collab['email'])) {
                        // Build photos/files HTML (linked by public URL — collaborators have no admin access)
                        $photosHtml = '';
                        if (!empty($photos)) {
                            $siteUrlBase = rtrim($config['SITE_URL'], '/');
                            $photosHtml = '<div style="background: #f8f9fa; padding: 15px; margin: 20px 0; border-radius: 5px;">'
                                . '<h3 style="margin: 0 0 10px; color: #333; font-size: 16px;">📎 Fichiers joints (' . count($photos) . ')</h3>'
                                . '<ul style="margin: 0; padding-left: 20px;">';
                            foreach ($photos as $photo) {
                                $photoUrl = $siteUrlBase . '/uploads/signalements/' . rawurlencode($photo['filename']);
                                $isVideo  = strpos($photo['mime_type'] ?? '', 'video/') === 0;
                                $icon     = $isVideo ? '🎬' : '📷';
                                $photosHtml .= '<li style="margin: 4px 0;">'
                                    . $icon . ' <a href="' . htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank">'
                                    . htmlspecialchars($photo['original_name'], ENT_QUOTES, 'UTF-8')
                                    . '</a></li>';
                            }
                            $photosHtml .= '</ul></div>';
                        }

                        // Récupérer le token d'action du collaborateur pour les boutons email
                        $collabToken = '';
                        try {
                            $tokenStmt = $pdo->prepare("SELECT action_token FROM signalements_collaborateurs WHERE signalement_id = ? AND collaborateur_email = ? LIMIT 1");
                            $tokenStmt->execute([$id, $collab['email']]);
                            $collabToken = $tokenStmt->fetchColumn() ?: '';
                        } catch (Exception $e) {}

                        // Construire les boutons d'action
                        $actionButtonsHtml = '';
                        if (!empty($collabToken)) {
                            $siteUrlBase = rtrim($config['SITE_URL'], '/');
                            $baseActionUrl = $siteUrlBase . '/signalement/collab-action.php?token=' . urlencode($collabToken);
                            $termineUrl    = $siteUrlBase . '/signalement/intervention-terminee.php?token=' . urlencode($collabToken);
                            $actionButtonsHtml = '<div style="margin: 25px 0; text-align: center;">'
                                . '<p style="font-weight: bold; margin-bottom: 15px;">Actions rapides :</p>'
                                . '<div style="display: flex; flex-wrap: wrap; gap: 10px; justify-content: center;">'
                                . '<a href="' . $baseActionUrl . '&amp;action=pris_en_charge" style="display:inline-block;background:#3498db;color:white;padding:12px 18px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:14px;">🔵 Pris en charge</a>'
                                . '<a href="' . $baseActionUrl . '&amp;action=sur_place" style="display:inline-block;background:#e67e22;color:white;padding:12px 18px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:14px;">🟠 Sur place</a>'
                                . '<a href="' . $termineUrl . '" style="display:inline-block;background:#27ae60;color:white;padding:12px 18px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:14px;">🟢 Intervention terminée</a>'
                                . '<a href="' . $baseActionUrl . '&amp;action=impossible" style="display:inline-block;background:#e74c3c;color:white;padding:12px 18px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:14px;">🔴 Impossible / Report</a>'
                                . '</div></div>';
                        }

                        $emailVars = [
                            'collab_nom'          => $collab['nom'],
                            'reference'           => $sig['reference'],
                            'titre'               => $sig['titre'],
                            'priorite'            => ucfirst($sig['priorite']),
                            'adresse'             => $sig['adresse'],
                            'logement_reference'  => $sig['logement_ref'] ?? '',
                            'locataire_nom'       => $sig['locataire_nom'] ?? '',
                            'locataire_telephone' => $sig['locataire_telephone'] ?? '',
                            'locataire_email'     => $sig['locataire_email'] ?? '',
                            'description'         => $sig['description'],
                            'date_signalement'    => !empty($sig['date_signalement'])
                                ? date('d/m/Y à H:i', strtotime($sig['date_signalement']))
                                : '',
                            'company'             => $config['COMPANY_NAME'] ?? 'My Invest Immobilier',
                            'photos_html'         => $photosHtml,
                            'action_buttons_html' => $actionButtonsHtml,
                        ];

                        $sent = sendTemplatedEmail(
                            'signalement_attribution',
                            $collab['email'],
                            $emailVars,
                            null,
                            false,
                            true, // addAdminBcc — les admins reçoivent une copie invisible
                            ['contexte' => "signalement_attribution;sig_id=$id"]
                        );
                        if (!$sent) {
                            $errors[] = 'Avertissement : l\'email à ' . htmlspecialchars($collab['nom']) . ' n\'a pas pu être envoyé.';
                        }
                    }

                    // Envoyer WhatsApp via Twilio si mode whatsapp ou les_deux
                    if (in_array($modeNotif, ['whatsapp', 'les_deux']) && !empty($collab['tel'])) {
                        $twilioSid    = getParameter('twilio_account_sid', '');
                        $twilioToken  = getParameter('twilio_auth_token', '');
                        $twilioFrom   = getParameter('twilio_whatsapp_from', '');
                        if (!empty($twilioSid) && !empty($twilioToken) && !empty($twilioFrom)) {
                            $waMessage = "[Signalement {$sig['priorite']}] {$sig['titre']}\n"
                                . "Adresse : {$sig['adresse']}\n"
                                . "Priorité : {$sig['priorite']}\n"
                                . "Description : " . mb_substr($sig['description'], 0, 200) . "...\n"
                                . "Lien mission : $signalementUrl";
                            $toNum = preg_replace('/\s+/', '', $collab['tel']);
                            if (substr($toNum, 0, 1) !== '+') {
                                $toNum = '+' . $toNum;
                            }
                            $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/$twilioSid/Messages.json");
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_USERPWD, "$twilioSid:$twilioToken");
                            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                                'From' => 'whatsapp:' . $twilioFrom,
                                'To'   => 'whatsapp:' . $toNum,
                                'Body' => $waMessage,
                            ]));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                            $waResponse = curl_exec($ch);
                            $waHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            if ($waHttpCode < 200 || $waHttpCode >= 300) {
                                error_log("Twilio WhatsApp error: HTTP $waHttpCode — $waResponse");
                                $errors[] = "Avertissement : le message WhatsApp à " . htmlspecialchars($collab['nom']) . " n'a pas pu être envoyé (HTTP $waHttpCode).";
                            }
                        }
                    }
                }

                if (empty($errors)) {
                    header("Location: signalement-detail.php?id=$id&success=attribution");
                    exit;
                }
            }
        }

        // ── Ajout de complément (uniquement si clos) ─────────────────────────
        if ($action === 'add_complement' && $isClos) {
            $complement = trim($_POST['complement'] ?? '');
            if (empty($complement)) {
                $errors[] = 'Le complément ne peut pas être vide.';
            } else {
                $pdo->prepare("UPDATE signalements SET complement = CONCAT(COALESCE(complement,''), ?), updated_at = NOW() WHERE id = ?")
                    ->execute(["\n\n[" . date('d/m/Y H:i') . " — $adminName] " . $complement, $id]);

                $pdo->prepare("
                    INSERT INTO signalements_actions (signalement_id, type_action, description, acteur, nouvelle_valeur, ip_address)
                    VALUES (?, 'complement', 'Complément ajouté au dossier clos', ?, ?, ?)
                ")->execute([$id, $adminName, $complement, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);

                header("Location: signalement-detail.php?id=$id&success=complement");
                exit;
            }
        }
    }
}

// Recharger après modifications
$stmt->execute([$id]);
$sig = $stmt->fetch(PDO::FETCH_ASSOC);
$isClos = ($sig['statut'] === 'clos');

// Recharger timeline
$actionsStmt = $pdo->prepare("SELECT * FROM signalements_actions WHERE signalement_id = ? ORDER BY created_at ASC");
$actionsStmt->execute([$id]);
$actions = $actionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Charger la liste des contrats actifs pour le changement de contrat
// Use contrat_logement for frozen adresse/reference with fallback to logements
$contratsListStmt = $pdo->query("
    SELECT c.id, c.reference_unique,
           COALESCE(cl.adresse, l.adresse) as adresse,
           COALESCE(cl.reference, l.reference) as _ref,
           (SELECT GROUP_CONCAT(CONCAT(prenom, ' ', nom) SEPARATOR ', ')
            FROM locataires WHERE contrat_id = c.id) as locataires
    FROM contrats c
    INNER JOIN logements l ON c.logement_id = l.id
    LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
    WHERE c.statut = 'valide'
    ORDER BY COALESCE(cl.adresse, l.adresse)
");
$contratsList = $contratsListStmt->fetchAll(PDO::FETCH_ASSOC);

// Charger la liste des collaborateurs attribués (table multi-collaborateurs)
$assignedCollabs = [];
try {
    $stmtAC = $pdo->prepare("
        SELECT sc.*, c.metier
        FROM signalements_collaborateurs sc
        LEFT JOIN collaborateurs c ON sc.collaborateur_id = c.id
        WHERE sc.signalement_id = ?
        ORDER BY sc.attribue_le ASC
    ");
    $stmtAC->execute([$id]);
    $assignedCollabs = $stmtAC->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table absente si migration 089 non appliquée
}

$statutLabels = [
    'nouveau'         => ['label' => 'Nouveau',         'class' => 'bg-primary'],
    'en_cours'        => ['label' => 'En cours',        'class' => 'bg-warning text-dark'],
    'pris_en_charge'  => ['label' => 'Pris en charge',  'class' => 'bg-info text-dark'],
    'sur_place'       => ['label' => 'Sur place',       'class' => 'bg-warning text-dark'],
    'en_attente'      => ['label' => 'En attente',      'class' => 'bg-info text-dark'],
    'reporte'         => ['label' => 'Reporté',         'class' => 'bg-danger'],
    'resolu'          => ['label' => 'Résolu',          'class' => 'bg-success'],
    'clos'            => ['label' => 'Clos',            'class' => 'bg-secondary'],
];

$actionIcons = [
    'creation'           => 'bi-flag-fill text-primary',
    'statut_change'      => 'bi-arrow-repeat text-warning',
    'attribution'        => 'bi-person-check text-info',
    'responsabilite'     => 'bi-shield-check text-success',
    'complement'         => 'bi-chat-text text-secondary',
    'cloture'            => 'bi-lock-fill text-secondary',
    'contrat_change'     => 'bi-file-earmark-text text-primary',
    'collab_pris_en_charge' => 'bi-check-circle text-info',
    'collab_sur_place'   => 'bi-geo-alt text-warning',
    'collab_termine'     => 'bi-check-circle-fill text-success',
    'collab_impossible'  => 'bi-x-circle text-danger',
];

// Charger le décompte associé à ce signalement (s'il existe)
$decompte = null;
try {
    $decStmt = $pdo->prepare("SELECT id, reference, statut, montant_total, date_creation FROM signalements_decomptes WHERE signalement_id = ? LIMIT 1");
    $decStmt->execute([$id]);
    $decompte = $decStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table absente si migration non appliquée
}

$csrfToken = generateCsrfToken();

$successParam = $_GET['success'] ?? '';
if ($successParam) {
    $successMessages = [
        'statut'         => 'Statut mis à jour avec succès.',
        'responsabilite' => 'Responsabilité confirmée avec succès.',
        'attribution'    => 'Signalement attribué avec succès.',
        'complement'     => 'Complément ajouté avec succès.',
        'contrat'        => 'Contrat mis à jour avec succès.',
    ];
    $successMsg = $successMessages[$successParam] ?? 'Modification enregistrée.';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signalement <?php echo htmlspecialchars($sig['reference']); ?> — Admin My Invest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .section-card { background: #fff; border-radius: 10px; padding: 22px; margin-bottom: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.07); }
        .timeline { position: relative; padding-left: 30px; }
        .timeline::before { content: ''; position: absolute; left: 10px; top: 0; bottom: 0; width: 2px; background: #dee2e6; }
        .timeline-item { position: relative; margin-bottom: 18px; }
        .timeline-item::before { content: ''; position: absolute; left: -24px; top: 5px; width: 12px; height: 12px; border-radius: 50%; background: #6c757d; border: 2px solid #fff; box-shadow: 0 0 0 2px #dee2e6; }
        .timeline-item.action-creation::before { background: #0d6efd; }
        .timeline-item.action-attribution::before { background: #0dcaf0; }
        .timeline-item.action-statut_change::before { background: #ffc107; }
        .timeline-item.action-responsabilite::before { background: #198754; }
        .timeline-item.action-cloture::before { background: #6c757d; }
        .photo-thumb { width: 120px; height: 90px; object-fit: cover; border-radius: 6px; border: 1px solid #dee2e6; cursor: pointer; transition: transform 0.15s; }
        .photo-thumb:hover, .photo-thumb:focus { transform: scale(1.05); outline: 2px solid #0d6efd; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="container-fluid mt-4">

        <!-- En-tête -->
        <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                    <h1 class="mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($sig['titre']); ?>
                    </h1>
                    <?php if ($sig['priorite'] === 'urgent'): ?>
                        <span class="badge bg-danger fs-6"><i class="bi bi-lightning-fill me-1"></i>Urgent</span>
                    <?php endif; ?>
                    <span class="badge <?php echo $statutLabels[$sig['statut']]['class']; ?> fs-6">
                        <?php echo $statutLabels[$sig['statut']]['label']; ?>
                    </span>
                </div>
                <p class="text-muted mb-0">
                    <span class="font-monospace"><?php echo htmlspecialchars($sig['reference']); ?></span>
                    &nbsp;—&nbsp;<?php echo htmlspecialchars($sig['adresse']); ?>
                    &nbsp;—&nbsp;signalé le <?php echo date('d/m/Y à H:i', strtotime($sig['date_signalement'])); ?>
                </p>
            </div>
            <a href="signalements.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Retour
            </a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $e): ?>
                    <div><i class="bi bi-exclamation-circle me-1"></i><?php echo htmlspecialchars($e); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($successMsg): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($successMsg); ?>
            </div>
        <?php endif; ?>
        <?php if ($isClos): ?>
            <div class="alert alert-secondary">
                <i class="bi bi-lock-fill me-2"></i>
                Ce signalement est <strong>clos</strong>. Il n'est plus modifiable. Seul un complément peut être ajouté.
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Colonne gauche : détails + gestion -->
            <div class="col-lg-8">

                <!-- Informations générales -->
                <div class="section-card">
                    <h5 class="mb-3"><i class="bi bi-info-circle me-2"></i>Détails du signalement</h5>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Référence</dt>
                        <dd class="col-sm-8">
                            <span class="font-monospace fw-bold fs-5 text-primary"><?php echo htmlspecialchars($sig['reference']); ?></span>
                        </dd>

                        <dt class="col-sm-4">Locataire</dt>
                        <dd class="col-sm-8">
                            <?php echo htmlspecialchars($sig['locataire_nom'] ?? '—'); ?>
                            <?php if (!empty($sig['locataire_email'])): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($sig['locataire_email']); ?></small>
                            <?php endif; ?>
                            <?php if (!empty($sig['locataire_telephone'])): ?>
                                <br><small class="text-muted"><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($sig['locataire_telephone']); ?></small>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-4">Logement</dt>
                        <dd class="col-sm-8">
                            <?php if (!empty($sig['logement_ref'])): ?>
                                <?php echo htmlspecialchars($sig['logement_ref']); ?>
                            <?php endif; ?>
                            <br><small class="text-muted font-monospace"><?php echo htmlspecialchars($sig['adresse']); ?></small>
                        </dd>

                        <dt class="col-sm-4">Contrat</dt>
                        <dd class="col-sm-8">
                            <a href="contrat-detail.php?id=<?php echo $sig['contrat_id']; ?>">
                                <?php echo htmlspecialchars($sig['contrat_ref']); ?>
                            </a>
                        </dd>

                        <dt class="col-sm-4">Description</dt>
                        <dd class="col-sm-8">
                            <div class="p-3 bg-light rounded" style="white-space:pre-wrap;"><?php
                                echo htmlspecialchars($sig['description']);
                            ?></div>
                        </dd>

                        <?php if (!empty($sig['disponibilites'])): ?>
                        <dt class="col-sm-4">Disponibilités</dt>
                        <dd class="col-sm-8">
                            <div class="p-2 bg-light rounded small" style="white-space:pre-wrap;"><?php echo htmlspecialchars($sig['disponibilites']); ?></div>
                        </dd>
                        <?php endif; ?>

                        <?php if (!empty($sig['nb_heures']) || !empty($sig['cout_materiaux']) || !empty($sig['notes_intervention'])): ?>
                        <dt class="col-sm-4">Intervention</dt>
                        <dd class="col-sm-8">
                            <div class="p-2 bg-light rounded small">
                                <?php if (!empty($sig['nb_heures'])): ?>
                                    <div><i class="bi bi-clock me-1 text-muted"></i><strong>Heures :</strong> <?php echo number_format((float)$sig['nb_heures'], 2, ',', ' '); ?> h</div>
                                <?php endif; ?>
                                <?php if (!empty($sig['cout_materiaux'])): ?>
                                    <div><i class="bi bi-currency-euro me-1 text-muted"></i><strong>Matériaux :</strong> <?php echo number_format((float)$sig['cout_materiaux'], 2, ',', ' '); ?> €</div>
                                <?php endif; ?>
                                <?php if (!empty($sig['notes_intervention'])): ?>
                                    <div class="mt-1" style="white-space:pre-wrap;"><?php echo htmlspecialchars($sig['notes_intervention']); ?></div>
                                <?php endif; ?>
                            </div>
                        </dd>
                        <?php endif; ?>

                        <dt class="col-sm-4">Responsabilité</dt>
                        <dd class="col-sm-8">
                            <?php
                            $respLabels = ['locataire' => 'Locataire', 'proprietaire' => 'Propriétaire', 'non_determine' => 'Non déterminée'];
                            $respClasses = ['locataire' => 'bg-danger', 'proprietaire' => 'bg-success', 'non_determine' => 'bg-secondary'];
                            ?>
                            <span class="badge <?php echo $respClasses[$sig['responsabilite']] ?? 'bg-secondary'; ?>">
                                <?php echo $respLabels[$sig['responsabilite']] ?? $sig['responsabilite']; ?>
                            </span>
                            <?php if ($sig['responsabilite_confirmee_admin']): ?>
                                <small class="text-success ms-2"><i class="bi bi-check-circle"></i> Confirmé par l'admin</small>
                            <?php endif; ?>
                            <?php if ($sig['checklist_confirmee']): ?>
                                <br><small class="text-muted"><i class="bi bi-clipboard-check me-1"></i>Checklist confirmée par le locataire</small>
                            <?php endif; ?>
                        </dd>

                        <?php if (!empty($assignedCollabs)): ?>
                        <dt class="col-sm-4">Collaborateur(s)</dt>
                        <dd class="col-sm-8">
                            <?php foreach ($assignedCollabs as $ac): ?>
                            <div class="mb-1">
                                <strong><?php echo htmlspecialchars($ac['collaborateur_nom']); ?></strong>
                                <?php if (!empty($ac['metier'])): ?>
                                    <small class="text-muted">(<?php echo htmlspecialchars($ac['metier']); ?>)</small>
                                <?php endif; ?>
                                <?php if (!empty($ac['collaborateur_email'])): ?>
                                    — <a href="mailto:<?php echo htmlspecialchars($ac['collaborateur_email']); ?>" class="small">
                                        <?php echo htmlspecialchars($ac['collaborateur_email']); ?>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($ac['collaborateur_telephone'])): ?>
                                    — <span class="small"><?php echo htmlspecialchars($ac['collaborateur_telephone']); ?></span>
                                <?php endif; ?>
                                <small class="text-muted d-block">Attribué le <?php echo date('d/m/Y', strtotime($ac['attribue_le'])); ?>
                                    <?php if (!empty($ac['attribue_par'])): ?> par <?php echo htmlspecialchars($ac['attribue_par']); ?><?php endif; ?></small>
                            </div>
                            <?php endforeach; ?>
                        </dd>
                        <?php elseif ($sig['collaborateur_nom']): ?>
                        <dt class="col-sm-4">Collaborateur</dt>
                        <dd class="col-sm-8">
                            <?php echo htmlspecialchars($sig['collaborateur_nom']); ?>
                            <?php if (!empty($sig['collaborateur_email'])): ?>
                                — <a href="mailto:<?php echo htmlspecialchars($sig['collaborateur_email']); ?>">
                                    <?php echo htmlspecialchars($sig['collaborateur_email']); ?>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($sig['collaborateur_telephone'])): ?>
                                — <?php echo htmlspecialchars($sig['collaborateur_telephone']); ?>
                            <?php endif; ?>
                        </dd>
                        <?php endif; ?>

                        <?php if ($sig['complement']): ?>
                        <dt class="col-sm-4">Complément</dt>
                        <dd class="col-sm-8">
                            <div class="p-3 bg-light rounded" style="white-space:pre-wrap;"><?php echo htmlspecialchars($sig['complement']); ?></div>
                        </dd>
                        <?php endif; ?>
                    </dl>
                </div>

                <!-- Rapport d'intervention du collaborateur -->
                <?php
                $apresPhotos = array_values(array_filter($photos, function($p) {
                    return ($p['photo_type'] ?? 'signalement') === 'apres_travaux';
                }));
                $hasRapport = !empty($sig['notes_intervention']) || !empty($apresPhotos);
                ?>
                <?php if ($hasRapport || in_array($sig['statut'], ['resolu', 'clos'])): ?>
                <div class="section-card border border-success">
                    <h5 class="mb-3 text-success"><i class="bi bi-clipboard-check me-2"></i>Rapport d'intervention du collaborateur</h5>
                    <?php if (!empty($sig['notes_intervention'])): ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted"><i class="bi bi-chat-left-text me-1"></i>Notes d'intervention</label>
                        <div class="p-3 bg-light rounded" style="white-space:pre-wrap;"><?php echo htmlspecialchars($sig['notes_intervention']); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($apresPhotos)): ?>
                    <div class="mb-2">
                        <label class="form-label fw-semibold small text-muted"><i class="bi bi-camera-fill me-1"></i>Photos après travaux (<?php echo count($apresPhotos); ?>)</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($apresPhotos as $idx => $photo): ?>
                            <?php
                            $mediaUrl = rtrim($config['SITE_URL'], '/') . '/uploads/signalements/' . urlencode($photo['filename']);
                            $isVideo  = strpos($photo['mime_type'] ?? '', 'video/') === 0;
                            $modalId  = 'rapport-modal-' . ($photo['id'] ?? $idx);
                            ?>
                            <?php if ($isVideo): ?>
                            <div class="position-relative" style="width:120px;height:90px;cursor:pointer;"
                                 data-bs-toggle="modal" data-bs-target="#<?php echo $modalId; ?>"
                                 title="<?php echo htmlspecialchars($photo['original_name']); ?>">
                                <div class="d-flex align-items-center justify-content-center bg-dark rounded h-100 w-100"
                                     style="border:1px solid #dee2e6;">
                                    <i class="bi bi-play-circle-fill text-white" style="font-size:2.5rem;"></i>
                                </div>
                                <small class="position-absolute bottom-0 start-0 end-0 text-center text-white bg-dark bg-opacity-75 rounded-bottom"
                                       style="font-size:10px;padding:2px;"><?php echo htmlspecialchars($photo['original_name']); ?></small>
                            </div>
                            <?php else: ?>
                            <img src="<?php echo htmlspecialchars($mediaUrl); ?>"
                                 alt="<?php echo htmlspecialchars($photo['original_name']); ?>"
                                 class="photo-thumb"
                                 data-bs-toggle="modal" data-bs-target="#<?php echo $modalId; ?>"
                                 title="<?php echo htmlspecialchars($photo['original_name']); ?>">
                            <?php endif; ?>
                            <div class="modal fade" id="<?php echo $modalId; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header py-2">
                                            <span class="modal-title small text-truncate"><?php echo htmlspecialchars($photo['original_name']); ?></span>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body text-center p-2">
                                            <?php if ($isVideo): ?>
                                            <video controls class="w-100" style="max-height:70vh;">
                                                <source src="<?php echo htmlspecialchars($mediaUrl); ?>" type="<?php echo htmlspecialchars($photo['mime_type']); ?>">
                                            </video>
                                            <?php else: ?>
                                            <img src="<?php echo htmlspecialchars($mediaUrl); ?>"
                                                 alt="<?php echo htmlspecialchars($photo['original_name']); ?>"
                                                 class="img-fluid" style="max-height:70vh;">
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer py-2">
                                            <a href="<?php echo htmlspecialchars($mediaUrl); ?>" class="btn btn-sm btn-outline-secondary" download>
                                                <i class="bi bi-download me-1"></i>Télécharger
                                            </a>
                                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Fermer</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!$hasRapport): ?>
                    <p class="text-muted small mb-0"><i class="bi bi-hourglass me-1"></i>Le rapport du collaborateur n'a pas encore été soumis.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Décompte d'intervention -->
                <div class="section-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Décompte d'intervention</h5>
                        <?php if ($decompte): ?>
                            <?php
                            $decStatutLabels = ['brouillon' => ['Brouillon', 'secondary'], 'valide' => ['Validé', 'success'], 'facture_envoyee' => ['Facture envoyée', 'primary']];
                            $dsl = $decStatutLabels[$decompte['statut']] ?? [$decompte['statut'], 'secondary'];
                            ?>
                            <span class="badge bg-<?php echo $dsl[1]; ?>"><?php echo $dsl[0]; ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($decompte): ?>
                        <p class="mb-2 small text-muted">
                            Référence : <strong class="font-monospace"><?php echo htmlspecialchars($decompte['reference']); ?></strong>
                            — Montant : <strong><?php echo number_format((float)$decompte['montant_total'], 2, ',', ' '); ?> €</strong>
                            — Créé le <?php echo date('d/m/Y', strtotime($decompte['date_creation'])); ?>
                        </p>
                        <a href="decompte-detail.php?id=<?php echo $decompte['id']; ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-pencil me-1"></i>Ouvrir le décompte
                        </a>
                    <?php else: ?>
                        <p class="text-muted small mb-3">Aucun décompte créé pour ce signalement.</p>
                        <a href="decompte-detail.php?sig=<?php echo $id; ?>" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-plus-circle me-1"></i>Créer un décompte
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Photos / Vidéos -->
                <?php if (!empty($photos)): ?>
                <?php
                // Group photos by type
                $photosByType = [];
                foreach ($photos as $p) {
                    $pt = $p['photo_type'] ?? 'signalement';
                    $photosByType[$pt][] = $p;
                }
                $photoTypeLabels = [
                    'signalement'  => ['Signalement initial', 'bi-camera'],
                    'avant_travaux'=> ['Avant travaux', 'bi-camera-fill'],
                    'apres_travaux'=> ['Après travaux', 'bi-camera-fill text-success'],
                ];
                ?>
                <div class="section-card">
                    <h5 class="mb-3"><i class="bi bi-camera me-2"></i>Photos / Vidéos (<?php echo count($photos); ?>)</h5>
                    <?php foreach ($photosByType as $ptKey => $ptPhotos): ?>
                    <?php if (count($photosByType) > 1): ?>
                    <h6 class="text-muted small mb-2">
                        <i class="<?php echo $photoTypeLabels[$ptKey][1] ?? 'bi-camera'; ?> me-1"></i>
                        <?php echo $photoTypeLabels[$ptKey][0] ?? $ptKey; ?>
                        (<?php echo count($ptPhotos); ?>)
                    </h6>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?php foreach ($ptPhotos as $idx => $photo): ?>
                            <?php
                            $pIdx      = $photo['id'] ?? $ptKey . $idx;
                            $mediaUrl  = rtrim($config['SITE_URL'], '/') . '/uploads/signalements/' . urlencode($photo['filename']);
                            $isVideo   = strpos($photo['mime_type'] ?? '', 'video/') === 0;
                            $modalId   = 'media-modal-' . $pIdx;
                            ?>
                            <?php if ($isVideo): ?>
                            <!-- Video thumbnail -->
                            <div class="position-relative" style="width:120px;height:90px;cursor:pointer;"
                                 data-bs-toggle="modal" data-bs-target="#<?php echo $modalId; ?>"
                                 title="<?php echo htmlspecialchars($photo['original_name']); ?>">
                                <div class="d-flex align-items-center justify-content-center bg-dark rounded h-100 w-100"
                                     style="border:1px solid #dee2e6;">
                                    <i class="bi bi-play-circle-fill text-white" style="font-size:2.5rem;"></i>
                                </div>
                                <small class="position-absolute bottom-0 start-0 end-0 text-center text-white bg-dark bg-opacity-75 rounded-bottom"
                                       style="font-size:10px;padding:2px;"><?php echo htmlspecialchars($photo['original_name']); ?></small>
                            </div>
                            <?php else: ?>
                            <!-- Image thumbnail -->
                            <img src="<?php echo htmlspecialchars($mediaUrl); ?>"
                                 alt="<?php echo htmlspecialchars($photo['original_name']); ?>"
                                 class="photo-thumb"
                                 data-bs-toggle="modal" data-bs-target="#<?php echo $modalId; ?>"
                                 title="<?php echo htmlspecialchars($photo['original_name']); ?>">
                            <?php endif; ?>

                            <!-- Modal for this media -->
                            <div class="modal fade" id="<?php echo $modalId; ?>" tabindex="-1"
                                 aria-label="<?php echo htmlspecialchars($photo['original_name']); ?>">
                                <div class="modal-dialog modal-lg modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header py-2">
                                            <span class="modal-title small text-truncate"><?php echo htmlspecialchars($photo['original_name']); ?></span>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body text-center p-2">
                                            <?php if ($isVideo): ?>
                                            <video controls class="w-100" style="max-height:70vh;">
                                                <source src="<?php echo htmlspecialchars($mediaUrl); ?>" type="<?php echo htmlspecialchars($photo['mime_type']); ?>">
                                                Votre navigateur ne supporte pas la lecture vidéo.
                                            </video>
                                            <?php else: ?>
                                            <img src="<?php echo htmlspecialchars($mediaUrl); ?>"
                                                 alt="<?php echo htmlspecialchars($photo['original_name']); ?>"
                                                 class="img-fluid" style="max-height:70vh;">
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer py-2">
                                            <a href="<?php echo htmlspecialchars($mediaUrl); ?>" class="btn btn-sm btn-outline-secondary" download>
                                                <i class="bi bi-download me-1"></i>Télécharger
                                            </a>
                                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Fermer</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; // photosByType ?>
                </div>
                <?php endif; ?>

                <!-- Timeline -->
                <div class="section-card">
                    <h5 class="mb-3"><i class="bi bi-clock-history me-2"></i>Timeline</h5>
                    <?php if (empty($actions)): ?>
                        <p class="text-muted">Aucune action enregistrée.</p>
                    <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($actions as $action): ?>
                        <div class="timeline-item action-<?php echo htmlspecialchars($action['type_action']); ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <i class="bi <?php echo $actionIcons[$action['type_action']] ?? 'bi-dot text-secondary'; ?> me-2"></i>
                                    <strong><?php echo htmlspecialchars($action['description']); ?></strong>
                                    <?php if (!empty($action['acteur'])): ?>
                                        <small class="text-muted ms-2">par <?php echo htmlspecialchars($action['acteur']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted text-nowrap ms-3">
                                    <?php echo date('d/m/Y H:i', strtotime($action['created_at'])); ?>
                                </small>
                            </div>
                            <?php if (!empty($action['ancienne_valeur']) || !empty($action['nouvelle_valeur'])): ?>
                                <div class="small text-muted mt-1 ms-4">
                                    <?php if (!empty($action['ancienne_valeur'])): ?>
                                        <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($action['ancienne_valeur']); ?></span>
                                        <i class="bi bi-arrow-right mx-1"></i>
                                    <?php endif; ?>
                                    <?php if (!empty($action['nouvelle_valeur'])): ?>
                                        <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($action['nouvelle_valeur']); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Complément post-clôture -->
                <?php if ($isClos): ?>
                <div class="section-card border border-secondary">
                    <h5 class="mb-3"><i class="bi bi-chat-text me-2"></i>Ajouter un complément</h5>
                    <p class="text-muted small">Le dossier est clos mais vous pouvez ajouter un complément d'information.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="add_complement">
                        <div class="mb-3">
                            <textarea class="form-control" name="complement" rows="3" required
                                      placeholder="Complément d'information..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-secondary">
                            <i class="bi bi-plus-circle me-1"></i>Ajouter le complément
                        </button>
                    </form>
                </div>
                <?php endif; ?>

            </div>

            <!-- Colonne droite : actions admin -->
            <div class="col-lg-4">

                <!-- Dates clés (timeline rapide) -->
                <div class="section-card">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-calendar3 me-2"></i>Dates clés</h6>
                    <ul class="list-unstyled mb-0 small">
                        <li class="mb-2">
                            <i class="bi bi-flag text-primary me-2"></i>
                            <strong>Signalement :</strong><br>
                            <span class="ms-4"><?php echo date('d/m/Y H:i', strtotime($sig['date_signalement'])); ?></span>
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-person-check text-info me-2"></i>
                            <strong>Attribution :</strong><br>
                            <span class="ms-4"><?php echo $sig['date_attribution'] ? date('d/m/Y H:i', strtotime($sig['date_attribution'])) : '—'; ?></span>
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-tools text-warning me-2"></i>
                            <strong>Intervention :</strong><br>
                            <span class="ms-4"><?php echo $sig['date_intervention'] ? date('d/m/Y H:i', strtotime($sig['date_intervention'])) : '—'; ?></span>
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-check-circle text-success me-2"></i>
                            <strong>Résolution :</strong><br>
                            <span class="ms-4"><?php echo $sig['date_resolution'] ? date('d/m/Y H:i', strtotime($sig['date_resolution'])) : '—'; ?></span>
                        </li>
                        <li>
                            <i class="bi bi-lock text-secondary me-2"></i>
                            <strong>Clôture :</strong><br>
                            <span class="ms-4"><?php echo $sig['date_cloture'] ? date('d/m/Y H:i', strtotime($sig['date_cloture'])) : '—'; ?></span>
                        </li>
                    </ul>
                </div>

                <?php if (!$isClos): ?>

                <!-- Changer le statut -->
                <div class="section-card">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-arrow-repeat me-2"></i>Changer le statut</h6>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="change_statut">
                        <div class="mb-2">
                            <select class="form-select" name="statut">
                                <?php foreach ($statutLabels as $v => $l): ?>
                                    <option value="<?php echo $v; ?>" <?php echo $sig['statut'] === $v ? 'selected' : ''; ?>>
                                        <?php echo $l['label']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                            <i class="bi bi-check me-1"></i>Mettre à jour le statut
                        </button>
                    </form>
                </div>

                <!-- Responsabilité -->
                <div class="section-card">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-shield-check me-2"></i>Confirmer la responsabilité</h6>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="set_responsabilite">
                        <div class="mb-2">
                            <select class="form-select" name="responsabilite">
                                <option value="non_determine" <?php echo $sig['responsabilite'] === 'non_determine' ? 'selected' : ''; ?>>Non déterminée</option>
                                <option value="locataire" <?php echo $sig['responsabilite'] === 'locataire' ? 'selected' : ''; ?>>Locataire</option>
                                <option value="proprietaire" <?php echo $sig['responsabilite'] === 'proprietaire' ? 'selected' : ''; ?>>Propriétaire</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-outline-success btn-sm w-100">
                            <i class="bi bi-check me-1"></i>Confirmer la responsabilité
                        </button>
                    </form>
                </div>

                <!-- Modifier le contrat attribué -->
                <div class="section-card">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-file-earmark-text me-2"></i>Modifier le contrat attribué</h6>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="change_contrat">
                        <div class="mb-2">
                            <select class="form-select form-select-sm" name="new_contrat_id" required>
                                <option value="">— Sélectionner un contrat —</option>
                                <?php foreach ($contratsList as $ct): ?>
                                <option value="<?php echo $ct['id']; ?>" <?php echo $sig['contrat_id'] == $ct['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ct['reference_unique']); ?>
                                    — <?php echo htmlspecialchars($ct['adresse']); ?>
                                    <?php if (!empty($ct['locataires'])): ?>(<?php echo htmlspecialchars($ct['locataires']); ?>)<?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                            <i class="bi bi-arrow-repeat me-1"></i>Mettre à jour le contrat
                        </button>
                    </form>
                </div>

                <!-- Transférer à un collaborateur -->
                <div class="section-card">
                    <h6 class="fw-semibold mb-3">
                        <i class="bi bi-people me-2"></i>Transférer à des collaborateurs
                    </h6>
                    <form method="POST" id="attribuer-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="attribuer">

                        <?php if (!empty($collabList)): ?>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Sélectionner un ou plusieurs collaborateurs</label>
                            <?php
                            $assignedCollabIds = array_column($assignedCollabs, 'collaborateur_id');
                            foreach ($collabList as $cl):
                                $isAssigned = in_array((int)$cl['id'], array_map('intval', $assignedCollabIds));
                            ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="collab_ids[]"
                                       id="collab-<?php echo (int)$cl['id']; ?>"
                                       value="<?php echo (int)$cl['id']; ?>"
                                       <?php echo $isAssigned ? 'checked' : ''; ?>>
                                <label class="form-check-label small" for="collab-<?php echo (int)$cl['id']; ?>">
                                    <?php echo htmlspecialchars($cl['nom']); ?>
                                    <?php if ($cl['metier']): ?>
                                        <?php if (strtolower(trim($cl['metier'])) === $serviceTechniqueMetier): ?>
                                            <span class="badge bg-warning text-dark ms-1"><i class="bi bi-tools me-1"></i>Service Technique</span>
                                        <?php else: ?>
                                            <span class="text-muted">(<?php echo htmlspecialchars($cl['metier']); ?>)</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!empty($cl['email'])): ?>— <span class="text-muted"><?php echo htmlspecialchars($cl['email']); ?></span><?php endif; ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mb-1">
                            <a href="collaborateurs.php" class="small text-muted">
                                <i class="bi bi-gear me-1"></i>Gérer les collaborateurs
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="mb-2">
                            <input type="text" class="form-control form-control-sm" name="collaborateur_nom" id="collab-nom"
                                   placeholder="Nom du collaborateur *"
                                   value="<?php echo htmlspecialchars($sig['collaborateur_nom'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-2">
                            <input type="email" class="form-control form-control-sm" name="collaborateur_email" id="collab-email"
                                   placeholder="Email"
                                   value="<?php echo htmlspecialchars($sig['collaborateur_email'] ?? ''); ?>">
                        </div>
                        <div class="mb-2">
                            <input type="tel" class="form-control form-control-sm" name="collaborateur_telephone" id="collab-tel"
                                   placeholder="Téléphone / WhatsApp"
                                   value="<?php echo htmlspecialchars($sig['collaborateur_telephone'] ?? ''); ?>">
                        </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Mode d'envoi</label>
                            <div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="mode_notification" id="mode_email"
                                           value="email" checked>
                                    <label class="form-check-label" for="mode_email">Email</label>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-info btn-sm w-100 text-white">
                            <i class="bi bi-send me-1"></i>Transférer
                        </button>
                    </form>
                </div>

                <?php endif; ?>

            </div>
        </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Stop video playback when modal is closed
    document.querySelectorAll('.modal').forEach(function(modal) {
        modal.addEventListener('hidden.bs.modal', function() {
            var video = this.querySelector('video');
            if (video) {
                video.pause();
                video.currentTime = 0;
            }
        });
    });
    </script>
</body>
</html>
