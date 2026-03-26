<?php
/**
 * Templates d'emails
 * My Invest Immobilier
 */

// Define formatRevenus helper function if not already defined
// This allows mail-templates.php to work independently
if (!function_exists('formatRevenus')) {
    /**
     * Format revenus_mensuels value for display
     * @param string|null $revenus Raw enum value from database
     * @return string Formatted display value
     */
    function formatRevenus($revenus) {
        if ($revenus === null || $revenus === '') {
            return 'N/A';
        }
        
        if ($revenus === '< 2300') {
            return '< 2300 €';
        } elseif ($revenus === '2300-3000') {
            return '2300-3000 €';
        } elseif ($revenus === '3000+') {
            return '3000 € et +';
        }
        
        // Return raw value for any unexpected values
        return $revenus;
    }
}

// Charger PHPMailer
// Si installé via Composer, utiliser l'autoload standard
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// Sinon, charger manuellement (installation manuelle)
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    require_once dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src/Exception.php';
    require_once dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src/SMTP.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

/**
 * Template email d'invitation à signer le bail
 * @param string $signatureLink
 * @param array $logement
 * @param string|null $dateExpiration Date d'expiration du lien (format compatible strtotime, typically 'Y-m-d H:i:s')
 * @return array ['subject' => string, 'body' => string]
 */
function getInvitationEmailTemplate($signatureLink, $logement, $dateExpiration = null) {
    global $config;
    $subject = "Contrat de bail à signer – Action immédiate requise";
    
    // Format expiration date for email if provided
    $expirationText = '';
    if ($dateExpiration) {
        // Format: "02/02/2026 à 15:30"
        $expirationText = "\n\n⚠️ IMPORTANT : Ce lien expire le " . date('d/m/Y à H:i', strtotime($dateExpiration)) . ".";
    }
    
    $body = "Bonjour,

Merci de prendre connaissance de la procédure ci-dessous.

Procédure de signature du bail

Merci de compléter l'ensemble de la procédure avant la date d'expiration indiquée, incluant :
	1.	La signature du contrat de bail en ligne
	2.	La transmission d'une pièce d'identité en cours de validité (carte nationale d'identité ou passeport)
	3.	Le règlement immédiat du dépôt de garantie, correspondant à deux mois de loyer, par virement bancaire instantané

La prise d'effet du bail ainsi que la remise des clés interviendront uniquement après réception complète de l'ensemble des éléments ci-dessus.

À défaut de réception complète du dossier dans le délai indiqué, la réservation du logement pourra être remise en disponibilité sans autre formalité.

Pour accéder au contrat de bail : $signatureLink$expirationText

Nous restons à votre disposition en cas de question.";
    
    return [
        'subject' => $subject,
        'body' => $body
    ];
}

/**
 * Template email de finalisation (après signature)
 * @param array $contrat
 * @param array $logement
 * @param array $locataires
 * @return array ['subject' => string, 'body' => string]
 */
function getFinalisationEmailTemplate($contrat, $logement, $locataires) {
    global $config;
    $subject = "Contrat de bail – Finalisation";
    
    $depotGarantie = formatMontant($logement['depot_garantie']);
    
    $body = "Bonjour,

Nous vous remercions pour votre confiance.

Veuillez trouver ci-joint une copie du contrat de bail dûment complété.

Nous vous rappelons que :

La prise d'effet du bail intervient après le règlement immédiat du dépôt de garantie, correspondant à deux mois de loyer ($depotGarantie), par virement bancaire instantané sur le compte suivant :

My Invest Immobilier
IBAN : FR76 1027 8021 6000 0206 1834 585
BIC : CMCIFRA

Dès réception du règlement, nous vous confirmerons la prise d'effet du bail ainsi que les modalités de remise des clés.

Nous restons à votre disposition pour toute question.";
    
    return [
        'subject' => $subject,
        'body' => $body
    ];
}

/**
 * Enregistre un email dans la table email_logs
 * @param string $to Email du destinataire
 * @param string $subject Sujet de l'email
 * @param string $body Corps de l'email
 * @param string $statut 'success' ou 'error'
 * @param string|null $messageErreur Message d'erreur (si statut = 'error')
 * @param string|null $templateId Identifiant du template utilisé
 * @param string|null $contexte Contexte (ex: 'contrat_id=5')
 * @param string|null $pieceJointe Nom de la pièce jointe
 */
function logEmail($to, $subject, $body, $statut = 'success', $messageErreur = null, $templateId = null, $contexte = null, $pieceJointe = null) {
    global $pdo;
    if (!$pdo) {
        return;
    }
    try {
        $stmt = $pdo->prepare("
            INSERT INTO email_logs (destinataire, sujet, corps_html, statut, message_erreur, template_id, contexte, piece_jointe)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$to, $subject, $body, $statut, $messageErreur, $templateId, $contexte, $pieceJointe]);
    } catch (Exception $e) {
        // Silently fail – logging should never break email sending
        error_log("Erreur lors de l'enregistrement du log email: " . $e->getMessage());
    }
}

