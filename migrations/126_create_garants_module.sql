-- Migration 126: Module Garant / Garantie
-- Crée la table garants, ajoute token_garantie à contrats, et insère les templates d'emails.

-- ======================================================
-- 1. Ajouter token_garantie à la table contrats
-- ======================================================
ALTER TABLE contrats
    ADD COLUMN IF NOT EXISTS token_garantie VARCHAR(100) NULL AFTER token_signature;

-- ======================================================
-- 2. Table garants
-- ======================================================
CREATE TABLE IF NOT EXISTS garants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contrat_id INT NOT NULL,

    -- Type de garantie
    type_garantie ENUM('visale', 'caution_solidaire') NOT NULL,

    -- Statut du processus garant
    statut ENUM('en_attente_garant', 'engage', 'signe', 'documents_recus') DEFAULT 'en_attente_garant',

    -- Token unique pour le lien garant
    token_garant VARCHAR(100) UNIQUE NOT NULL,

    -- ---- Champs Visale ----
    numero_visale VARCHAR(100) NULL,
    document_visale VARCHAR(255) NULL,

    -- ---- Champs Caution solidaire ----
    nom VARCHAR(100) NULL,
    prenom VARCHAR(100) NULL,
    date_naissance DATE NULL,
    email VARCHAR(255) NULL,
    telephone VARCHAR(30) NULL,
    adresse TEXT NULL,
    ville VARCHAR(100) NULL,
    code_postal VARCHAR(10) NULL,

    -- Signature électronique garant
    signature_data LONGTEXT NULL,
    signature_ip VARCHAR(45) NULL,
    signature_timestamp TIMESTAMP NULL,
    certifie_exact TINYINT(1) DEFAULT 0,

    -- Document de caution solidaire généré
    document_caution VARCHAR(255) NULL,

    -- Pièce d'identité garant
    piece_identite VARCHAR(255) NULL,

    -- Horodatages étapes
    date_envoi_invitation TIMESTAMP NULL,
    date_engagement TIMESTAMP NULL,
    date_signature TIMESTAMP NULL,
    date_documents TIMESTAMP NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (contrat_id) REFERENCES contrats(id) ON DELETE CASCADE,
    INDEX idx_contrat (contrat_id),
    INDEX idx_token (token_garant),
    INDEX idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- 3. Templates d'emails pour le module garant
-- ======================================================

-- 3a. Invitation envoyée au garant (avec lien vers son parcours)
INSERT INTO email_templates (identifiant, nom, description, sujet, corps_html, variables_disponibles, actif)
VALUES (
    'garant_invitation',
    'Invitation au garant (caution solidaire)',
    'Email envoyé au garant avec le lien vers son parcours de validation',
    'Demande de caution solidaire – {{prenom_locataire}} {{nom_locataire}}',
    '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Demande de caution solidaire</title></head>
<body style="font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:0;">
<div style="max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);">
  <div style="background:#2c3e50;padding:25px 30px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;">{{company}}</h1>
    <p style="color:#bdc3c7;margin:8px 0 0;">Demande de caution solidaire</p>
  </div>
  <div style="padding:30px;">
    <p>Bonjour <strong>{{prenom_garant}} {{nom_garant}}</strong>,</p>
    <p>{{prenom_locataire}} {{nom_locataire}} vous a désigné(e) comme garant(e) pour la location du logement suivant :</p>
    <div style="background:#ecf0f1;border-left:4px solid #3498db;padding:15px;margin:20px 0;border-radius:4px;">
      <p style="margin:0;"><strong>Logement :</strong> {{adresse_logement}}</p>
      <p style="margin:8px 0 0;"><strong>Locataire :</strong> {{prenom_locataire}} {{nom_locataire}}</p>
    </div>
    <p>Pour finaliser votre engagement, veuillez cliquer sur le bouton ci-dessous et suivre les étapes du formulaire :</p>
    <div style="text-align:center;margin:30px 0;">
      <a href="{{lien_garant}}" style="background:#3498db;color:#fff;padding:14px 32px;border-radius:6px;text-decoration:none;font-size:16px;font-weight:bold;">
        Accéder à mon espace garant →
      </a>
    </div>
    <p style="color:#e74c3c;font-size:14px;"><strong>⚠️ Ce lien est personnel et sécurisé. Ne le partagez pas.</strong></p>
    <p>Si vous avez des questions, contactez-nous à <a href="mailto:{{email_contact}}">{{email_contact}}</a>.</p>
  </div>
  <div style="background:#ecf0f1;padding:15px 30px;text-align:center;font-size:12px;color:#7f8c8d;">
    <p style="margin:0;">{{company}} – Email confidentiel</p>
  </div>
</div>
</body></html>',
    JSON_ARRAY('prenom_garant', 'nom_garant', 'prenom_locataire', 'nom_locataire', 'adresse_logement', 'lien_garant', 'email_contact', 'company'),
    1
)
ON DUPLICATE KEY UPDATE
    nom = VALUES(nom),
    description = VALUES(description),
    sujet = VALUES(sujet),
    corps_html = VALUES(corps_html),
    variables_disponibles = VALUES(variables_disponibles);

