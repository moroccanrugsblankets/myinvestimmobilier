-- Migration 089 : Support multi-collaborateurs sur les signalements
-- Date: 2026-03-04
-- Description:
--   1. Crée la table signalements_collaborateurs pour stocker plusieurs attributions par signalement
--   2. Ajoute le template email de confirmation de responsabilité propriétaire

-- 1. Table de liaison signalement ↔ plusieurs collaborateurs
CREATE TABLE IF NOT EXISTS signalements_collaborateurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    signalement_id INT NOT NULL,
    collaborateur_id INT NULL COMMENT 'Référence vers collaborateurs.id (si sélectionné depuis la liste)',
    collaborateur_nom VARCHAR(255) NOT NULL,
    collaborateur_email VARCHAR(255) NULL,
    collaborateur_telephone VARCHAR(50) NULL,
    mode_notification VARCHAR(20) NOT NULL DEFAULT 'email' COMMENT 'email, whatsapp, les_deux',
    attribue_le TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    attribue_par VARCHAR(255) NULL COMMENT 'Nom de l''admin qui a fait l''attribution',

    INDEX idx_sc_signalement (signalement_id),
    INDEX idx_sc_collaborateur (collaborateur_id),

    FOREIGN KEY (signalement_id) REFERENCES signalements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Collaborateurs attribués à un signalement (permet plusieurs attributions)';

-- 2. Template email de confirmation de responsabilité propriétaire
INSERT INTO email_templates (
    identifiant,
    nom,
    sujet,
    corps_html,
    variables_disponibles,
    description,
    actif,
    ordre,
    created_at
) VALUES (
    'confirmation_responsabilite_proprietaire',
    'Confirmation de responsabilité — À la charge du propriétaire',
    'Votre signalement est pris en charge — Réf. {{reference}}',
    '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Information signalement</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #2c3e50 0%, #27ae60 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="margin: 0; font-size: 26px;">✅ Signalement pris en charge</h1>
        <p style="margin: 10px 0 0; font-size: 16px;">{{company}}</p>
    </div>
    <div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none;">
        <p>Bonjour {{prenom}} {{nom}},</p>
        <p>Suite à l''analyse de votre signalement, nous avons le plaisir de vous informer que ce problème relève de la responsabilité du propriétaire et sera traité dans les meilleurs délais.</p>
        <div style="background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 0 5px 5px 0;">
            <p style="margin: 5px 0;"><strong>Référence :</strong> <code>{{reference}}</code></p>
            <p style="margin: 5px 0;"><strong>Titre :</strong> {{titre}}</p>
            <p style="margin: 5px 0;"><strong>Logement :</strong> {{adresse}}</p>
            <p style="margin: 5px 0;"><strong>Responsabilité :</strong> <strong style="color: #28a745;">À la charge du propriétaire</strong></p>
        </div>
        <p>Notre équipe vous contactera prochainement pour convenir des modalités d''intervention. Pour toute question, n''hésitez pas à contacter votre gestionnaire.</p>
    </div>
    <div style="background: #f8f9fa; padding: 15px; text-align: center; border-radius: 0 0 10px 10px; border: 1px solid #e0e0e0; border-top: none;">
        <p style="margin: 0; color: #666; font-size: 12px;">{{company}}</p>
    </div>
    {{signature}}
</body>
</html>',
    'prenom,nom,reference,titre,adresse,company,responsabilite',
    'Email envoyé au locataire lorsque la responsabilité du signalement est confirmée à la charge du propriétaire',
    1,
    94,
    NOW()
) ON DUPLICATE KEY UPDATE identifiant = identifiant;

-- 3. Mettre à jour le template locataire pour inclure la variable description, disponibilites_html et photos_html
UPDATE email_templates
SET corps_html = '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signalement enregistré</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="margin: 0; font-size: 26px;">✅ Signalement enregistré</h1>
        <p style="margin: 10px 0 0; font-size: 16px;">{{company}}</p>
    </div>
    <div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none;">
        <p>Bonjour {{prenom}} {{nom}},</p>
        <p>Votre signalement a bien été transmis à notre équipe de gestion. Un suivi vous sera communiqué dans les meilleurs délais.</p>
        <div style="background: #f8f9fa; border-left: 4px solid #3498db; padding: 15px; margin: 20px 0; border-radius: 0 5px 5px 0;">
            <p style="margin: 5px 0;"><strong>Référence :</strong> <code>{{reference}}</code></p>
            <p style="margin: 5px 0;"><strong>Titre :</strong> {{titre}}</p>
            <p style="margin: 5px 0;"><strong>Priorité :</strong> {{priorite}}</p>
            <p style="margin: 5px 0;"><strong>Logement :</strong> {{adresse}}</p>
            <p style="margin: 5px 0;"><strong>Date :</strong> {{date}}</p>
        </div>
        <p><strong>Description :</strong></p>
        <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; white-space: pre-wrap;">{{description}}</div>
        {{disponibilites_html}}
        {{photos_html}}
        <p style="color: #666; font-size: 13px; margin-top: 20px;">Conservez la référence <strong>{{reference}}</strong> pour tout suivi auprès de votre gestionnaire.</p>
    </div>
    <div style="background: #f8f9fa; padding: 15px; text-align: center; border-radius: 0 0 10px 10px; border: 1px solid #e0e0e0; border-top: none;">
        <p style="margin: 0; color: #666; font-size: 12px;">{{company}}</p>
    </div>
    {{signature}}
</body>
</html>',
    variables_disponibles = 'prenom,nom,reference,titre,priorite,adresse,date,company,description,disponibilites_html,photos_html'
WHERE identifiant = 'nouveau_signalement_locataire';