/**
 * Envoyer un email avec PHPMailer
 * @param string $to Email du destinataire
 * @param string $subject Sujet de l'email
 * @param string $body Corps de l'email (peut être HTML ou texte)
 * @param string|array|null $attachmentPath Chemin(s) vers pièce(s) jointe(s) - peut être un string ou array de ['path' => ..., 'name' => ...]
 * @param bool $isHtml Si true, le corps sera traité comme HTML (par défaut: true)
 * @param bool $isAdminEmail Si true, envoie aussi à l'adresse secondaire si configurée
 * @param string|null $replyTo Email de réponse personnalisé (optionnel)
 * @param string|null $replyToName Nom pour l'email de réponse (optionnel)
 * @param bool $addAdminBcc Si true, ajoute les administrateurs en copie cachée (BCC) - pour emails clients avec copie admin invisible
 * @param array $logContext Contexte additionnel pour les logs ['template_id' => ..., 'contexte' => ...]
 * @return bool True si l'email a été envoyé avec succès
 */
function sendEmail($to, $subject, $body, $attachmentPath = null, $isHtml = true, $isAdminEmail = false, $replyTo = null, $replyToName = null, $addAdminBcc = false, $logContext = []) {
    global $config, $pdo;

    // Override SMTP/FROM config with values stored in the parametres table (if available).
    // This allows admins to configure email sending directly from the admin UI without
    // editing files. DB values take priority over the static $config array.
    static $smtpDbLoaded = false;
    if (!$smtpDbLoaded && $pdo) {
        $smtpDbLoaded = true;
        try {
            $stmt = $pdo->prepare(
                "SELECT cle, valeur FROM parametres
                 WHERE groupe = 'email'
                   AND cle IN ('smtp_host','smtp_port','smtp_secure','smtp_username','smtp_password','mail_from','mail_from_name')"
            );
            $stmt->execute();
            $smtpMap = [
                'smtp_host'      => 'SMTP_HOST',
                'smtp_port'      => 'SMTP_PORT',
                'smtp_secure'    => 'SMTP_SECURE',
                'smtp_username'  => 'SMTP_USERNAME',
                'smtp_password'  => 'SMTP_PASSWORD',
                'mail_from'      => 'MAIL_FROM',
                'mail_from_name' => 'MAIL_FROM_NAME',
            ];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (isset($smtpMap[$row['cle']]) && $row['valeur'] !== '') {
                    $val = $row['valeur'];
                    if ($row['cle'] === 'smtp_port') {
                        $val = (int)$val;
                    }
                    $config[$smtpMap[$row['cle']]] = $val;
                }
            }
            // If credentials are set in DB, make sure SMTP_AUTH is on
            if (!empty($config['SMTP_USERNAME']) && !empty($config['SMTP_PASSWORD'])) {
                $config['SMTP_AUTH'] = true;
            }
        } catch (Exception $e) {
            error_log("Could not load SMTP config from database: " . $e->getMessage());
        }
    }

    // Validate that the recipient address is not empty and is a valid email
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("sendEmail: adresse email destinataire invalide ou vide: '$to'. L'email ne peut pas être envoyé (sujet: $subject).");
        return false;
    }

    // Validate that the sender address is configured (required in all modes)
    if (empty($config['MAIL_FROM'])) {
        error_log("ERREUR: L'adresse email expéditeur (mail_from) n'est pas configurée. "
                . "Configurez-la dans Admin > Paramètres (groupe Email). L'email à $to ne peut pas être envoyé.");
        return false;
    }

    // Validate SMTP configuration if SMTP auth is enabled
    if ($config['SMTP_AUTH']) {
        if (empty($config['SMTP_PASSWORD']) || empty($config['SMTP_USERNAME']) || empty($config['SMTP_HOST'])) {
            error_log("ERREUR CRITIQUE: Configuration SMTP incomplète. Password: " . (empty($config['SMTP_PASSWORD']) ? 'VIDE' : 'défini') . 
                     ", Username: " . (empty($config['SMTP_USERNAME']) ? 'VIDE' : 'défini') . 
                     ", Host: " . (empty($config['SMTP_HOST']) ? 'VIDE' : 'défini'));
            error_log("L'email à $to ne peut pas être envoyé. Veuillez configurer les paramètres SMTP dans l'interface Admin > Paramètres.");
            return false;
        }
    }
    
    $mail = new PHPMailer(true);
    
    try {
        // Configuration du serveur SMTP
        if ($config['SMTP_AUTH']) {
            $mail->isSMTP();
            $mail->Host       = $config['SMTP_HOST'];
            $mail->SMTPAuth   = $config['SMTP_AUTH'];
            $mail->Username   = $config['SMTP_USERNAME'];
            $mail->Password   = $config['SMTP_PASSWORD'];
            $mail->SMTPSecure = $config['SMTP_SECURE'];
            $mail->Port       = $config['SMTP_PORT'];
            $mail->SMTPDebug  = $config['SMTP_DEBUG'];
        }
        
        // Encodage
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        
        // Expéditeur
        $mail->setFrom($config['MAIL_FROM'], $config['MAIL_FROM_NAME']);
        
        // Email de réponse personnalisé ou par défaut
        if ($replyTo) {
            $mail->addReplyTo($replyTo, $replyToName ?: $replyTo);
        } else {
            $mail->addReplyTo($config['MAIL_FROM'], $config['MAIL_FROM_NAME']);
        }
        
        // Destinataire principal
        $mail->addAddress($to);
        
        // Normaliser l'adresse principale pour éviter les doublons en BCC
        $toNormalized = strtolower($to);

        // Ajouter les administrateurs en BCC si c'est un email admin OU si addAdminBcc est activé
        // On suit les adresses déjà ajoutées pour éviter tout doublon BCC.
        $bccAdded = [$toNormalized => true]; // Le destinataire principal ne doit jamais être en BCC
        if (($isAdminEmail || $addAdminBcc) && $pdo) {
            try {
                $stmt = $pdo->prepare("SELECT email FROM administrateurs WHERE actif = TRUE");
                $stmt->execute();
                $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($admins as $admin) {
                    $adminEmailNorm = strtolower($admin['email'] ?? '');
                    if (!empty($adminEmailNorm) && filter_var($adminEmailNorm, FILTER_VALIDATE_EMAIL) && !isset($bccAdded[$adminEmailNorm])) {
                        $mail->addBCC($admin['email']);
                        $bccAdded[$adminEmailNorm] = true;
                    }
                }
            } catch (Exception $e) {
                error_log("Could not fetch admin emails for BCC: " . $e->getMessage());
            }
        }
        
        // Si c'est un email admin et qu'une adresse secondaire est configurée
        $secondaryNorm = strtolower($config['ADMIN_EMAIL_SECONDARY'] ?? '');
        if ($isAdminEmail && !empty($secondaryNorm) && !isset($bccAdded[$secondaryNorm])) {
            $mail->addBCC($config['ADMIN_EMAIL_SECONDARY']);
            $bccAdded[$secondaryNorm] = true;
        }
        
        // Ajouter BCC pour l'adresse BCC admin si c'est un email admin OU si addAdminBcc est activé
        $bccAdminNorm = strtolower($config['ADMIN_EMAIL_BCC'] ?? '');
        if (($isAdminEmail || $addAdminBcc) && !empty($bccAdminNorm) && !isset($bccAdded[$bccAdminNorm])) {
            $mail->addBCC($config['ADMIN_EMAIL_BCC']);
            $bccAdded[$bccAdminNorm] = true;
        }
        
        // Replace {{signature}} placeholder if present in body
        $finalBody = $body;
        if ($isHtml && strpos($body, '{{signature}}') !== false) {
            // Get email signature from parametres (with caching)
            static $signatureCache = null;
            if ($pdo && $signatureCache === null) {
                try {
                    $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'email_signature' LIMIT 1");
                    $stmt->execute();
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $signatureCache = ($result && !empty($result['valeur'])) ? $result['valeur'] : '';
                } catch (Exception $e) {
                    // Silently fail if parametres table doesn't exist yet
                    error_log("Could not fetch email signature: " . $e->getMessage());
                    $signatureCache = '';
                }
            }
            $signature = $signatureCache !== null ? $signatureCache : '';
            $finalBody = str_replace('{{signature}}', $signature, $finalBody);
        }

        // Replace {{company}} placeholder if still present (not already replaced by template variables)
        if (strpos($finalBody, '{{company}}') !== false) {
            static $companyCache = null;
            if ($pdo && $companyCache === null) {
                try {
                    $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'company_name' LIMIT 1");
                    $stmt->execute();
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($result && !empty($result['valeur'])) {
                        $companyCache = $result['valeur'];
                    } else {
                        global $config;
                        $companyCache = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';
                    }
                } catch (Exception $e) {
                    global $config;
                    $companyCache = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';
                }
            }
            $companyName = $companyCache ?? 'My Invest Immobilier';
            $finalBody = str_replace('{{company}}', htmlspecialchars($companyName), $finalBody);
            $subject   = str_replace('{{company}}', htmlspecialchars($companyName), $subject);
        }
        
        // Contenu
        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        $mail->Body    = $finalBody;
        
        // Si le contenu est HTML, générer une version texte alternative
        if ($isHtml) {
            $mail->AltBody = strip_tags($finalBody);
        }
        
        // Pièces jointes - supporter un seul fichier ou un array de fichiers
        if ($attachmentPath) {
            if (is_array($attachmentPath)) {
                foreach ($attachmentPath as $attachment) {
                    if (is_string($attachment) && file_exists($attachment)) {
                        // Simple chemin de fichier
                        $mail->addAttachment($attachment);
                    } elseif (is_array($attachment) && !empty($attachment['path']) && file_exists($attachment['path'])) {
                        // Array avec path et name optionnel
                        $mail->addAttachment($attachment['path'], $attachment['name'] ?? '');
                    }
                }
            } elseif (is_string($attachmentPath) && file_exists($attachmentPath)) {
                // Un seul fichier (backward compatibility)
                $mail->addAttachment($attachmentPath);
            }
        }
        
        // Envoyer l'email
        $result = $mail->send();
        
        // Logger le succès seulement si vraiment envoyé
        if ($result) {
            error_log("Email envoyé avec succès à: $to - Sujet: $subject");
            // Enregistrer dans les logs email
            // Store relative path from project root so the tracker can serve the file
            $projectRoot = realpath(dirname(__DIR__));
            $pieceJointeNom = null;
            if ($attachmentPath) {
                $toRelative = function($absPath) use ($projectRoot) {
                    $resolved = realpath($absPath) ?: $absPath;
                    if ($projectRoot && strpos($resolved, $projectRoot . DIRECTORY_SEPARATOR) === 0) {
                        return DIRECTORY_SEPARATOR . str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen($projectRoot) + 1));
                    }
                    return DIRECTORY_SEPARATOR . str_replace(DIRECTORY_SEPARATOR, '/', basename($resolved));
                };
                if (is_array($attachmentPath)) {
                    $noms = [];
                    foreach ($attachmentPath as $att) {
                        if (is_array($att)) {
                            $path = $att['path'] ?? '';
                            $noms[] = $path ? $toRelative($path) : ($att['name'] ?? '');
                        } else {
                            $noms[] = $toRelative($att);
                        }
                    }
                    $pieceJointeNom = implode(', ', array_filter($noms));
                } else {
                    $pieceJointeNom = $toRelative($attachmentPath);
                }
            }
            logEmail($to, $subject, $finalBody, 'success', null,
                $logContext['template_id'] ?? null,
                $logContext['contexte'] ?? null,
                $pieceJointeNom ?: null);
        } else {
            error_log("Échec de l'envoi d'email à: $to - Sujet: $subject (mail->send() returned false)");
            logEmail($to, $subject, $finalBody, 'error', 'mail->send() returned false',
                $logContext['template_id'] ?? null,
                $logContext['contexte'] ?? null);
        }
        
        return $result;
        
    } catch (Exception $e) {
        // Logger l'erreur avec contexte approprié
        if ($mail instanceof PHPMailer) {
            error_log("Erreur PHPMailer lors de l'envoi à $to: {$mail->ErrorInfo}");
        }
        error_log("Exception lors de l'envoi d'email à $to: " . $e->getMessage());
        $errMsg = ($mail instanceof PHPMailer) ? $mail->ErrorInfo : $e->getMessage();
        logEmail($to, $subject, $body, 'error', $errMsg,
            $logContext['template_id'] ?? null,
            $logContext['contexte'] ?? null);
        
        // Ne PAS utiliser le fallback mail() quand SMTP est configuré OU en mode CLI (cron) :
        // Le serveur SMTP peut avoir accepté l'email avant de lever l'exception (ex: déconnexion
        // OVH après livraison). Appeler sendEmailFallback() enverrait un doublon au destinataire.
        if ($config['SMTP_AUTH'] || !empty($config['SMTP_HOST']) || php_sapi_name() === 'cli') {
            error_log("ATTENTION: Pas de fallback (SMTP configuré ou mode CLI). L'email n'a peut-être pas été envoyé à $to.");
            return false;
        }
        
        // Fallback avec mail() natif uniquement si aucun SMTP n'est configuré et pas en mode CLI
        error_log("Tentative de fallback avec mail() natif...");
        return sendEmailFallback($to, $subject, $body, $attachmentPath, $isHtml);
    }
}