-- 3b. Confirmation envoyée au locataire (demande transmise au garant)
INSERT INTO email_templates (identifiant, nom, description, sujet, corps_html, variables_disponibles, actif)
VALUES (
    'garant_confirmation_locataire',
    'Confirmation locataire – demande garant envoyée',
    'Email envoyé au locataire pour confirmer que la demande a été transmise au garant',
    'Votre demande de garant a été envoyée',
    '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Demande de garant envoyée</title></head>
<body style="font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:0;">
<div style="max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);">
  <div style="background:#2c3e50;padding:25px 30px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;">{{company}}</h1>
    <p style="color:#bdc3c7;margin:8px 0 0;">Confirmation de demande</p>
  </div>
  <div style="padding:30px;">
    <p>Bonjour <strong>{{prenom_locataire}} {{nom_locataire}}</strong>,</p>
    <p>Votre demande de caution solidaire a bien été transmise à <strong>{{prenom_garant}} {{nom_garant}}</strong> ({{email_garant}}).</p>
    <p>Votre garant recevra un email avec un lien sécurisé pour compléter son engagement et fournir les justificatifs nécessaires.</p>
    <p>Vous serez notifié(e) une fois que votre garant aura finalisé son dossier.</p>
    <p>Pour toute question, contactez-nous à <a href="mailto:{{email_contact}}">{{email_contact}}</a>.</p>
  </div>
  <div style="background:#ecf0f1;padding:15px 30px;text-align:center;font-size:12px;color:#7f8c8d;">
    <p style="margin:0;">{{company}}</p>
  </div>
</div>
</body></html>',
    JSON_ARRAY('prenom_locataire', 'nom_locataire', 'prenom_garant', 'nom_garant', 'email_garant', 'email_contact', 'company'),
    1
)
ON DUPLICATE KEY UPDATE
    nom = VALUES(nom),
    description = VALUES(description),
    sujet = VALUES(sujet),
    corps_html = VALUES(corps_html),
    variables_disponibles = VALUES(variables_disponibles);

-- 3c. Notification admin (BCC) lors de la soumission de la demande garant
INSERT INTO email_templates (identifiant, nom, description, sujet, corps_html, variables_disponibles, actif)
VALUES (
    'garant_notification_admin',
    'Notification admin – nouvelle demande garant',
    'Email de notification envoyé aux administrateurs lors d''une demande de garant',
    '[Garant] Nouvelle demande – contrat {{reference}}',
    '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;">
<h2>Nouvelle demande de garant</h2>
<p><strong>Contrat :</strong> {{reference}}</p>
<p><strong>Logement :</strong> {{adresse_logement}}</p>
<p><strong>Locataire :</strong> {{prenom_locataire}} {{nom_locataire}}</p>
<p><strong>Type de garantie :</strong> {{type_garantie}}</p>
<p><strong>Garant :</strong> {{prenom_garant}} {{nom_garant}} ({{email_garant}})</p>
<p><strong>Date :</strong> {{date_envoi}}</p>
<p><a href="{{lien_admin}}">Voir le contrat dans l''interface admin →</a></p>
</body></html>',
    JSON_ARRAY('reference', 'adresse_logement', 'prenom_locataire', 'nom_locataire', 'type_garantie', 'prenom_garant', 'nom_garant', 'email_garant', 'date_envoi', 'lien_admin', 'company'),
    1
)
ON DUPLICATE KEY UPDATE
    nom = VALUES(nom),
    description = VALUES(description),
    sujet = VALUES(sujet),
    corps_html = VALUES(corps_html),
    variables_disponibles = VALUES(variables_disponibles);

-- 3d. Email de finalisation envoyé au garant et au locataire une fois le dossier complet
INSERT INTO email_templates (identifiant, nom, description, sujet, corps_html, variables_disponibles, actif)
VALUES (
    'garant_finalisation',
    'Finalisation – dossier garant complet',
    'Email envoyé au garant et au locataire lorsque le dossier garant est finalisé (avec lien vers document)',
    'Dossier garant finalisé – {{prenom_locataire}} {{nom_locataire}}',
    '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dossier garant finalisé</title></head>
<body style="font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:0;">
<div style="max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);">
  <div style="background:#27ae60;padding:25px 30px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;">{{company}}</h1>
    <p style="color:#d5f5e3;margin:8px 0 0;">Dossier garant finalisé</p>
  </div>
  <div style="padding:30px;">
    <p>Bonjour <strong>{{prenom_destinataire}} {{nom_destinataire}}</strong>,</p>
    <p>Le dossier de caution solidaire pour le logement <strong>{{adresse_logement}}</strong> a été finalisé avec succès.</p>
    <div style="background:#eafaf1;border-left:4px solid #27ae60;padding:15px;margin:20px 0;border-radius:4px;">
      <p style="margin:0;"><strong>Garant :</strong> {{prenom_garant}} {{nom_garant}}</p>
      <p style="margin:8px 0 0;"><strong>Locataire :</strong> {{prenom_locataire}} {{nom_locataire}}</p>
      <p style="margin:8px 0 0;"><strong>Date de finalisation :</strong> {{date_finalisation}}</p>
    </div>
    <p>Vous pouvez consulter le document de caution solidaire via le lien ci-dessous :</p>
    <div style="text-align:center;margin:30px 0;">
      <a href="{{lien_document}}" style="background:#27ae60;color:#fff;padding:14px 32px;border-radius:6px;text-decoration:none;font-size:16px;font-weight:bold;">
        Consulter le document →
      </a>
    </div>
    <p>Pour toute question, contactez-nous à <a href="mailto:{{email_contact}}">{{email_contact}}</a>.</p>
  </div>
  <div style="background:#ecf0f1;padding:15px 30px;text-align:center;font-size:12px;color:#7f8c8d;">
    <p style="margin:0;">{{company}}</p>
  </div>
</div>
</body></html>',
    JSON_ARRAY('prenom_destinataire', 'nom_destinataire', 'prenom_garant', 'nom_garant', 'prenom_locataire', 'nom_locataire', 'adresse_logement', 'date_finalisation', 'lien_document', 'email_contact', 'company'),
    1
)
ON DUPLICATE KEY UPDATE
    nom = VALUES(nom),
    description = VALUES(description),
    sujet = VALUES(sujet),
    corps_html = VALUES(corps_html),
    variables_disponibles = VALUES(variables_disponibles);
