-- Migration 095 : Nouveaux templates pour les notifications email de responsabilité signalement
-- Date: 2026-03-06
-- Description:
--   1. Template pour le Service Technique lorsque la responsabilité est confirmée au propriétaire
--      (avec les 4 boutons d'action : Pris en charge, Sur place, Terminé, Impossible)
--   2. Template de confirmation au locataire lorsqu'il accepte l'intervention (responsabilité locataire)
--   3. Template pour le Service Technique lorsque le locataire accepte l'intervention
--      (avec les 4 boutons d'action)

-- 1. Template Service Technique — responsabilité propriétaire
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
    'responsabilite_proprietaire_service_technique',
    'Intervention requise — Responsabilité propriétaire (Service Technique)',
    '[Intervention requise] {{titre}} — {{adresse}}',
    '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Intervention requise</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #2c3e50 0%, #27ae60 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="margin: 0; font-size: 26px;">🔧 Intervention requise</h1>
        <p style="margin: 10px 0 0; font-size: 16px;">Responsabilité confirmée — À la charge du propriétaire</p>
    </div>
    <div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none;">
        <p>Bonjour,</p>
        <p>La responsabilité du signalement ci-dessous a été confirmée <strong>à la charge du propriétaire</strong>. Votre intervention est requise.</p>
        <div style="background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 0 5px 5px 0;">
            <p style="margin: 5px 0;"><strong>Référence :</strong> <code>{{reference}}</code></p>
            <p style="margin: 5px 0;"><strong>Titre :</strong> {{titre}}</p>
            <p style="margin: 5px 0;"><strong>Logement :</strong> {{adresse}}</p>
            <p style="margin: 5px 0;"><strong>Locataire :</strong> {{locataire_nom}}</p>
            <p style="margin: 5px 0;"><strong>Téléphone :</strong> {{locataire_telephone}}</p>
        </div>
        {{action_buttons_html}}
    </div>
    {{signature}}
</body>
</html>',
    'reference,titre,adresse,locataire_nom,locataire_telephone,action_buttons_html',
    'Email envoyé au Service Technique lorsque la responsabilité du signalement est confirmée à la charge du propriétaire (avec boutons d''action)',
    1,
    95,
    NOW()
) ON DUPLICATE KEY UPDATE identifiant = identifiant;

-- 2. Template confirmation au locataire — acceptation de l'intervention
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
    'acceptation_intervention_locataire',
    'Confirmation d''acceptation de l''intervention (locataire)',
    'Votre accord a bien été enregistré — Réf. {{reference}}',
    '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceptation de l''intervention</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #e67e22 0%, #d35400 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="margin: 0; font-size: 26px;">✅ Accord enregistré</h1>
        <p style="margin: 10px 0 0; font-size: 16px;">{{company}}</p>
    </div>
    <div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none;">
        <p>Bonjour {{prenom}} {{nom}},</p>
        <p>Votre accord pour la réalisation de l''intervention a bien été enregistré. Notre équipe technique vous contactera prochainement pour convenir d''une date d''intervention.</p>
        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 0 5px 5px 0;">
            <p style="margin: 5px 0;"><strong>Référence :</strong> <code>{{reference}}</code></p>
            <p style="margin: 5px 0;"><strong>Titre :</strong> {{titre}}</p>
            <p style="margin: 5px 0;"><strong>Logement :</strong> {{adresse}}</p>
        </div>
        <p style="color: #666; font-size: 13px;">L''intervention sera facturée selon le barème tarifaire qui vous a été communiqué. Pour toute question, n''hésitez pas à contacter votre gestionnaire.</p>
    </div>
    <div style="background: #f8f9fa; padding: 15px; text-align: center; border-radius: 0 0 10px 10px; border: 1px solid #e0e0e0; border-top: none;">
        <p style="margin: 0; color: #666; font-size: 12px;">{{company}}</p>
    </div>
    {{signature}}
</body>
</html>',
    'prenom,nom,reference,titre,adresse,company',
    'Email envoyé au locataire (et admins en BCC) lorsqu''il accepte l''intervention facturable',
    1,
    96,
    NOW()
) ON DUPLICATE KEY UPDATE identifiant = identifiant;

-- 3. Template Service Technique — locataire a accepté l'intervention
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
    'acceptation_intervention_service_technique',
    'Intervention acceptée par le locataire (Service Technique)',
    '[Intervention à planifier] {{titre}} — {{adresse}}',
    '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Intervention à planifier</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #e67e22 0%, #d35400 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="margin: 0; font-size: 26px;">🔧 Intervention à planifier</h1>
        <p style="margin: 10px 0 0; font-size: 16px;">Le locataire a accepté l''intervention</p>
    </div>
    <div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none;">
        <p>Bonjour,</p>
        <p>Le locataire a accepté l''intervention pour le signalement ci-dessous. Vous pouvez désormais planifier l''intervention.</p>
        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 0 5px 5px 0;">
            <p style="margin: 5px 0;"><strong>Référence :</strong> <code>{{reference}}</code></p>
            <p style="margin: 5px 0;"><strong>Titre :</strong> {{titre}}</p>
            <p style="margin: 5px 0;"><strong>Logement :</strong> {{adresse}}</p>
            <p style="margin: 5px 0;"><strong>Locataire :</strong> {{locataire_nom}}</p>
            <p style="margin: 5px 0;"><strong>Téléphone :</strong> {{locataire_telephone}}</p>
        </div>
        {{action_buttons_html}}
    </div>
    {{signature}}
</body>
</html>',
    'reference,titre,adresse,locataire_nom,locataire_telephone,action_buttons_html',
    'Email envoyé au Service Technique lorsque le locataire accepte l''intervention (avec boutons d''action)',
    1,
    97,
    NOW()
) ON DUPLICATE KEY UPDATE identifiant = identifiant;
