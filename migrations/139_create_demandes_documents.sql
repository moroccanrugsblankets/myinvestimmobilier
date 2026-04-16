-- Migration 139 : Module "Demandes & Documents" (Espace locataire)
-- Date: 2026-04-16
-- Description:
--   1. Crée la table demandes_documents pour stocker les demandes soumises par les locataires
--   2. Ajoute les templates email :
--      - demande_document_admin    : notification aux admins lors d'une nouvelle demande
--      - demande_document_locataire: confirmation de réception au locataire
--      - demande_document_reponse  : réponse de l'admin au locataire (avec Reply-To)

-- 1. Table principale des demandes
CREATE TABLE IF NOT EXISTS demandes_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(50) UNIQUE NOT NULL COMMENT 'Référence unique (ex: DEM-20260416-ABCD1234)',

    -- Relations
    contrat_id  INT  NOT NULL,
    logement_id INT  NOT NULL,
    locataire_id INT NULL,

    -- Données de la demande
    email_locataire VARCHAR(255) NOT NULL COMMENT 'Email du locataire au moment de la soumission',
    objet           VARCHAR(500) NOT NULL COMMENT 'Objet de la demande (obligatoire)',
    message         TEXT         NULL     COMMENT 'Message libre du locataire (optionnel)',
    fichier_path    VARCHAR(500) NULL     COMMENT 'Chemin relatif vers le fichier joint (optionnel)',
    fichier_nom     VARCHAR(255) NULL     COMMENT 'Nom original du fichier joint',

    -- Statut
    statut ENUM('nouveau','traite') NOT NULL DEFAULT 'nouveau',

    -- Métadonnées
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_dem_contrat    (contrat_id),
    INDEX idx_dem_logement   (logement_id),
    INDEX idx_dem_locataire  (locataire_id),
    INDEX idx_dem_statut     (statut),
    INDEX idx_dem_created_at (created_at),

    FOREIGN KEY (contrat_id)  REFERENCES contrats(id)   ON DELETE CASCADE,
    FOREIGN KEY (logement_id) REFERENCES logements(id)  ON DELETE CASCADE,
    FOREIGN KEY (locataire_id) REFERENCES locataires(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Demandes de documents soumises par les locataires depuis le portail';

-- 2. Template : notification admin lors d'une nouvelle demande
INSERT INTO email_templates (identifiant, nom, sujet, corps_html, variables_disponibles, actif, ordre)
VALUES (
    'demande_document_admin',
    'Nouvelle demande de document (Admin)',
    '📄 Nouvelle demande de document — {{objet}}',
    '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>Nouvelle demande de document</title></head>
<body style="font-family: Arial, sans-serif; background:#f4f4f4; margin:0; padding:0;">
<div style="max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
  <div style="background:linear-gradient(135deg,#2c3e50,#3498db);padding:30px 35px;">
    <h2 style="color:#fff;margin:0;font-size:20px;">📄 Nouvelle demande de document</h2>
    <p style="color:rgba(255,255,255,0.8);margin:6px 0 0;font-size:13px;">Portail locataire — {{company}}</p>
  </div>
  <div style="padding:30px 35px;">
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
      <tr><td style="padding:8px 0;color:#666;width:140px;font-size:13px;">Référence</td><td style="padding:8px 0;font-weight:bold;font-size:13px;font-family:monospace;">{{reference}}</td></tr>
      <tr style="background:#f8f9fa;"><td style="padding:8px 6px;color:#666;font-size:13px;">Locataire</td><td style="padding:8px 6px;font-size:13px;">{{locataire}}</td></tr>
      <tr><td style="padding:8px 0;color:#666;font-size:13px;">Email</td><td style="padding:8px 0;font-size:13px;"><a href="mailto:{{email_locataire}}" style="color:#3498db;">{{email_locataire}}</a></td></tr>
      <tr style="background:#f8f9fa;"><td style="padding:8px 6px;color:#666;font-size:13px;">Logement</td><td style="padding:8px 6px;font-size:13px;">{{adresse}}</td></tr>
      <tr><td style="padding:8px 0;color:#666;font-size:13px;">Date</td><td style="padding:8px 0;font-size:13px;">{{date}}</td></tr>
    </table>
    <div style="background:#eaf4fd;border-left:4px solid #3498db;padding:15px;border-radius:0 5px 5px 0;margin-bottom:20px;">
      <p style="margin:0 0 6px;font-weight:bold;font-size:14px;">Objet de la demande :</p>
      <p style="margin:0;font-size:14px;">{{objet}}</p>
    </div>
    {{message_html}}
    <div style="margin:25px 0;text-align:center;">
      <a href="{{lien_admin}}" style="display:inline-block;background:#3498db;color:white;padding:12px 28px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:14px;">Voir la demande →</a>
    </div>
    <p style="color:#999;font-size:12px;margin:20px 0 0;border-top:1px solid #eee;padding-top:15px;">{{company}} — notification automatique</p>
  </div>
</div>
</body></html>',
    'reference,locataire,email_locataire,adresse,objet,message_html,date,lien_admin,company',
    1,
    200
) ON DUPLICATE KEY UPDATE nom = nom;

-- 3. Template : confirmation de réception au locataire
INSERT INTO email_templates (identifiant, nom, sujet, corps_html, variables_disponibles, actif, ordre)
VALUES (
    'demande_document_locataire',
    'Confirmation demande de document (Locataire)',
    '✅ Votre demande a bien été reçue — {{objet}}',
    '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>Demande reçue</title></head>
<body style="font-family: Arial, sans-serif; background:#f4f4f4; margin:0; padding:0;">
<div style="max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
  <div style="background:linear-gradient(135deg,#2c3e50,#3498db);padding:30px 35px;">
    <h2 style="color:#fff;margin:0;font-size:20px;">✅ Votre demande a bien été reçue</h2>
    <p style="color:rgba(255,255,255,0.8);margin:6px 0 0;font-size:13px;">{{company}}</p>
  </div>
  <div style="padding:30px 35px;">
    <p style="font-size:15px;">Bonjour {{prenom}},</p>
    <p style="color:#555;font-size:14px;">Nous avons bien enregistré votre demande et nous vous répondrons dans les meilleurs délais.</p>
    <div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:20px;margin:20px 0;">
      <p style="margin:0 0 8px;font-size:13px;color:#666;"><strong>Référence :</strong> <span style="font-family:monospace;">{{reference}}</span></p>
      <p style="margin:0;font-size:13px;color:#666;"><strong>Objet :</strong> {{objet}}</p>
    </div>
    <p style="color:#555;font-size:13px;">Conservez cette référence pour tout suivi auprès de votre gestionnaire.</p>
    <p style="color:#999;font-size:12px;margin:25px 0 0;border-top:1px solid #eee;padding-top:15px;">{{company}} — cet email est envoyé automatiquement, veuillez ne pas y répondre directement.</p>
  </div>
</div>
</body></html>',
    'prenom,nom,reference,objet,company',
    1,
    201
) ON DUPLICATE KEY UPDATE nom = nom;