/**
 * Fonction de fallback utilisant mail() natif de PHP
 * Utilisée si PHPMailer échoue
 */
function sendEmailFallback($to, $subject, $body, $attachmentPath = null, $isHtml = true) {
    global $config, $pdo;
    try {
        // Replace {{signature}} placeholder if present in body
        $finalBody = $body;
        if ($isHtml && strpos($body, '{{signature}}') !== false) {
            // Get email signature from parametres (with caching)
            static $signatureFallbackCache = null;
            if ($pdo && $signatureFallbackCache === null) {
                try {
                    $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'email_signature' LIMIT 1");
                    $stmt->execute();
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $signatureFallbackCache = ($result && !empty($result['valeur'])) ? $result['valeur'] : '';
                } catch (Exception $e) {
                    // Silently fail if parametres table doesn't exist yet
                    error_log("Could not fetch email signature in fallback: " . $e->getMessage());
                    $signatureFallbackCache = '';
                }
            }
            $signature = $signatureFallbackCache !== null ? $signatureFallbackCache : '';
            $finalBody = str_replace('{{signature}}', $signature, $finalBody);
        }

        // Replace {{company}} placeholder if still present
        if (strpos($finalBody, '{{company}}') !== false) {
            static $companyFallbackCache = null;
            if ($pdo && $companyFallbackCache === null) {
                try {
                    $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'company_name' LIMIT 1");
                    $stmt->execute();
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $companyFallbackCache = ($result && !empty($result['valeur'])) ? $result['valeur'] : ($config['COMPANY_NAME'] ?? 'My Invest Immobilier');
                } catch (Exception $e) {
                    $companyFallbackCache = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';
                }
            }
            $finalBody = str_replace('{{company}}', htmlspecialchars($companyFallbackCache ?? 'My Invest Immobilier'), $finalBody);
            $subject   = str_replace('{{company}}', htmlspecialchars($companyFallbackCache ?? 'My Invest Immobilier'), $subject);
        }
        
        if ($attachmentPath && file_exists($attachmentPath)) {
            // Email avec pièce jointe
            $boundary = md5(time());
            $headers = "From: " . $config['MAIL_FROM_NAME'] . " <" . $config['MAIL_FROM'] . ">\r\n";
            $headers .= "Reply-To: " . $config['MAIL_FROM'] . "\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
            
            $contentType = $isHtml ? "text/html" : "text/plain";
            $message = "--$boundary\r\n";
            $message .= "Content-Type: $contentType; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $message .= $finalBody . "\r\n\r\n";
            
            // Pièce jointe
            $filename = basename($attachmentPath);
            $fileContent = chunk_split(base64_encode(file_get_contents($attachmentPath)));
            $message .= "--$boundary\r\n";
            $message .= "Content-Type: application/pdf; name=\"$filename\"\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= "Content-Disposition: attachment; filename=\"$filename\"\r\n\r\n";
            $message .= $fileContent . "\r\n";
            $message .= "--$boundary--";
            
            $result = mail($to, $subject, $message, $headers);
        } else {
            // Email simple
            $headers = "From: " . $config['MAIL_FROM_NAME'] . " <" . $config['MAIL_FROM'] . ">\r\n";
            $headers .= "Reply-To: " . $config['MAIL_FROM'] . "\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
            
            if ($isHtml) {
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            } else {
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            }
            
            $result = mail($to, $subject, $finalBody, $headers);
        }
        
        if (!$result) {
            error_log("Fallback mail() a échoué pour l'envoi à $to");
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Exception dans fallback mail(): " . $e->getMessage());
        return false;
    }
}

/**
 * Template HTML pour l'email de candidature reçue
 * @param string $prenom Prénom du candidat
 * @param string $nom Nom du candidat
 * @param array $logement Informations du logement
 * @param int $uploaded_count Nombre de documents uploadés
 * @return string HTML de l'email
 */
function getCandidatureRecueEmailHTML($prenom, $nom, $logement, $uploaded_count) {
    global $config;
    $html = '
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0;">
    <div style="max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; padding: 30px 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px;">✓ Candidature Reçue</h1>
        </div>
        <div style="padding: 30px 20px;">
            <p>Bonjour <strong>' . htmlspecialchars($prenom . ' ' . $nom) . '</strong>,</p>
            
            <p>Nous avons bien reçu votre candidature pour le logement <strong>' . htmlspecialchars($logement['reference']) . '</strong>.</p>
            
            <div style="background: #f8f9fa; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <h3 style="margin-top: 0; color: #667eea;">📋 Informations de votre candidature</h3>
                <div style="margin: 10px 0;"><strong style="color: #555;">Logement :</strong> ' . htmlspecialchars($logement['reference']) . ' - ' . htmlspecialchars($logement['type']) . '</div>
                <div style="margin: 10px 0;"><strong style="color: #555;">Adresse :</strong> ' . htmlspecialchars($logement['adresse']) . '</div>
                <div style="margin: 10px 0;"><strong style="color: #555;">Loyer :</strong> ' . htmlspecialchars($logement['loyer']) . ' €/mois</div>
                <div style="margin: 10px 0;"><strong style="color: #555;">Documents joints :</strong> ' . $uploaded_count . ' pièce(s) justificative(s)</div>
            </div>
            
            <p>Il est actuellement en cours d\'étude. Une réponse vous sera apportée entre 1 et 4 jours ouvrés.</p>
        </div>
        <div style="background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666;">
            <p>© ' . date('Y') . ' My Invest Immobilier - Tous droits réservés</p>
            <p>Cet email a été envoyé automatiquement, merci de ne pas y répondre directement.</p>
        </div>
    </div>
</body>
</html>';
    
    return $html;
}

/**
 * Template HTML pour l'email d'invitation à signer le bail
 * @param string $signatureLink Lien de signature
 * @param string $adresse Adresse du logement
 * @param int $nb_locataires Nombre de locataires
 * @return string HTML de l'email
 */
function getInvitationSignatureEmailHTML($signatureLink, $adresse, $nb_locataires) {
    global $config;
    $html = '
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0;">
    <div style="max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; padding: 30px 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px;">📝 Contrat de Bail à Signer</h1>
        </div>
        <div style="padding: 30px 20px;">
            <p>Bonjour,</p>
            
            <p>Merci de prendre connaissance de la procédure ci-dessous.</p>
            
            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <strong>⏰ Action immédiate requise</strong><br>
                Délai de 24 heures à compter de la réception de ce message
            </div>
            
            <h3>📋 Procédure de signature du bail</h3>
            <p>Merci de compléter l\'ensemble de la procédure dans un délai de 24 heures, incluant :</p>
            <ol>
                <li><strong>La signature du contrat de bail en ligne</strong></li>
                <li><strong>La transmission d\'une pièce d\'identité</strong> en cours de validité (CNI ou passeport)</li>
                <li><strong>Le règlement du dépôt de garantie</strong> (2 mois de loyer) par virement bancaire instantané</li>
            </ol>
            
            <div style="background: #f8f9fa; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <p style="margin: 0;"><strong>Important :</strong></p>
                <ul style="margin: 10px 0 0 0;">
                    <li>La prise d\'effet du bail et la remise des clés interviendront uniquement après réception complète de l\'ensemble des éléments</li>
                    <li>À défaut de réception complète du dossier dans le délai indiqué, la réservation du logement pourra être remise en disponibilité sans autre formalité</li>
                </ul>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . htmlspecialchars($signatureLink) . '" style="display: inline-block; padding: 15px 30px; background: #667eea; color: #ffffff; text-decoration: none; border-radius: 4px; margin: 20px 0; font-weight: bold;">🖊️ Accéder au Contrat de Bail</a>
            </div>
            
            <p>Nous restons à votre disposition en cas de question.</p>
        </div>
        <div style="background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666;">
            <p>© ' . date('Y') . ' My Invest Immobilier - Tous droits réservés</p>
        </div>
    </div>
</body>
</html>';
    
    return $html;
}

/**
 * Template HTML pour les emails de changement de statut
 * @param string $nom_complet Nom complet du candidat
 * @param string $statut Nouveau statut
 * @param string $commentaire Commentaire optionnel
 * @return string HTML de l'email
 */
function getStatusChangeEmailHTML($nom_complet, $statut, $commentaire = '') {
    global $config;
    $title = '';
    $message = '';
    $color = '#667eea';
    
    switch ($statut) {
        case 'Accepté':
        case 'Accepte':
        case 'accepte':
            $title = 'Suite à votre candidature';
            $message = 'Nous vous remercions pour l\'intérêt que vous portez à notre logement et pour votre candidature.';
            $message .= '<br><br>Après une première analyse de votre dossier, nous avons le plaisir de vous informer qu\'il a été retenu pour la suite du processus.<br>';
            $message .= 'Nous reviendrons vers vous prochainement afin de convenir ensemble d\'une date de visite.';
            $message .= '<br><br>Nous vous remercions encore pour votre démarche et restons à votre disposition pour toute information complémentaire.';
            break;
            
        case 'Refusé':
        case 'Refuse':
        case 'refuse':
            $title = 'Réponse à votre candidature';
            $message = 'Nous vous remercions pour l\'intérêt que vous portez à notre logement et pour le temps consacré à votre candidature.';
            $message .= '<br><br>Après étude de l\'ensemble des dossiers reçus, nous vous informons que nous ne donnerons pas suite à votre demande pour ce logement.';
            $message .= '<br><br>Nous vous remercions pour votre démarche et vous souhaitons pleine réussite dans vos recherches.';
            break;
            
        case 'Refus après visite':
        case 'Visite planifiée':
            $title = 'Réponse à votre candidature';
            $message = 'Nous vous remercions d\'avoir visité le logement et du temps que vous nous avez consacré.';
            $message .= '<br><br>Après examen de votre dossier, nous sommes au regret de vous informer que nous ne sommes pas en mesure de donner suite à votre candidature.';
            $color = '#dc3545';
            break;
            
        case 'Contrat envoyé':
            $title = '📄 Contrat de Bail';
            $message = 'Votre contrat de bail est prêt.';
            $message .= '<br><br>Vous allez recevoir un lien pour le signer électroniquement.';
            $color = '#ffc107';
            break;
            
        case 'Contrat signé':
            $title = '✓ Contrat Signé';
            $message = 'Nous avons bien reçu votre contrat signé.';
            $message .= '<br><br>Nous vous contacterons prochainement pour les modalités d\'entrée dans le logement.';
            $color = '#28a745';
            break;
            
        default:
            $title = 'Mise à jour de votre candidature';
            $message = 'Votre candidature a été mise à jour.';
    }
    
    $html = '
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0;">
    <div style="max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; padding: 30px 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 28px;">My Invest Immobilier</h1>
        </div>
        <div style="padding: 30px;">
            <p style="margin: 15px 0;">Bonjour,</p>
            
            <p style="margin: 15px 0;">' . $message . '</p>';
    
    if ($commentaire) {
        $html .= '
            <p style="margin: 15px 0;"><strong>Note :</strong> ' . nl2br(htmlspecialchars($commentaire)) . '</p>';
    }
    
    $html .= '
        </div>
        <div style="background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #e9ecef;">
            <p>My Invest Immobilier - Gestion locative professionnelle</p>
        </div>
    </div>
</body>
</html>';
    
    return $html;
}

/**
 * Envoyer un email aux administrateurs (emails de config + tous les administrateurs actifs de la base de données)
 * @param string $subject Sujet de l'email
 * @param string $body Corps de l'email (peut être HTML ou texte)
 * @param string|array|null $attachmentPath Chemin(s) vers pièce(s) jointe(s) - peut être un string ou array
 * @param bool $isHtml Si true, le corps sera traité comme HTML (par défaut: true)
 * @param string|null $replyTo Email de réponse personnalisé (optionnel)
 * @param string|null $replyToName Nom pour l'email de réponse (optionnel)
 * @return array ['success' => bool, 'sent_to' => array, 'errors' => array]
 */
function sendEmailToAdmins($subject, $body, $attachmentPath = null, $isHtml = true, $replyTo = null, $replyToName = null, $templateVariables = null) {
    global $config, $pdo;
    
    $results = [
        'success' => false,
        'sent_to' => [],
        'errors' => []
    ];
    
    // If templateVariables is provided, use template-based email
    if ($templateVariables !== null) {
        // Use the admin_nouvelle_candidature template
        $template = getEmailTemplate('admin_nouvelle_candidature');
        if ($template) {
            // Replace variables in subject and body
            $subject = replaceTemplateVariables($template['sujet'], $templateVariables);
            $body = replaceTemplateVariables($template['corps_html'], $templateVariables);
        } else {
            error_log("Warning: admin_nouvelle_candidature template not found, falling back to provided body");
        }
    }
    
    // Liste des emails administrateurs (use associative array for O(1) duplicate checking)
    $adminEmailsMap = [];
    
    // Email principal - use parameter or fallback to config
    $adminEmail = getAdminEmail();
    if (!empty($adminEmail)) {
        // Validate email format
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $adminEmailsMap[$adminEmail] = true;
        } else {
            $results['errors'][] = "Invalid ADMIN_EMAIL format: " . $adminEmail;
            error_log("Invalid ADMIN_EMAIL configured: " . $adminEmail);
        }
    }
    
    // Email secondaire (si configuré)
    if (!empty($config['ADMIN_EMAIL_SECONDARY'])) {
        // Validate email format
        if (filter_var($config['ADMIN_EMAIL_SECONDARY'], FILTER_VALIDATE_EMAIL)) {
            $adminEmailsMap[$config['ADMIN_EMAIL_SECONDARY']] = true;
        } else {
            $results['errors'][] = "Invalid ADMIN_EMAIL_SECONDARY format: " . $config['ADMIN_EMAIL_SECONDARY'];
            error_log("Invalid ADMIN_EMAIL_SECONDARY configured: " . $config['ADMIN_EMAIL_SECONDARY']);
        }
    }
    
    // Récupérer tous les emails des administrateurs actifs depuis la base de données
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT email FROM administrateurs WHERE actif = TRUE AND email IS NOT NULL AND email != ''");
            $adminUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($adminUsers as $adminUserEmail) {
                // Validate email format and avoid duplicates using O(1) lookup
                if (filter_var($adminUserEmail, FILTER_VALIDATE_EMAIL) && !isset($adminEmailsMap[$adminUserEmail])) {
                    $adminEmailsMap[$adminUserEmail] = true;
                }
            }
        } catch (Exception $e) {
            error_log("Could not fetch administrators emails: " . $e->getMessage());
        }
    }
    
    // Si aucun email admin configuré, utiliser l'email de la société
    if (empty($adminEmailsMap) && !empty($config['COMPANY_EMAIL'])) {
        if (filter_var($config['COMPANY_EMAIL'], FILTER_VALIDATE_EMAIL)) {
            $adminEmailsMap[$config['COMPANY_EMAIL']] = true;
        }
    }
    
    // Convert to array for iteration
    $adminEmails = array_keys($adminEmailsMap);
    
    // Count configured emails for partial success detection
    $totalConfigured = count($adminEmails);
    
    // Envoyer à chaque administrateur
    foreach ($adminEmails as $adminEmail) {
        try {
            $sent = sendEmail($adminEmail, $subject, $body, $attachmentPath, $isHtml, false, $replyTo, $replyToName);
            if ($sent) {
                $results['sent_to'][] = $adminEmail;
                $results['success'] = true; // Au moins un email envoyé
            } else {
                $results['errors'][] = "Échec d'envoi à $adminEmail";
            }
        } catch (Exception $e) {
            $results['errors'][] = "Exception lors de l'envoi à $adminEmail: " . $e->getMessage();
            error_log("Erreur sendEmailToAdmins pour $adminEmail: " . $e->getMessage());
        }
    }
    
    // Log warning if partial success (some but not all emails sent)
    if ($results['success'] && count($results['sent_to']) < $totalConfigured) {
        $partialMessage = "Partial success: " . count($results['sent_to']) . " of $totalConfigured admin emails sent";
        error_log("WARNING: $partialMessage");
        $results['partial_success'] = true;
    }
    
    return $results;
}

