-- Migration 097 : Template email pour confirmation d'intervention par le locataire
-- Date: 2026-03-06
-- Description:
--   Ajoute un template email envoyé aux admins/service-technique quand le locataire
--   confirme que l'intervention a été réalisée à sa satisfaction via le bouton
--   "✅ Confirmer l'intervention" de /signalement/confirmer-intervention.php

INSERT INTO email_templates (identifiant, nom, sujet, corps_html, variables_disponibles, description, actif, ordre, created_at)
VALUES (
    'signalement_intervention_confirmee_admin',
    'Intervention confirmée par le locataire (notification admin)',
    '✅ Intervention confirmée par le locataire — Réf. {{reference}}',
    '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>Intervention confirmée</title></head>
<body style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;margin:0 auto;padding:20px;">
    <div style="background:linear-gradient(135deg,#27ae60 0%,#2ecc71 100%);color:white;padding:30px;text-align:center;border-radius:10px 10px 0 0;">
        <h1 style="margin:0;font-size:24px;">✅ Intervention Confirmée par le Locataire</h1>
        <p style="margin:10px 0 0;font-size:15px;">{{company}}</p>
    </div>
    <div style="background:#fff;padding:30px;border:1px solid #e0e0e0;border-top:none;">
        <p>Le locataire <strong>{{locataire_nom}}</strong> a confirmé que l''intervention a bien été réalisée à sa satisfaction. Le dossier est maintenant <strong>clos</strong>.</p>
        <div style="background:#e8f8f0;border-left:4px solid #27ae60;padding:15px;margin:20px 0;border-radius:0 5px 5px 0;">
            <p style="margin:5px 0;"><strong>Référence :</strong> <code>{{reference}}</code></p>
            <p style="margin:5px 0;"><strong>Titre :</strong> {{titre}}</p>
            <p style="margin:5px 0;"><strong>Logement :</strong> {{adresse}}</p>
            <p style="margin:5px 0;"><strong>Confirmé le :</strong> {{date_confirmation}}</p>
        </div>
        <p><a href="{{lien_admin}}" style="display:inline-block;background:#27ae60;color:white;padding:12px 24px;text-decoration:none;border-radius:5px;">Voir le signalement</a></p>
    </div>
    <div style="background:#f8f9fa;padding:15px;text-align:center;border-radius:0 0 10px 10px;border:1px solid #e0e0e0;border-top:none;">
        <p style="margin:0;color:#666;font-size:12px;">{{company}}</p>
    </div>
    {{signature}}
</body>
</html>',
    'reference,titre,adresse,locataire_nom,date_confirmation,lien_admin,company',
    'Notification envoyée aux admins quand le locataire confirme l''intervention via le bouton de confirmation',
    1, 100, NOW()
) ON DUPLICATE KEY UPDATE identifiant = identifiant;
