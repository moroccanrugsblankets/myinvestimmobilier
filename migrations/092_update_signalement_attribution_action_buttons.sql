-- Migration 092 : Ajout de {{action_buttons_html}} dans le template signalement_attribution
-- Date: 2026-03-05
-- Description:
--   Met à jour le template email d'attribution de signalement pour y inclure la variable
--   {{action_buttons_html}} qui contient les boutons d'action rapide pour le collaborateur,
--   dont le bouton "🟢 Intervention terminée" qui pointe vers la nouvelle page dédiée.

UPDATE email_templates
SET
    corps_html = '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signalement attribué</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #e67e22 0%, #d35400 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="margin: 0; font-size: 26px;">🔧 Mission attribuée</h1>
        <p style="margin: 10px 0 0; font-size: 16px;">{{company}}</p>
    </div>
    <div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none;">
        <p>Bonjour {{collab_nom}},</p>
        <p>Un signalement vous a été attribué. Veuillez en prendre connaissance et intervenir dans les meilleurs délais.</p>

        <div style="background: #fff3e0; border-left: 4px solid #e67e22; padding: 15px; margin: 20px 0; border-radius: 0 5px 5px 0;">
            <h3 style="margin: 0 0 10px; color: #e67e22; font-size: 16px;">📋 Informations du signalement</h3>
            <p style="margin: 5px 0;"><strong>Référence :</strong> <code>{{reference}}</code></p>
            <p style="margin: 5px 0;"><strong>Titre :</strong> {{titre}}</p>
            <p style="margin: 5px 0;"><strong>Priorité :</strong> {{priorite}}</p>
            <p style="margin: 5px 0;"><strong>Logement :</strong> {{adresse}}</p>
            <p style="margin: 5px 0;"><strong>Date du signalement :</strong> {{date_signalement}}</p>
        </div>

        <div style="background: #f0f4f8; border-left: 4px solid #3498db; padding: 15px; margin: 20px 0; border-radius: 0 5px 5px 0;">
            <h3 style="margin: 0 0 10px; color: #3498db; font-size: 16px;">👤 Locataire</h3>
            <p style="margin: 5px 0;"><strong>Nom :</strong> {{locataire_nom}}</p>
            <p style="margin: 5px 0;"><strong>Téléphone :</strong> {{locataire_telephone}}</p>
            <p style="margin: 5px 0;"><strong>Email :</strong> {{locataire_email}}</p>
        </div>

        <div style="background: #f8f9fa; padding: 15px; margin: 20px 0; border-radius: 5px;">
            <h3 style="margin: 0 0 10px; color: #333; font-size: 16px;">📝 Description du problème</h3>
            <div style="white-space: pre-wrap;">{{description}}</div>
        </div>

        {{photos_html}}

        {{action_buttons_html}}
    </div>
    <div style="background: #f8f9fa; padding: 15px; text-align: center; border-radius: 0 0 10px 10px; border: 1px solid #e0e0e0; border-top: none;">
        <p style="margin: 0; color: #666; font-size: 12px;">{{company}}</p>
    </div>
    {{signature}}
</body>
</html>',
    variables_disponibles = 'collab_nom,reference,titre,priorite,adresse,locataire_nom,locataire_telephone,locataire_email,description,date_signalement,photos_html,action_buttons_html,company',
    updated_at = NOW()
WHERE identifiant = 'signalement_attribution';
