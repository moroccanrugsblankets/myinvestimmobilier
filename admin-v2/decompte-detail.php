<?php
/**
 * Détail / édition d'un décompte d'intervention — Interface admin
 * My Invest Immobilier
 *
 * Modes:
 *  - ?id=X           : Ouvrir un décompte existant
 *  - ?sig=X          : Créer un décompte pour le signalement X
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/mail-templates.php';
require_once '../pdf/generate-decompte.php';

$decompteId  = isset($_GET['id'])  ? (int)$_GET['id']  : 0;
$sigIdCreate = isset($_GET['sig']) ? (int)$_GET['sig']  : 0;

$errors     = [];
$successMsg = '';
$decompte   = null;
$sig        = null;
$lignes     = [];
$fichiers   = [];

$adminName  = $_SESSION['admin_nom'] ?? 'Administrateur';

// ── Charger ou créer le décompte ───────────────────────────────────────────────
if ($decompteId > 0) {
    // Charger un décompte existant
    try {
        // Use contrat_logement for frozen adresse/reference with fallback to logements
        $stmt = $pdo->prepare("
            SELECT d.*,
                   sig.id           AS sig_id,
                   sig.reference    AS sig_reference,
                   sig.titre        AS sig_titre,
                   sig.statut       AS sig_statut,
                   sig.nb_heures    AS sig_nb_heures,
                   sig.cout_materiaux AS sig_cout_materiaux,
                   COALESCE(cl.adresse, l.adresse) AS adresse,
                   COALESCE(cl.reference, l.reference) AS logement_reference,
                   c.reference_unique AS contrat_ref,
                   CONCAT(loc.prenom, ' ', loc.nom) AS locataire_nom,
                   loc.email AS locataire_email,
                   loc.prenom AS locataire_prenom
            FROM signalements_decomptes d
            INNER JOIN signalements sig ON d.signalement_id = sig.id
            INNER JOIN logements l ON sig.logement_id = l.id
            LEFT JOIN contrat_logement cl ON cl.contrat_id = sig.contrat_id
            INNER JOIN contrats c ON sig.contrat_id = c.id
            LEFT JOIN locataires loc ON sig.locataire_id = loc.id
            WHERE d.id = ?
            LIMIT 1
        ");
        $stmt->execute([$decompteId]);
        $decompte = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $errors[] = 'Impossible de charger le décompte : ' . $e->getMessage();
    }

    if (!$decompte) {
        header('Location: gestion-decomptes.php');
        exit;
    }

    // Charger les lignes
    try {
        $stmtL = $pdo->prepare("SELECT * FROM signalements_decomptes_lignes WHERE decompte_id = ? ORDER BY ordre ASC, id ASC");
        $stmtL->execute([$decompteId]);
        $lignes = $stmtL->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $lignes = [];
    }

    // Charger les fichiers
    try {
        $stmtF = $pdo->prepare("SELECT * FROM signalements_decomptes_fichiers WHERE decompte_id = ? ORDER BY uploaded_at ASC");
        $stmtF->execute([$decompteId]);
        $fichiers = $stmtF->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $fichiers = [];
    }

    $sig = [
        'id'        => $decompte['sig_id'],
        'reference' => $decompte['sig_reference'],
        'titre'     => $decompte['sig_titre'],
        'statut'    => $decompte['sig_statut'],
        'adresse'   => $decompte['adresse'],
    ];

} elseif ($sigIdCreate > 0) {
    // Vérifier si un décompte existe déjà
    try {
        $existCheck = $pdo->prepare("SELECT id FROM signalements_decomptes WHERE signalement_id = ? LIMIT 1");
        $existCheck->execute([$sigIdCreate]);
        $existId = $existCheck->fetchColumn();
        if ($existId) {
            header("Location: decompte-detail.php?id=$existId");
            exit;
        }
    } catch (Exception $e) {}

    // Charger le signalement
    // Use contrat_logement for frozen adresse with fallback to logements
    try {
        $stmt = $pdo->prepare("
            SELECT sig.*,
                   COALESCE(cl.adresse, l.adresse) AS adresse,
                   c.reference_unique AS contrat_ref,
                   CONCAT(loc.prenom, ' ', loc.nom) AS locataire_nom,
                   loc.email AS locataire_email,
                   loc.prenom AS locataire_prenom
            FROM signalements sig
            INNER JOIN logements l ON sig.logement_id = l.id
            LEFT JOIN contrat_logement cl ON cl.contrat_id = sig.contrat_id
            INNER JOIN contrats c ON sig.contrat_id = c.id
            LEFT JOIN locataires loc ON sig.locataire_id = loc.id
            WHERE sig.id = ?
            LIMIT 1
        ");
        $stmt->execute([$sigIdCreate]);
        $sig = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $errors[] = 'Signalement introuvable.';
    }

    if (!$sig && empty($errors)) {
        header('Location: signalements.php');
        exit;
    }
} else {
    header('Location: gestion-decomptes.php');
    exit;
}

// ── Traitement des formulaires ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token CSRF invalide. Veuillez recharger la page.';
    } else {
        $postAction = $_POST['action'];

        // ── Créer le décompte ──────────────────────────────────────────────────
        if ($postAction === 'creer_decompte' && $sigIdCreate > 0 && !$decompte) {
            try {
                $ref = 'DEC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
                $pdo->prepare("
                    INSERT INTO signalements_decomptes
                        (signalement_id, reference, statut, montant_total, cree_par, date_creation)
                    VALUES (?, ?, 'brouillon', 0.00, ?, NOW())
                ")->execute([$sigIdCreate, $ref, $adminName]);

                $newId = (int)$pdo->lastInsertId();

                // Pré-remplir les 3 lignes standards
                $defaultLignes = [
                    ['Déplacement + Diagnostic + 1h', 0.00],
                    ['Heures supplémentaires',        0.00],
                    ['Fournitures',                   0.00],
                ];
                foreach ($defaultLignes as $idx => $dl) {
                    $pdo->prepare("
                        INSERT INTO signalements_decomptes_lignes (decompte_id, ordre, intitule, montant)
                        VALUES (?, ?, ?, ?)
                    ")->execute([$newId, $idx, $dl[0], $dl[1]]);
                }

                header("Location: decompte-detail.php?id=$newId&success=created");
                exit;
            } catch (Exception $e) {
                $errors[] = 'Erreur lors de la création du décompte : ' . $e->getMessage();
            }
        }

        // ── Sauvegarder les lignes ─────────────────────────────────────────────
        if ($postAction === 'save_lignes' && $decompte) {
            $isEditable = in_array($decompte['statut'], ['brouillon', 'valide'], true);
            if (!$isEditable) {
                $errors[] = 'Ce décompte ne peut plus être modifié (statut : ' . $decompte['statut'] . ').';
            } else {
                try {
                    // Supprimer toutes les lignes et réinsérer
                    $pdo->prepare("DELETE FROM signalements_decomptes_lignes WHERE decompte_id = ?")
                        ->execute([$decompteId]);

                    $intitules = (array)($_POST['intitule'] ?? []);
                    $montants  = (array)($_POST['montant']  ?? []);
                    $total     = 0.0;

                    foreach ($intitules as $idx => $intitule) {
                        $intitule = trim($intitule);
                        $montant  = floatval(str_replace(',', '.', $montants[$idx] ?? '0'));
                        if ($intitule === '') continue;
                        $total += max(0, $montant);
                        $pdo->prepare("
                            INSERT INTO signalements_decomptes_lignes (decompte_id, ordre, intitule, montant)
                            VALUES (?, ?, ?, ?)
                        ")->execute([$decompteId, (int)$idx, $intitule, $montant]);
                    }

                    // Notes
                    $notes = trim($_POST['notes'] ?? '');
                    $pdo->prepare("UPDATE signalements_decomptes SET montant_total = ?, notes = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$total, $notes ?: null, $decompteId]);

                    // ── Traiter les nouvelles pièces jointes (upload intégré) ──────────
                    if (!empty($_FILES['new_fichiers']['name'][0])) {
                        $uploadDir = __DIR__ . '/../uploads/decomptes/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        $allowedMime = [
                            'application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                            'video/mp4', 'video/quicktime', 'video/mpeg',
                        ];
                        $fileCount = count($_FILES['new_fichiers']['name']);
                        for ($fi = 0; $fi < $fileCount; $fi++) {
                            if (empty($_FILES['new_fichiers']['tmp_name'][$fi])
                                || $_FILES['new_fichiers']['error'][$fi] !== UPLOAD_ERR_OK) {
                                continue;
                            }
                            $origName = basename($_FILES['new_fichiers']['name'][$fi]);
                            $mimeType = mime_content_type($_FILES['new_fichiers']['tmp_name'][$fi]) ?: 'application/octet-stream';
                            if (!in_array($mimeType, $allowedMime, true)) continue;
                            if ($_FILES['new_fichiers']['size'][$fi] > 50 * 1024 * 1024) continue;
                            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                            $filename = 'dec_' . $decompteId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                            if (move_uploaded_file($_FILES['new_fichiers']['tmp_name'][$fi], $uploadDir . $filename)) {
                                try {
                                    $pdo->prepare("
                                        INSERT INTO signalements_decomptes_fichiers
                                            (decompte_id, filename, original_name, mime_type, taille, uploaded_by)
                                        VALUES (?, ?, ?, ?, ?, ?)
                                    ")->execute([$decompteId, $filename, $origName, $mimeType,
                                        (int)$_FILES['new_fichiers']['size'][$fi], $adminName]);
                                } catch (Exception $eF) {
                                    error_log('save_lignes upload fichier error: ' . $eF->getMessage());
                                }
                            }
                        }
                    }

                    header("Location: decompte-detail.php?id=$decompteId&success=saved");
                    exit;
                } catch (Exception $e) {
                    $errors[] = 'Erreur : ' . $e->getMessage();
                }
            }
        }

        // ── Upload de pièce jointe ─────────────────────────────────────────────
        if ($postAction === 'upload_fichier' && $decompte) {
            $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
            if (!empty($_FILES['fichier']['tmp_name']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../uploads/decomptes/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $origName = basename($_FILES['fichier']['name']);
                $mimeType = mime_content_type($_FILES['fichier']['tmp_name']) ?: 'application/octet-stream';
                $allowedMime = [
                    'application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                    'video/mp4', 'video/quicktime', 'video/mpeg',
                ];
                if (!in_array($mimeType, $allowedMime, true)) {
                    $uploadError = 'Type de fichier non autorisé.';
                } elseif ($_FILES['fichier']['size'] > 50 * 1024 * 1024) {
                    $uploadError = 'Fichier trop volumineux (max 50 Mo).';
                } else {
                    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $filename = 'dec_' . $decompteId . '_' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($_FILES['fichier']['tmp_name'], $uploadDir . $filename)) {
                        try {
                            $pdo->prepare("
                                INSERT INTO signalements_decomptes_fichiers
                                    (decompte_id, filename, original_name, mime_type, taille, uploaded_by)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ")->execute([$decompteId, $filename, $origName, $mimeType, (int)$_FILES['fichier']['size'], $adminName]);
                            $newFileId = (int)$pdo->lastInsertId();
                            if ($isAjax) {
                                header('Content-Type: application/json');
                                echo json_encode([
                                    'success' => true,
                                    'id'      => $newFileId,
                                    'filename'=> $filename,
                                    'original_name' => $origName,
                                    'mime_type'     => $mimeType,
                                    'taille'        => (int)$_FILES['fichier']['size'],
                                ]);
                                exit;
                            }
                            header("Location: decompte-detail.php?id=$decompteId&success=upload");
                            exit;
                        } catch (Exception $e) {
                            $uploadError = 'Erreur BD : ' . $e->getMessage();
                        }
                    } else {
                        $uploadError = 'Impossible d\'enregistrer le fichier.';
                    }
                }
            } else {
                $uploadError = 'Aucun fichier reçu.';
            }
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $uploadError ?? 'Erreur inconnue.']);
                exit;
            }
            $errors[] = $uploadError ?? 'Erreur inconnue.';
        }

        // ── Supprimer une pièce jointe ─────────────────────────────────────────
        if ($postAction === 'delete_fichier' && $decompte) {
            $fichierId = (int)($_POST['fichier_id'] ?? 0);
            $isAjax    = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
            if ($fichierId > 0) {
                try {
                    $fStmt = $pdo->prepare("SELECT filename FROM signalements_decomptes_fichiers WHERE id = ? AND decompte_id = ?");
                    $fStmt->execute([$fichierId, $decompteId]);
                    $fRow = $fStmt->fetch(PDO::FETCH_ASSOC);
                    if ($fRow) {
                        $fPath = __DIR__ . '/../uploads/decomptes/' . $fRow['filename'];
                        if (file_exists($fPath)) {
                            @unlink($fPath);
                        }
                        $pdo->prepare("DELETE FROM signalements_decomptes_fichiers WHERE id = ? AND decompte_id = ?")
                            ->execute([$fichierId, $decompteId]);
                    }
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true]);
                        exit;
                    }
                    header("Location: decompte-detail.php?id=$decompteId&success=deleted");
                    exit;
                } catch (Exception $e) {
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                        exit;
                    }
                    $errors[] = 'Erreur : ' . $e->getMessage();
                }
            }
        }

        // ── Valider le décompte ────────────────────────────────────────────────
        if ($postAction === 'valider' && $decompte && $decompte['statut'] === 'brouillon') {
            try {
                $pdo->prepare("
                    UPDATE signalements_decomptes
                    SET statut = 'valide', date_validation = NOW(), valide_par = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$adminName, $decompteId]);

                // Notifier les collaborateurs
                $companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';
                $stmtLignes  = $pdo->prepare("SELECT * FROM signalements_decomptes_lignes WHERE decompte_id = ? ORDER BY ordre ASC");
                $stmtLignes->execute([$decompteId]);
                $allLignes = $stmtLignes->fetchAll(PDO::FETCH_ASSOC);

                $lignesHtml = '';
                if (!empty($allLignes)) {
                    $lignesHtml = '<table style="width:100%;border-collapse:collapse;margin-top:10px;">'
                        . '<thead><tr style="background:#f8f9fa;">'
                        . '<th style="border:1px solid #dee2e6;padding:8px;text-align:left;">Intitulé</th>'
                        . '<th style="border:1px solid #dee2e6;padding:8px;text-align:right;white-space:nowrap;">Montant</th>'
                        . '</tr></thead><tbody>';
                    foreach ($allLignes as $lg) {
                        $lignesHtml .= '<tr>'
                            . '<td style="border:1px solid #dee2e6;padding:8px;">' . htmlspecialchars($lg['intitule']) . '</td>'
                            . '<td style="border:1px solid #dee2e6;padding:8px;text-align:right;">' . number_format((float)$lg['montant'], 2, ',', ' ') . ' €</td>'
                            . '</tr>';
                    }
                    $lignesHtml .= '</tbody></table>';
                }

                $emailVars = [
                    'prenom'                => $decompte['locataire_prenom'] ?? '',
                    'nom'                   => $decompte['locataire_nom'] ?? '',
                    'reference_decompte'    => $decompte['reference'],
                    'reference_signalement' => $decompte['sig_reference'],
                    'titre'                 => $decompte['sig_titre'],
                    'adresse'               => $decompte['adresse'],
                    'logement_reference'    => $decompte['logement_reference'] ?? '',
                    'montant_total'         => number_format((float)$decompte['montant_total'], 2, ',', ' '),
                    'lignes_html'           => $lignesHtml,
                    'company'               => $companyName,
                ];

                // Notifier le locataire (client) avec BCC aux administrateurs
                $locataireEmailVal = $decompte['locataire_email'] ?? '';
                if (!empty($locataireEmailVal)) {
                    sendTemplatedEmail('decompte_valide_client', $locataireEmailVal, $emailVars, null, false, true,
                        ['contexte' => 'decompte_valide_client;dec_id=' . $decompteId]);
                }

                header("Location: decompte-detail.php?id=$decompteId&success=valide");
                exit;
            } catch (Exception $e) {
                $errors[] = 'Erreur lors de la validation : ' . $e->getMessage();
            }
        }

        // ── Générer le lien de paiement Stripe ────────────────────────────────
        if ($postAction === 'generer_lien_paiement' && $decompte && $decompte['statut'] === 'facture_envoyee') {
            try {
                // Générer un token sécurisé de 64 caractères hexadécimaux
                $token = bin2hex(random_bytes(32));
                // Expiration dans 30 jours
                $expiration = date('Y-m-d H:i:s', strtotime('+30 days'));

                // Note : on efface l'ancienne stripe_session_id — si elle était encore ouverte,
                // Stripe l'expirera automatiquement après 24h. Une nouvelle session sera créée
                // lors du prochain accès au lien de paiement.

                $pdo->prepare("
                    UPDATE signalements_decomptes
                    SET token_paiement = ?,
                        token_paiement_expiration = ?,
                        statut_paiement = 'en_attente',
                        stripe_session_id = NULL,
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$token, $expiration, $decompteId]);

                header("Location: decompte-detail.php?id=$decompteId&success=lien_paiement");
                exit;
            } catch (Exception $e) {
                $errors[] = 'Erreur lors de la génération du lien : ' . $e->getMessage();
            }
        }

        // ── Envoyer le lien de paiement Stripe par email ──────────────────────
        if ($postAction === 'envoyer_lien_paiement_email' && $decompte && $decompte['statut'] === 'facture_envoyee') {
            $locataireEmail = $decompte['locataire_email'] ?? '';
            if (empty($locataireEmail)) {
                $errors[] = 'Adresse email du locataire introuvable.';
            } elseif (empty($decompte['token_paiement'])) {
                $errors[] = 'Aucun lien de paiement généré. Veuillez d\'abord générer le lien Stripe.';
            } else {
                $companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';
                $lienPaiementUrl = rtrim($config['SITE_URL'] ?? '', '/') . '/payment/pay-decompte.php?token=' . urlencode($decompte['token_paiement']);
                $boutonsHtml = '<div style="text-align:center;margin:24px 0;">'
                    . '<a href="' . htmlspecialchars($lienPaiementUrl) . '" style="display:inline-block;background:#635bff;color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:700;font-size:1rem;">'
                    . '💳 Payer en ligne</a></div>';
                $expDate = !empty($decompte['token_paiement_expiration'])
                    ? date('d/m/Y', strtotime($decompte['token_paiement_expiration'])) : '';

                $emailVarsPay = [
                    'prenom'             => $decompte['locataire_prenom'] ?? '',
                    'nom'                => $decompte['locataire_nom'] ?? '',
                    'reference'          => $decompte['reference'],
                    'reference_sig'      => $decompte['sig_reference'],
                    'titre'              => $decompte['sig_titre'],
                    'adresse'            => $decompte['adresse'],
                    'logement_reference' => $decompte['logement_reference'] ?? '',
                    'montant_total'      => number_format((float)$decompte['montant_total'], 2, ',', ' '),
                    'lien_paiement'      => $lienPaiementUrl,
                    'bouton_paiement'    => $boutonsHtml,
                    'date_expiration'    => $expDate,
                    'company'            => $companyName,
                ];

                // Use template if exists, otherwise fallback
                $sent = false;
                if (getEmailTemplate('decompte_lien_paiement_stripe')) {
                    $sent = (bool)sendTemplatedEmail(
                        'decompte_lien_paiement_stripe',
                        $locataireEmail,
                        $emailVarsPay,
                        null, false, true,
                        ['contexte' => 'lien_paiement_stripe;dec_id=' . $decompteId]
                    );
                } else {
                    // Fallback inline HTML
                    $subject  = 'Règlement de la facture ' . $decompte['reference'] . ' — ' . $companyName;
                    $htmlBody = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;margin:0 auto;padding:20px;">'
                        . '<h2 style="color:#635bff;">💳 Lien de paiement Stripe</h2>'
                        . '<p>Bonjour ' . htmlspecialchars($emailVarsPay['prenom']) . ' ' . htmlspecialchars($emailVarsPay['nom']) . ',</p>'
                        . '<p>Veuillez régler la facture <strong>' . htmlspecialchars($decompte['reference']) . '</strong> d\'un montant de <strong>' . htmlspecialchars($emailVarsPay['montant_total']) . ' €</strong>.</p>'
                        . $boutonsHtml
                        . '<p><small>Lien : <a href="' . htmlspecialchars($lienPaiementUrl) . '">' . htmlspecialchars($lienPaiementUrl) . '</a></small></p>'
                        . ($expDate ? '<p><small>Ce lien expire le ' . htmlspecialchars($expDate) . '.</small></p>' : '')
                        . '<p>Cordialement,<br>' . htmlspecialchars($companyName) . '</p>'
                        . '</body></html>';
                    $sent = sendEmail($locataireEmail, $subject, $htmlBody, null, true, false, null, null, true,
                        ['contexte' => 'lien_paiement_stripe_fallback;dec_id=' . $decompteId]);
                }

                if ($sent) {
                    header("Location: decompte-detail.php?id=$decompteId&success=lien_paiement_email");
                    exit;
                } else {
                    $errors[] = 'L\'envoi de l\'email a échoué. Vérifiez la configuration email dans Admin > Paramètres (adresse expéditeur et paramètres SMTP).';
                }
            }
        }

        // ── Envoyer la facture au locataire ────────────────────────────────────
        if ($postAction === 'envoyer_facture' && $decompte && $decompte['statut'] === 'valide') {
            $locataireEmail = $decompte['locataire_email'] ?? '';
            if (empty($locataireEmail)) {
                $errors[] = 'Adresse email du locataire introuvable.';
            } else {
                try {
                    // Recalculer lignes pour le corps de la facture
                    $stmtLF = $pdo->prepare("SELECT * FROM signalements_decomptes_lignes WHERE decompte_id = ? ORDER BY ordre ASC");
                    $stmtLF->execute([$decompteId]);
                    $allLignesF = $stmtLF->fetchAll(PDO::FETCH_ASSOC);

                    // Construire un corps de facture simple HTML
                    $lignesTableHtml = '<table style="width:100%;border-collapse:collapse;margin:10px 0;">'
                        . '<thead><tr style="background:#2c3e50;color:white;">'
                        . '<th style="padding:10px;text-align:left;">Intitulé</th>'
                        . '<th style="padding:10px;text-align:right;">Montant</th>'
                        . '</tr></thead><tbody>';
                    foreach ($allLignesF as $lg) {
                        $lignesTableHtml .= '<tr style="border-bottom:1px solid #eee;">'
                            . '<td style="padding:8px;">' . htmlspecialchars($lg['intitule']) . '</td>'
                            . '<td style="padding:8px;text-align:right;">' . number_format((float)$lg['montant'], 2, ',', ' ') . ' €</td>'
                            . '</tr>';
                    }
                    $lignesTableHtml .= '<tr style="background:#f8f9fa;font-weight:bold;">'
                        . '<td style="padding:10px;">Total</td>'
                        . '<td style="padding:10px;text-align:right;">' . number_format((float)$decompte['montant_total'], 2, ',', ' ') . ' €</td>'
                        . '</tr></tbody></table>';

                    $companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';
                    $emailVarsF  = [
                        'prenom'             => $decompte['locataire_prenom'] ?? '',
                        'nom'                => $decompte['locataire_nom'] ?? '',
                        'reference'          => $decompte['reference'],
                        'reference_sig'      => $decompte['sig_reference'],
                        'titre'              => $decompte['sig_titre'],
                        'adresse'            => $decompte['adresse'],
                        'logement_reference' => $decompte['logement_reference'] ?? '',
                        'montant_total'      => number_format((float)$decompte['montant_total'], 2, ',', ' '),
                        'lignes_html'        => $lignesTableHtml,
                        'date_facture'       => date('d/m/Y'),
                        'company'            => $companyName,
                        'contrat_ref'        => $decompte['contrat_ref'] ?? '',
                    ];

                    // Send invoice to tenant with optional file attachments
                    $sent = sendFactureEmail($locataireEmail, $emailVarsF, $fichiers, $decompteId);

                    if ($sent) {
                        $pdo->prepare("
                            UPDATE signalements_decomptes
                            SET statut = 'facture_envoyee', date_facture = NOW(), updated_at = NOW()
                            WHERE id = ?
                        ")->execute([$decompteId]);

                        header("Location: decompte-detail.php?id=$decompteId&success=facture");
                        exit;
                    } else {
                        $errors[] = 'L\'envoi de la facture a échoué. Vérifiez la configuration email dans Admin > Paramètres (adresse expéditeur et paramètres SMTP).';
                    }
                } catch (Exception $e) {
                    $errors[] = 'Erreur : ' . $e->getMessage();
                }
            }
        }
    }
}

// Recharger après action
if ($decompteId > 0) {
    try {
        // Use contrat_logement for frozen adresse/reference with fallback to logements
        $reloadStmt = $pdo->prepare("
            SELECT d.*,
                   sig.id           AS sig_id,
                   sig.reference    AS sig_reference,
                   sig.titre        AS sig_titre,
                   sig.statut       AS sig_statut,
                   sig.nb_heures    AS sig_nb_heures,
                   sig.cout_materiaux AS sig_cout_materiaux,
                   COALESCE(cl.adresse, l.adresse) AS adresse,
                   COALESCE(cl.reference, l.reference) AS logement_reference,
                   c.reference_unique AS contrat_ref,
                   CONCAT(loc.prenom, ' ', loc.nom) AS locataire_nom,
                   loc.email AS locataire_email,
                   loc.prenom AS locataire_prenom
            FROM signalements_decomptes d
            INNER JOIN signalements sig ON d.signalement_id = sig.id
            INNER JOIN logements l ON sig.logement_id = l.id
            LEFT JOIN contrat_logement cl ON cl.contrat_id = sig.contrat_id
            INNER JOIN contrats c ON sig.contrat_id = c.id
            LEFT JOIN locataires loc ON sig.locataire_id = loc.id
            WHERE d.id = ?
            LIMIT 1
        ");
        $reloadStmt->execute([$decompteId]);
        $decompte = $reloadStmt->fetch(PDO::FETCH_ASSOC);

        $reloadLignes = $pdo->prepare("SELECT * FROM signalements_decomptes_lignes WHERE decompte_id = ? ORDER BY ordre ASC, id ASC");
        $reloadLignes->execute([$decompteId]);
        $lignes = $reloadLignes->fetchAll(PDO::FETCH_ASSOC);

        $reloadFichiers = $pdo->prepare("SELECT * FROM signalements_decomptes_fichiers WHERE decompte_id = ? ORDER BY uploaded_at ASC");
        $reloadFichiers->execute([$decompteId]);
        $fichiers = $reloadFichiers->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

$successParam = $_GET['success'] ?? '';
$successMessages = [
    'created'              => 'Décompte créé avec succès. Les lignes standards ont été pré-remplies.',
    'saved'                => 'Lignes sauvegardées avec succès.',
    'upload'               => 'Pièce jointe ajoutée.',
    'deleted'              => 'Pièce jointe supprimée.',
    'valide'               => 'Décompte validé. Les collaborateurs ont été notifiés.',
    'facture'              => 'Facture envoyée au locataire.',
    'lien_paiement'        => 'Lien de paiement Stripe généré avec succès.',
    'lien_paiement_email'  => 'Lien de paiement Stripe envoyé par email au locataire.',
];
if ($successParam && isset($successMessages[$successParam])) {
    $successMsg = $successMessages[$successParam];
}

$csrfToken        = generateCsrfToken();
$isEditable       = $decompte && in_array($decompte['statut'], ['brouillon', 'valide'], true);
$isValide         = $decompte && $decompte['statut'] === 'valide';
$isFactureEnvoyee = $decompte && $decompte['statut'] === 'facture_envoyee';
$siteUrl          = rtrim($config['SITE_URL'] ?? '', '/');

// Build public payment URL if token exists
$lienPaiement = null;
if (!empty($decompte['token_paiement'])) {
    $lienPaiement = $siteUrl . '/payment/pay-decompte.php?token=' . urlencode($decompte['token_paiement']);
}
$paiementStatut = $decompte['statut_paiement'] ?? 'non_genere';
// Helper: Build invoice HTML for sending (fallback if no DB template found)
function buildFactureHtml(array $vars, array $fichiers = []): string {
    $lignesHtml = $vars['lignes_html'] ?? '';
    $logRef = !empty($vars['logement_reference'])
        ? '<p style="margin:5px 0;"><strong>Réf. logement :</strong> <code>' . htmlspecialchars($vars['logement_reference']) . '</code></p>'
        : '';
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Facture</title></head>
<body style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:640px;margin:0 auto;padding:20px;">
<div style="background:#2c3e50;color:white;padding:30px;text-align:center;border-radius:10px 10px 0 0;">
<h1 style="margin:0;">📄 Facture d\'Intervention</h1>
<p style="margin:10px 0 0;">' . htmlspecialchars($vars['company']) . '</p>
</div>
<div style="background:#fff;padding:30px;border:1px solid #e0e0e0;border-top:none;">
<p>Bonjour ' . htmlspecialchars($vars['prenom']) . ' ' . htmlspecialchars($vars['nom']) . ',</p>
<p>Veuillez trouver ci-dessous la facture relative à l\'intervention effectuée dans votre logement.</p>
<div style="background:#f8f9fa;border-left:4px solid #3498db;padding:15px;margin:20px 0;border-radius:0 5px 5px 0;">
<p style="margin:5px 0;"><strong>N° Facture :</strong> <code>' . htmlspecialchars($vars['reference']) . '</code></p>
<p style="margin:5px 0;"><strong>Signalement :</strong> ' . htmlspecialchars($vars['reference_sig']) . ' — ' . htmlspecialchars($vars['titre']) . '</p>
<p style="margin:5px 0;"><strong>Logement :</strong> ' . htmlspecialchars($vars['adresse']) . '</p>
' . $logRef . '
<p style="margin:5px 0;"><strong>Date :</strong> ' . htmlspecialchars($vars['date_facture']) . '</p>
</div>
' . $lignesHtml . '
</div>
<div style="background:#f8f9fa;padding:15px;text-align:center;border-radius:0 0 10px 10px;border:1px solid #e0e0e0;border-top:none;">
<p style="margin:0;color:#666;font-size:12px;">' . htmlspecialchars($vars['company']) . '</p>
</div>
</body></html>';
}

function sendFactureEmail(string $to, array $vars, array $fichiers, int $decompteId): bool {
    // Build attachment list from uploaded files
    $attachments = [];
    foreach ($fichiers as $f) {
        $path = __DIR__ . '/../uploads/decomptes/' . $f['filename'];
        if (file_exists($path)) {
            $attachments[] = ['path' => $path, 'name' => $f['original_name']];
        }
    }

    // Generate PDF of the decompte and prepend it to attachments
    $pdfPath = generateDecomptePDF($decompteId, $vars);
    if ($pdfPath && file_exists($pdfPath)) {
        $pdfName = 'decompte-' . preg_replace('/[^A-Za-z0-9\-_]/', '_', $vars['reference'] ?? (string)$decompteId) . '.pdf';
        array_unshift($attachments, ['path' => $pdfPath, 'name' => $pdfName]);
    }

    $emailAttachments = !empty($attachments) ? $attachments : null;

    // Use template if it exists in the DB
    if (getEmailTemplate('facture_intervention')) {
        return (bool)sendTemplatedEmail(
            'facture_intervention',
            $to,
            $vars,
            $emailAttachments,
            false, // isAdminEmail
            true,  // addAdminBcc – admins receive BCC hidden from locataire
            ['contexte' => 'facture_intervention;dec_id=' . $decompteId]
        );
    }

    // Fallback: build inline HTML when template not yet created in DB
    $factureHtml = buildFactureHtml($vars, $fichiers);
    $subject     = 'Facture d\'intervention — ' . ($vars['reference'] ?? '');
    return sendEmail(
        $to,
        $subject,
        $factureHtml,
        $emailAttachments,
        true,  // isHtml
        false, // isAdminEmail
        null,  // replyTo
        null,  // replyToName
        true,  // addAdminBcc
        ['contexte' => 'facture_intervention;dec_id=' . $decompteId]
    );
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $decompte ? 'Décompte ' . htmlspecialchars($decompte['reference']) : 'Créer un décompte'; ?> — Admin My Invest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .section-card { background: #fff; border-radius: 10px; padding: 22px; margin-bottom: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.07); }
        .ligne-row td { vertical-align: middle; }
        .total-row { background: #f8f9fa; font-weight: bold; }
        .btn-delete-ligne { color: #dc3545; }
        .btn-delete-ligne:hover { background: #dc3545; color: #fff; }
        /* Drop zone for new files */
        .drop-zone-dec {
            border: 2px dashed #ced4da;
            border-radius: 8px;
            padding: 18px 14px;
            text-align: center;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            background: #fafafa;
        }
        .drop-zone-dec.drag-over { border-color: #0d6efd; background: #f0f4ff; }
        .new-file-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            margin-bottom: 4px;
            background: #fff;
            font-size: .875rem;
        }
        .new-file-name { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .new-file-size { color: #6c757d; font-size: .75rem; white-space: nowrap; }
        .btn-remove-new-file { flex-shrink: 0; background: none; border: none; color: #dc3545; padding: 2px 6px; cursor: pointer; border-radius: 4px; line-height: 1; }
        .btn-remove-new-file:hover { background: #f8d7da; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="container-fluid mt-4">

        <!-- En-tête -->
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h1>
                    <i class="bi bi-receipt me-2"></i>
                    <?php echo $decompte ? 'Décompte ' . htmlspecialchars($decompte['reference']) : 'Nouveau décompte'; ?>
                </h1>
                <?php if ($sig): ?>
                <p class="text-muted mb-0">
                    Signalement
                    <a href="signalement-detail.php?id=<?php echo $sig['id']; ?>">
                        <?php echo htmlspecialchars($sig['reference']); ?>
                    </a>
                    — <?php echo htmlspecialchars($sig['titre']); ?>
                    — <?php echo htmlspecialchars($decompte ? $decompte['adresse'] : ($sig['adresse'] ?? '')); ?>
                </p>
                <?php endif; ?>
            </div>
            <a href="<?php echo $decompte ? 'gestion-decomptes.php' : 'signalement-detail.php?id=' . $sigIdCreate; ?>" class="btn btn-outline-secondary">
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

        <?php if (!$decompte && $sig): ?>
        <!-- ─── MODE CRÉATION ────────────────────────────────────────────────── -->
        <div class="section-card">
            <h5 class="mb-3"><i class="bi bi-plus-circle me-2"></i>Créer le décompte d'intervention</h5>
            <p class="text-muted">Un décompte sera créé pour le signalement <strong><?php echo htmlspecialchars($sig['reference']); ?></strong> avec 3 lignes standards pré-remplies.</p>
            <div class="row g-3 mb-3">
                <div class="col-sm-4">
                    <div class="p-3 bg-light rounded">
                        <small class="text-muted d-block">Référence</small>
                        <strong class="font-monospace"><?php echo htmlspecialchars($sig['reference']); ?></strong>
                    </div>
                </div>
                <div class="col-sm-8">
                    <div class="p-3 bg-light rounded">
                        <small class="text-muted d-block">Titre</small>
                        <strong><?php echo htmlspecialchars($sig['titre']); ?></strong>
                    </div>
                </div>
                <div class="col-12">
                    <div class="p-3 bg-light rounded">
                        <small class="text-muted d-block">Logement</small>
                        <?php echo htmlspecialchars($sig['adresse'] ?? ''); ?>
                    </div>
                </div>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="creer_decompte">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-1"></i>Créer le décompte
                </button>
            </form>
        </div>

        <?php elseif ($decompte): ?>
        <!-- ─── MODE ÉDITION / VISUALISATION ─────────────────────────────────── -->

        <div class="row g-4">
            <!-- Colonne gauche : lignes -->
            <div class="col-lg-8">

                <!-- Informations du décompte -->
                <div class="section-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Lignes du décompte</h5>
                        <?php if ($decompte['statut'] !== 'brouillon'): ?>
                        <span class="badge bg-<?php echo $decompte['statut'] === 'valide' ? 'success' : 'primary'; ?> fs-6">
                            <?php echo $decompte['statut'] === 'valide' ? 'Validé' : 'Facture envoyée'; ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($isEditable): ?>
                    <form method="POST" id="form-lignes" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="save_lignes">

                        <div class="table-responsive mb-3">
                            <table class="table table-bordered mb-0" id="lignes-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>Intitulé</th>
                                        <th style="width:150px;">Montant (€)</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="lignes-body">
                                    <?php foreach ($lignes as $idx => $lg): ?>
                                    <tr class="ligne-row">
                                        <td>
                                            <input type="text" class="form-control form-control-sm"
                                                   name="intitule[]"
                                                   value="<?php echo htmlspecialchars($lg['intitule']); ?>"
                                                   required>
                                        </td>
                                        <td>
                                            <input type="number" class="form-control form-control-sm montant-input"
                                                   name="montant[]"
                                                   value="<?php echo number_format((float)$lg['montant'], 2, '.', ''); ?>"
                                                   min="0" max="99999" step="0.01">
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-delete-ligne border"
                                                    onclick="deleteLigne(this)" title="Supprimer">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="total-row">
                                        <td class="text-end fw-bold">Total</td>
                                        <td class="fw-bold text-end" id="total-display">
                                            <?php echo number_format((float)$decompte['montant_total'], 2, ',', ' '); ?> €
                                        </td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="mb-3">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="add-ligne-btn">
                                <i class="bi bi-plus-circle me-1"></i>Ajouter une ligne
                            </button>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea class="form-control" name="notes" rows="2"
                                      placeholder="Observations, remarques..."><?php echo htmlspecialchars($decompte['notes'] ?? ''); ?></textarea>
                        </div>

                        <!-- ── Pièces jointes (intégrées au formulaire) ── -->
                        <hr class="my-3">
                        <h6 class="fw-semibold mb-3"><i class="bi bi-paperclip me-2"></i>Pièces jointes</h6>

                        <!-- Fichiers déjà sauvegardés -->
                        <ul class="list-group mb-3" id="fichiers-list">
                            <?php if (!empty($fichiers)): ?>
                            <?php foreach ($fichiers as $f): ?>
                            <?php
                            $isImg = strpos($f['mime_type'], 'image/') === 0;
                            $isPdf = $f['mime_type'] === 'application/pdf';
                            $isVid = strpos($f['mime_type'], 'video/') === 0;
                            $icon  = $isPdf ? 'bi-file-earmark-pdf text-danger' : ($isImg ? 'bi-file-image text-primary' : ($isVid ? 'bi-camera-video text-info' : 'bi-file-earmark'));
                            ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center" data-fichier-id="<?php echo (int)$f['id']; ?>">
                                <div>
                                    <i class="bi <?php echo $icon; ?> me-2"></i>
                                    <a href="<?php echo htmlspecialchars($siteUrl . '/uploads/decomptes/' . $f['filename']); ?>"
                                       target="_blank">
                                        <?php echo htmlspecialchars($f['original_name']); ?>
                                    </a>
                                    <small class="text-muted ms-2">
                                        <?php echo $f['taille'] ? round($f['taille'] / 1024) . ' Ko' : ''; ?>
                                    </small>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-danger btn-delete-fichier"
                                        data-fichier-id="<?php echo (int)$f['id']; ?>"
                                        data-csrf="<?php echo htmlspecialchars($csrfToken); ?>"
                                        title="Supprimer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </li>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <li class="list-group-item text-muted small" id="no-fichiers-msg">Aucune pièce jointe.</li>
                            <?php endif; ?>
                        </ul>

                        <!-- Sélection de nouveaux fichiers (drag & drop) -->
                        <div class="drop-zone-dec" id="dropZoneDec">
                            <input type="file" id="newFichiersInput" name="new_fichiers[]" multiple
                                   accept=".pdf,image/*,video/*" style="display:none;">
                            <i class="bi bi-cloud-upload fs-4 text-muted d-block mb-1"></i>
                            <p class="mb-1 small fw-semibold">Glissez vos fichiers ici</p>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnBrowseDec">
                                <i class="bi bi-folder2-open me-1"></i>Parcourir
                            </button>
                            <p class="mt-1 mb-0 text-muted" style="font-size:.75rem;">PDF, images, vidéos. Max 50 Mo par fichier.</p>
                        </div>

                        <div id="new-fichiers-wrapper" class="mt-2" style="display:none;">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small fw-semibold">Nouveaux fichiers (<span id="newFichiersCount">0</span>)</span>
                                <button type="button" class="btn btn-outline-danger btn-sm" id="btnClearDec">
                                    <i class="bi bi-trash me-1"></i>Tout supprimer
                                </button>
                            </div>
                            <ul class="list-unstyled mb-0" id="newFichiersPreview"></ul>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary" id="btn-save-lignes">
                                <i class="bi bi-save me-1"></i>Sauvegarder
                            </button>
                        </div>
                    </form>
                    <?php else: ?>
                    <!-- Mode lecture seule -->
                    <div class="table-responsive mb-3">
                        <table class="table table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Intitulé</th>
                                    <th class="text-end" style="width:150px;">Montant</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lignes as $lg): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($lg['intitule']); ?></td>
                                    <td class="text-end"><?php echo number_format((float)$lg['montant'], 2, ',', ' '); ?> €</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="total-row">
                                    <td class="text-end fw-bold">Total</td>
                                    <td class="text-end fw-bold"><?php echo number_format((float)$decompte['montant_total'], 2, ',', ' '); ?> €</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php if (!empty($decompte['notes'])): ?>
                    <div class="p-3 bg-light rounded small"><?php echo nl2br(htmlspecialchars($decompte['notes'])); ?></div>
                    <?php endif; ?>

                    <!-- Pièces jointes (lecture seule) -->
                    <hr class="my-3">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-paperclip me-2"></i>Pièces jointes</h6>
                    <ul class="list-group mb-0" id="fichiers-list">
                        <?php if (!empty($fichiers)): ?>
                        <?php foreach ($fichiers as $f): ?>
                        <?php
                        $isImg = strpos($f['mime_type'], 'image/') === 0;
                        $isPdf = $f['mime_type'] === 'application/pdf';
                        $isVid = strpos($f['mime_type'], 'video/') === 0;
                        $icon  = $isPdf ? 'bi-file-earmark-pdf text-danger' : ($isImg ? 'bi-file-image text-primary' : ($isVid ? 'bi-camera-video text-info' : 'bi-file-earmark'));
                        ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center" data-fichier-id="<?php echo (int)$f['id']; ?>">
                            <div>
                                <i class="bi <?php echo $icon; ?> me-2"></i>
                                <a href="<?php echo htmlspecialchars($siteUrl . '/uploads/decomptes/' . $f['filename']); ?>"
                                   target="_blank">
                                    <?php echo htmlspecialchars($f['original_name']); ?>
                                </a>
                                <small class="text-muted ms-2">
                                    <?php echo $f['taille'] ? round($f['taille'] / 1024) . ' Ko' : ''; ?>
                                </small>
                            </div>
                        </li>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <li class="list-group-item text-muted small">Aucune pièce jointe.</li>
                        <?php endif; ?>
                    </ul>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Colonne droite : actions -->
            <div class="col-lg-4">

                <!-- Statut et infos -->
                <div class="section-card">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-info-circle me-2"></i>Informations</h6>
                    <dl class="row small mb-0">
                        <dt class="col-5">Référence</dt>
                        <dd class="col-7 font-monospace"><?php echo htmlspecialchars($decompte['reference']); ?></dd>

                        <dt class="col-5">Statut</dt>
                        <dd class="col-7">
                            <?php
                            $sLabels = ['brouillon' => ['Brouillon', 'secondary'], 'valide' => ['Validé', 'success'], 'facture_envoyee' => ['Facture envoyée', 'primary']];
                            $sl = $sLabels[$decompte['statut']] ?? [$decompte['statut'], 'secondary'];
                            ?>
                            <span class="badge bg-<?php echo $sl[1]; ?>"><?php echo $sl[0]; ?></span>
                        </dd>

                        <?php if ($isFactureEnvoyee): ?>
                        <dt class="col-5">Paiement</dt>
                        <dd class="col-7">
                            <?php
                            $pLabels = [
                                'non_genere' => ['Non généré', 'secondary'],
                                'en_attente' => ['En attente', 'warning'],
                                'paye'       => ['Payé', 'success'],
                                'annule'     => ['Annulé', 'danger'],
                            ];
                            $pl = $pLabels[$paiementStatut] ?? [$paiementStatut, 'secondary'];
                            ?>
                            <span class="badge bg-<?php echo $pl[1]; ?>"><?php echo $pl[0]; ?></span>
                        </dd>
                        <?php endif; ?>

                        <dt class="col-5">Montant total</dt>
                        <dd class="col-7 fw-bold"><?php echo number_format((float)$decompte['montant_total'], 2, ',', ' '); ?> €</dd>

                        <dt class="col-5">Créé par</dt>
                        <dd class="col-7"><?php echo htmlspecialchars($decompte['cree_par'] ?? '—'); ?></dd>

                        <dt class="col-5">Date création</dt>
                        <dd class="col-7"><?php echo date('d/m/Y H:i', strtotime($decompte['date_creation'])); ?></dd>

                        <?php if ($decompte['date_validation']): ?>
                        <dt class="col-5">Validé le</dt>
                        <dd class="col-7"><?php echo date('d/m/Y H:i', strtotime($decompte['date_validation'])); ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>

                <!-- Actions -->
                <?php if ($decompte && $decompte['statut'] === 'brouillon'): ?>
                <div class="section-card border border-success">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-check-circle me-2 text-success"></i>Valider le décompte</h6>
                    <p class="text-muted small">Après validation, les collaborateurs sont notifiés par email. Vous pourrez encore modifier les lignes et pièces jointes.</p>
                    <form method="POST" onsubmit="return confirm('Valider ce décompte ?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="valider">
                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-check-circle me-1"></i>Valider le décompte
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <?php if ($isValide): ?>
                <div class="section-card border border-primary">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-send me-2 text-primary"></i>Convertir en facture</h6>
                    <p class="text-muted small">Envoie une facture par email au locataire avec le PDF du décompte en pièce jointe.</p>
                    <?php if (!empty($decompte['locataire_email'])): ?>
                    <p class="small mb-3">
                        <i class="bi bi-envelope me-1"></i>
                        <strong><?php echo htmlspecialchars($decompte['locataire_email']); ?></strong>
                    </p>
                    <form method="POST" onsubmit="return confirm('Envoyer la facture au locataire ?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="envoyer_facture">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-send me-1"></i>Envoyer la facture (+ PDF)
                        </button>
                    </form>
                    <?php else: ?>
                    <div class="alert alert-warning small mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>Aucun email locataire disponible.
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($isFactureEnvoyee): ?>
                <!-- ── Paiement Stripe ──────────────────────────────────────────── -->
                <div class="section-card border border-<?php echo $paiementStatut === 'paye' ? 'success' : 'warning'; ?>">
                    <h6 class="fw-semibold mb-3">
                        <i class="bi bi-credit-card me-2 text-<?php echo $paiementStatut === 'paye' ? 'success' : 'warning'; ?>"></i>
                        Paiement Stripe
                    </h6>

                    <?php if ($paiementStatut === 'paye'): ?>
                    <!-- Déjà payé -->
                    <div class="alert alert-success small mb-2">
                        <i class="bi bi-check-circle me-1"></i>
                        <strong>Décompte réglé</strong>
                        <?php if (!empty($decompte['date_paiement'])): ?>
                        — le <?php echo date('d/m/Y', strtotime($decompte['date_paiement'])); ?>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>

                    <?php
                    $statutPaiementLabels = [
                        'non_genere' => ['Lien non généré',    'secondary'],
                        'en_attente' => ['En attente',         'warning'],
                        'annule'     => ['Annulé',             'danger'],
                    ];
                    $spLabel = $statutPaiementLabels[$paiementStatut] ?? [$paiementStatut, 'secondary'];
                    ?>
                    <p class="small mb-2">
                        Statut :
                        <span class="badge bg-<?php echo $spLabel[1]; ?>"><?php echo $spLabel[0]; ?></span>
                    </p>

                    <?php if ($lienPaiement): ?>
                    <!-- Lien existant -->
                    <div class="input-group input-group-sm mb-2">
                        <input type="text" id="lienPaiementInput" class="form-control font-monospace"
                               value="<?php echo htmlspecialchars($lienPaiement); ?>" readonly>
                        <button class="btn btn-outline-secondary" type="button"
                                onclick="copyLienPaiement()" title="Copier le lien">
                            <i class="bi bi-clipboard" id="copyIcon"></i>
                        </button>
                    </div>
                    <?php if (!empty($decompte['token_paiement_expiration'])): ?>
                    <p class="text-muted small mb-2">
                        <i class="bi bi-clock me-1"></i>
                        Expire le <?php echo date('d/m/Y', strtotime($decompte['token_paiement_expiration'])); ?>
                    </p>
                    <?php endif; ?>
                    <!-- Send link by email -->
                    <?php if (!empty($decompte['locataire_email'])): ?>
                    <form method="POST" class="mb-1" onsubmit="return confirm('Envoyer le lien de paiement Stripe par email à ' + <?php echo json_encode(htmlspecialchars($decompte['locataire_email'])); ?> + ' ?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="envoyer_lien_paiement_email">
                        <button type="submit" class="btn btn-primary btn-sm w-100 mb-1">
                            <i class="bi bi-envelope me-1"></i>Envoyer le lien par email
                        </button>
                    </form>
                    <p class="text-muted" style="font-size:.7rem;"><i class="bi bi-info-circle me-1"></i>Un email avec le lien sera envoyé à <strong><?php echo htmlspecialchars($decompte['locataire_email']); ?></strong> (copie aux admins en BCC).</p>
                    <?php endif; ?>
                    <form method="POST" class="mb-1" onsubmit="return confirm('Régénérer un nouveau lien de paiement ?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="generer_lien_paiement">
                        <button type="submit" class="btn btn-outline-warning btn-sm w-100">
                            <i class="bi bi-arrow-clockwise me-1"></i>Régénérer le lien
                        </button>
                    </form>
                    <?php else: ?>
                    <!-- Générer le premier lien -->
                    <p class="text-muted small mb-2">Aucun lien de paiement généré.</p>
                    <form method="POST" onsubmit="return confirm('Générer un lien de paiement Stripe pour ce décompte ?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="generer_lien_paiement">
                        <button type="submit" class="btn btn-warning w-100">
                            <i class="bi bi-credit-card me-1"></i>Générer le lien Stripe
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php endif; /* not paid */ ?>
                </div>
                <?php endif; /* facture_envoyee */ ?>

                <!-- Lien vers le signalement -->
                <div class="section-card">
                    <h6 class="fw-semibold mb-2"><i class="bi bi-link-45deg me-2"></i>Signalement associé</h6>
                    <a href="signalement-detail.php?id=<?php echo $decompte['sig_id']; ?>"
                       class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-eye me-1"></i>
                        Voir le signalement <?php echo htmlspecialchars($decompte['sig_reference']); ?>
                    </a>
                </div>

                <!-- Lien vers la configuration du template -->
                <div class="section-card">
                    <h6 class="fw-semibold mb-2"><i class="bi bi-gear me-2"></i>Configuration PDF</h6>
                    <a href="decompte-configuration.php" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Configurer le template PDF
                    </a>
                </div>

            </div>
        </div>

        <?php endif; ?>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // ── Copier le lien de paiement Stripe ─────────────────────────────────────
    function copyLienPaiement() {
        var input = document.getElementById('lienPaiementInput');
        if (!input) return;
        navigator.clipboard.writeText(input.value).then(function() {
            var icon = document.getElementById('copyIcon');
            if (icon) {
                icon.className = 'bi bi-check-lg text-success';
                setTimeout(function() { icon.className = 'bi bi-clipboard'; }, 2000);
            }
        }).catch(function() {
            input.select();
            document.execCommand('copy');
        });
    }

    // ── Gestion dynamique des lignes ──────────────────────────────────────────
    function updateTotal() {
        var total = 0;
        document.querySelectorAll('.montant-input').forEach(function(inp) {
            var v = parseFloat(inp.value);
            if (!isNaN(v) && v > 0) total += v;
        });
        var el = document.getElementById('total-display');
        if (el) {
            el.textContent = total.toFixed(2).replace('.', ',') + ' €';
        }
    }

    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('montant-input')) {
            updateTotal();
        }
    });

    function deleteLigne(btn) {
        var row = btn.closest('tr');
        if (row) {
            row.remove();
            updateTotal();
        }
    }

    document.getElementById('add-ligne-btn')?.addEventListener('click', function() {
        var tbody = document.getElementById('lignes-body');
        var tr = document.createElement('tr');
        tr.className = 'ligne-row';
        tr.innerHTML = '<td><input type="text" class="form-control form-control-sm" name="intitule[]" placeholder="Intitulé..." required></td>'
            + '<td><input type="number" class="form-control form-control-sm montant-input" name="montant[]" value="0.00" min="0" max="99999" step="0.01"></td>'
            + '<td class="text-center"><button type="button" class="btn btn-sm btn-delete-ligne border" onclick="deleteLigne(this)" title="Supprimer"><i class="bi bi-trash"></i></button></td>';
        tbody.appendChild(tr);
        tr.querySelector('input[type=text]').focus();
    });

    // ── Gestion multi-fichiers intégrée au formulaire ─────────────────────────
    (function() {
        var dropZone    = document.getElementById('dropZoneDec');
        var fileInput   = document.getElementById('newFichiersInput');
        var btnBrowse   = document.getElementById('btnBrowseDec');
        var btnClear    = document.getElementById('btnClearDec');
        var preview     = document.getElementById('newFichiersPreview');
        var wrapper     = document.getElementById('new-fichiers-wrapper');
        var countEl     = document.getElementById('newFichiersCount');
        var saveBtn     = document.getElementById('btn-save-lignes');
        var form        = document.getElementById('form-lignes');

        if (!dropZone) return;

        var fileDt      = new DataTransfer();
        var ignoreChg   = false;
        var MAX_SIZE    = 50 * 1024 * 1024;

        function formatBytes(b) {
            if (b < 1024) return b + ' o';
            if (b < 1048576) return (b / 1024).toFixed(1) + ' Ko';
            return (b / 1048576).toFixed(1) + ' Mo';
        }

        function escapeHtml(s) {
            return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function refreshPreview() {
            var n = fileDt.files.length;
            if (countEl) countEl.textContent = n;
            if (wrapper) wrapper.style.display = n > 0 ? '' : 'none';
        }

        function addFiles(files) {
            // Build a Set of existing file identifiers for O(1) duplicate lookup
            var existing = new Set();
            for (var j = 0; j < fileDt.files.length; j++) {
                existing.add(fileDt.files[j].name + '|' + fileDt.files[j].size);
            }
            for (var i = 0; i < files.length; i++) {
                var f = files[i];
                if (f.size > MAX_SIZE) { alert('Fichier trop volumineux (max 50 Mo) : ' + f.name); continue; }
                var key = f.name + '|' + f.size;
                if (existing.has(key)) continue;

                fileDt.items.add(f);
                existing.add(key);
                var li = document.createElement('li');
                li.className = 'new-file-item';
                li.dataset.idx = fileDt.files.length - 1;
                li.innerHTML = '<i class="bi bi-paperclip text-muted"></i>'
                    + '<span class="new-file-name">' + escapeHtml(f.name) + '</span>'
                    + '<span class="new-file-size">' + formatBytes(f.size) + '</span>'
                    + '<button type="button" class="btn-remove-new-file" data-idx="' + (fileDt.files.length - 1) + '" title="Retirer"><i class="bi bi-x-lg"></i></button>';
                preview.appendChild(li);
            }
            refreshPreview();
        }

        function rebuildIdx() {
            preview.querySelectorAll('.new-file-item').forEach(function(li, i) {
                li.dataset.idx = i;
                var btn = li.querySelector('.btn-remove-new-file');
                if (btn) btn.dataset.idx = i;
            });
        }

        // Browse button
        btnBrowse?.addEventListener('click', function() { fileInput.click(); });
        dropZone.addEventListener('click', function(e) { if (e.target === dropZone) fileInput.click(); });

        // File input change
        fileInput?.addEventListener('change', function() {
            if (ignoreChg) return;
            if (fileInput.files.length > 0) {
                addFiles(fileInput.files);
                ignoreChg = true;
                fileInput.value = '';
                ignoreChg = false;
            }
        });

        // Drag & drop
        dropZone.addEventListener('dragover', function(e) { e.preventDefault(); dropZone.classList.add('drag-over'); });
        dropZone.addEventListener('dragleave', function() { dropZone.classList.remove('drag-over'); });
        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
            if (e.dataTransfer.files.length > 0) addFiles(e.dataTransfer.files);
        });

        // Remove individual file
        preview?.addEventListener('click', function(e) {
            var btn = e.target.closest('.btn-remove-new-file');
            if (!btn) return;
            var idx = parseInt(btn.dataset.idx, 10);
            var newDt = new DataTransfer();
            for (var i = 0; i < fileDt.files.length; i++) {
                if (i !== idx) newDt.items.add(fileDt.files[i]);
            }
            fileDt = newDt;
            var li = btn.closest('.new-file-item');
            if (li) li.remove();
            rebuildIdx();
            refreshPreview();
        });

        // Clear all new files
        btnClear?.addEventListener('click', function() {
            fileDt = new DataTransfer();
            preview.innerHTML = '';
            refreshPreview();
        });

        // On form submit: sync DataTransfer to the file input
        form?.addEventListener('submit', function() {
            try {
                ignoreChg = true;
                fileInput.files = fileDt.files;
                ignoreChg = false;
            } catch(ex) { ignoreChg = false; }
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sauvegarde…';
            }
        });
    })();

    // ── Suppression AJAX des pièces jointes déjà sauvegardées ────────────────
    (function() {
        var list       = document.getElementById('fichiers-list');
        var decompteId = <?php echo (int)($decompteId ?? 0); ?>;
        var csrfToken  = <?php echo json_encode($csrfToken); ?>;

        if (!list) return;

        function attachDeleteHandler(delBtn) {
            delBtn.addEventListener('click', function() {
                if (!confirm('Supprimer cette pièce jointe ?')) return;
                var fid = delBtn.dataset.fichierId;
                var fd  = new FormData();
                fd.append('csrf_token', delBtn.dataset.csrf || csrfToken);
                fd.append('action', 'delete_fichier');
                fd.append('fichier_id', fid);

                fetch(window.location.pathname + '?id=' + decompteId, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        var li = list.querySelector('[data-fichier-id="' + fid + '"]');
                        if (li) li.remove();
                        if (list.querySelectorAll('li[data-fichier-id]').length === 0) {
                            var emptyLi = document.createElement('li');
                            emptyLi.id = 'no-fichiers-msg';
                            emptyLi.className = 'list-group-item text-muted small';
                            emptyLi.textContent = 'Aucune pièce jointe.';
                            list.appendChild(emptyLi);
                        }
                    } else {
                        alert('Erreur : ' + (data.error || 'Impossible de supprimer.'));
                    }
                })
                .catch(function() { alert('Erreur réseau.'); });
            });
        }

        // Attach handlers to existing delete buttons
        document.querySelectorAll('.btn-delete-fichier').forEach(attachDeleteHandler);
    })();
    </script>
</body>
</html>
