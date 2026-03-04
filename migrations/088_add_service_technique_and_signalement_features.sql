-- Migration 088 : Service Technique collaborateur + améliorations signalement
-- Date: 2026-03-04
-- Description:
--   1. Ajoute le champ service_technique à la table collaborateurs
--   2. Ajoute le champ disponibilites à la table signalements
--   3. Ajoute le template email de confirmation de responsabilité locataire
--   4. Ajoute le template email pour le service technique (sans bouton admin)

-- 1. Champ service_technique dans collaborateurs
ALTER TABLE collaborateurs
    ADD COLUMN IF NOT EXISTS service_technique TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Si 1, ce collaborateur est désigné comme Service Technique (reçoit les notifications en BCC)';

-- 2. Champ disponibilites dans signalements (renseigné par le locataire)
ALTER TABLE signalements
    ADD COLUMN IF NOT EXISTS disponibilites TEXT NULL
        COMMENT 'Disponibilités renseignées par le locataire lors du signalement (3 jours suivants)';

-- 3. Template email : confirmation de responsabilité à la charge du locataire
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
    'confirmation_responsabilite_locataire',
    'Confirmation de responsabilité — À la charge du locataire',
    'Information concernant votre signalement — Réf. {{reference}}',
    '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Information signalement</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #2c3e50 0%, #e74c3c 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="margin: 0; font-size: 26px;">📋 Information sur votre signalement</h1>
        <p style="margin: 10px 0 0; font-size: 16px;">{{company}}</p>
    </div>
    <div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none;">
        <p>Bonjour {{prenom}} {{nom}},</p>
        <p>Suite à l''analyse de votre signalement, nous vous informons que la responsabilité pour ce problème a été déterminée.</p>
        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 0 5px 5px 0;">
            <p style="margin: 5px 0;"><strong>Référence :</strong> <code>{{reference}}</code></p>
            <p style="margin: 5px 0;"><strong>Titre :</strong> {{titre}}</p>
            <p style="margin: 5px 0;"><strong>Logement :</strong> {{adresse}}</p>
            <p style="margin: 5px 0;"><strong>Responsabilité :</strong> <strong style="color: #e74c3c;">À la charge du locataire</strong></p>
        </div>
        <p>Ce signalement est désormais <strong>clos</strong>. Pour toute question, n''hésitez pas à contacter votre gestionnaire.</p>
    </div>
    <div style="background: #f8f9fa; padding: 15px; text-align: center; border-radius: 0 0 10px 10px; border: 1px solid #e0e0e0; border-top: none;">
        <p style="margin: 0; color: #666; font-size: 12px;">{{company}}</p>
    </div>
    {{signature}}
</body>
</html>',
    'prenom,nom,reference,titre,adresse,company',
    'Email envoyé au locataire lorsque la responsabilité du signalement est confirmée à sa charge (dossier clos automatiquement)',
    1,
    92,
    NOW()
) ON DUPLICATE KEY UPDATE identifiant = identifiant;

-- 4. Template email pour le Service Technique (même contenu que admin mais sans bouton admin)
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
    'nouveau_signalement_service_technique',
    'Nouveau signalement reçu (service technique)',
    '[Signalement {{priorite}}] {{titre}} — {{adresse}}',
    '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouveau signalement</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="margin: 0; font-size: 26px;">🔔 Nouveau Signalement</h1>
        <p style="margin: 10px 0 0; font-size: 16px;">Un locataire a ouvert un ticket</p>
    </div>
    <div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none;">
        <div style="background: #f8f9fa; border-left: 4px solid #e74c3c; padding: 15px; margin-bottom: 20px; border-radius: 0 5px 5px 0;">
            <p style="margin: 5px 0;"><strong>Référence :</strong> <code>{{reference}}</code></p>
            <p style="margin: 5px 0;"><strong>Titre :</strong> {{titre}}</p>
            <p style="margin: 5px 0;"><strong>Priorité :</strong> {{priorite}}</p>
            <p style="margin: 5px 0;"><strong>Logement :</strong> {{adresse}}</p>
            <p style="margin: 5px 0;"><strong>Locataire :</strong> {{locataire}}</p>
            <p style="margin: 5px 0;"><strong>Téléphone :</strong> {{telephone}}</p>
            <p style="margin: 5px 0;"><strong>Date :</strong> {{date}}</p>
            {{disponibilites_html}}
        </div>
        <p><strong>Description :</strong></p>
        <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; white-space: pre-wrap;">{{description}}</div>
        {{photos_html}}
    </div>
    {{signature}}
</body>
</html>',
    'reference,titre,priorite,adresse,locataire,telephone,description,date,disponibilites_html,photos_html',
    'Email envoyé au Service Technique à chaque nouveau signalement (sans bouton accès admin)',
    1,
    93,
    NOW()
) ON DUPLICATE KEY UPDATE identifiant = identifiant;

-- 5. Mettre à jour le template admin pour inclure téléphone, disponibilités et médias
UPDATE email_templates
SET corps_html = '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouveau signalement</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="margin: 0; font-size: 26px;">🔔 Nouveau Signalement</h1>
        <p style="margin: 10px 0 0; font-size: 16px;">Un locataire a ouvert un ticket</p>
    </div>
    <div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none;">
        <div style="background: #f8f9fa; border-left: 4px solid #e74c3c; padding: 15px; margin-bottom: 20px; border-radius: 0 5px 5px 0;">
            <p style="margin: 5px 0;"><strong>Référence :</strong> <code>{{reference}}</code></p>
            <p style="margin: 5px 0;"><strong>Titre :</strong> {{titre}}</p>
            <p style="margin: 5px 0;"><strong>Priorité :</strong> {{priorite}}</p>
            <p style="margin: 5px 0;"><strong>Logement :</strong> {{adresse}}</p>
            <p style="margin: 5px 0;"><strong>Locataire :</strong> {{locataire}}</p>
            <p style="margin: 5px 0;"><strong>Téléphone :</strong> {{telephone}}</p>
            <p style="margin: 5px 0;"><strong>Date :</strong> {{date}}</p>
            {{disponibilites_html}}
        </div>
        <p><strong>Description :</strong></p>
        <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; white-space: pre-wrap;">{{description}}</div>
        {{photos_html}}
        <p style="margin-top: 20px; text-align: center;">
            <a href="{{lien_admin}}" style="background: #3498db; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block;">
                Voir le signalement →
            </a>
        </p>
    </div>
    {{signature}}
</body>
</html>',
    variables_disponibles = 'reference,titre,priorite,adresse,locataire,telephone,description,date,lien_admin,disponibilites_html,photos_html'
WHERE identifiant = 'nouveau_signalement_admin';