/**
 * Template HTML pour notification admin - Nouvelle candidature reçue
 * @param array $candidature Données de la candidature (doit inclure 'response_token')
 * @param array $logement Informations du logement
 * @param int $nb_documents Nombre de documents uploadés
 * @return string HTML de l'email
 */
function getAdminNewCandidatureEmailHTML($candidature, $logement, $nb_documents) {
    global $config;
    
    // Générer les liens de réponse si un token est fourni
    $responseLinksHtml = '';
    if (!empty($candidature['response_token'])) {
        $baseUrl = !empty($config['SITE_URL']) ? $config['SITE_URL'] : 'https://www.myinvest-immobilier.com';
        $linkPositive = $baseUrl . '/candidature/reponse-candidature.php?token=' . urlencode($candidature['response_token']) . '&action=positive';
        $linkNegative = $baseUrl . '/candidature/reponse-candidature.php?token=' . urlencode($candidature['response_token']) . '&action=negative';
        
        $responseLinksHtml = '
            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <h3 style="margin-top: 0; color: #856404; font-size: 16px;">⚡ Actions Rapides</h3>
                <div style="text-align: center; margin: 15px 0;">
                    <a href="' . htmlspecialchars($linkPositive) . '" style="display: inline-block; padding: 12px 24px; background: #28a745; color: #ffffff; text-decoration: none; border-radius: 4px; margin: 5px;">
                        ✓ Accepter la candidature
                    </a>
                    <a href="' . htmlspecialchars($linkNegative) . '" style="display: inline-block; padding: 12px 24px; background: #dc3545; color: #ffffff; text-decoration: none; border-radius: 4px; margin: 5px;">
                        ✗ Refuser la candidature
                    </a>
                </div>
            </div>';
    }
    
    $html = '
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0;">
    <div style="max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: #ffffff; padding: 30px 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px;">🔔 Nouvelle Candidature Reçue</h1>
        </div>
        <div style="padding: 30px 20px;">
            <p><strong>Une nouvelle candidature vient d\'être soumise.</strong></p>
            
            <div style="background: #f8f9fa; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <h3 style="margin-top: 0; color: #28a745; font-size: 16px;">👤 Informations du Candidat</h3>
                <div style="margin: 8px 0;"><strong style="color: #555; display: inline-block; width: 180px;">Nom :</strong> ' . htmlspecialchars($candidature['nom']) . ' ' . htmlspecialchars($candidature['prenom']) . '</div>
                <div style="margin: 8px 0;"><strong style="color: #555; display: inline-block; width: 180px;">Email :</strong> <a href="mailto:' . htmlspecialchars($candidature['email']) . '">' . htmlspecialchars($candidature['email']) . '</a></div>
                <div style="margin: 8px 0;"><strong style="color: #555; display: inline-block; width: 180px;">Téléphone :</strong> ' . htmlspecialchars($candidature['telephone']) . '</div>
                <div style="margin: 8px 0;"><strong style="color: #555; display: inline-block; width: 180px;">Référence :</strong> ' . htmlspecialchars($candidature['reference']) . '</div>
            </div>
            
            <div style="background: #f8f9fa; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <h3 style="margin-top: 0; color: #28a745; font-size: 16px;">🏠 Logement</h3>
                <div style="margin: 8px 0;"><strong style="color: #555; display: inline-block; width: 180px;">Référence :</strong> ' . htmlspecialchars($logement['reference']) . '</div>
                <div style="margin: 8px 0;"><strong style="color: #555; display: inline-block; width: 180px;">Type :</strong> ' . htmlspecialchars($logement['type']) . '</div>
                <div style="margin: 8px 0;"><strong style="color: #555; display: inline-block; width: 180px;">Adresse :</strong> ' . htmlspecialchars($logement['adresse']) . '</div>
                <div style="margin: 8px 0;"><strong style="color: #555; display: inline-block; width: 180px;">Loyer :</strong> ' . htmlspecialchars($logement['loyer']) . ' €/mois</div>
            </div>
            
            <div style="background: #f8f9fa; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <h3 style="margin-top: 0; color: #28a745; font-size: 16px;">💼 Situation Professionnelle</h3>
                <div style="margin: 8px 0;"><strong style="color: #555; display: inline-block; width: 180px;">Statut :</strong> ' . htmlspecialchars($candidature['statut_professionnel']) . '</div>
                <div style="margin: 8px 0;"><strong style="color: #555; display: inline-block; width: 180px;">Période d\'essai :</strong> ' . htmlspecialchars($candidature['periode_essai']) . '</div>
                <div style="margin: 8px 0;"><strong style="color: #555; display: inline-block; width: 180px;">Revenus mensuels :</strong> ' . formatRevenus($candidature['revenus_mensuels'] ?? null) . '</div>
                <div style="margin: 8px 0;"><strong style="color: #555; display: inline-block; width: 180px;">Type de revenus :</strong> ' . htmlspecialchars($candidature['type_revenus']) . '</div>
            </div>
            
            <div style="background: #f8f9fa; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <h3 style="margin-top: 0; color: #28a745; font-size: 16px;">📎 Documents</h3>
                <div style="margin: 8px 0;"><strong style="color: #555; display: inline-block; width: 180px;">Nombre de pièces :</strong> ' . $nb_documents . ' document(s)</div>
            </div>
            
            ' . $responseLinksHtml . '
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . $config['SITE_URL'] . '/admin-v2/candidature-detail.php?id=' . $candidature['id'] . '" style="display: inline-block; padding: 12px 24px; background: #28a745; color: #ffffff; text-decoration: none; border-radius: 4px; margin: 20px 0;">
                    Voir la Candidature
                </a>
            </div>
        </div>
        <div style="background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666;">
            <p>© ' . date('Y') . ' My Invest Immobilier - Système de Gestion des Candidatures</p>
        </div>
    </div>
</body>
</html>';
    
    return $html;
}
