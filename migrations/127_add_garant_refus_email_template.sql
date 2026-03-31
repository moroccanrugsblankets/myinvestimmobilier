-- Migration 127 : Ajout du template email pour le refus de la demande garant
-- ======================================================
-- Template : garant_refus_notification
-- Envoyé au garant ET au locataire lorsque le garant refuse l'engagement
-- ======================================================

INSERT INTO email_templates (identifiant, nom, description, sujet, corps_html, variables_disponibles, actif)
VALUES (
    'garant_refus_notification',
    'Refus garant – notification',
    'Email envoyé au garant et au locataire lorsque le garant a refusé la demande de caution solidaire',
    'Refus de demande de garant – {{prenom_locataire}} {{nom_locataire}}',
    '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Refus de demande de garant</title></head>
<body style="font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:0;">
<div style="max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);">
  <div style="background:#e74c3c;padding:25px 30px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;">{{company}}</h1>
    <p style="color:#fadbd8;margin:8px 0 0;">Refus de demande de garant</p>
  </div>
  <div style="padding:30px;">
    <p>Bonjour <strong>{{prenom_destinataire}} {{nom_destinataire}}</strong>,</p>
    <p>Nous vous informons que <strong>{{prenom_garant}} {{nom_garant}}</strong> a refusé la demande de caution solidaire pour le logement suivant :</p>
    <div style="background:#fdf2f2;border-left:4px solid #e74c3c;padding:15px;margin:20px 0;border-radius:4px;">
      <p style="margin:0;"><strong>Logement :</strong> {{adresse_logement}}</p>
      <p style="margin:8px 0 0;"><strong>Locataire :</strong> {{prenom_locataire}} {{nom_locataire}}</p>
      <p style="margin:8px 0 0;"><strong>Garant :</strong> {{prenom_garant}} {{nom_garant}}</p>
    </div>
    <p>Pour toute question ou pour désigner un nouveau garant, contactez-nous à <a href="mailto:{{email_contact}}">{{email_contact}}</a>.</p>
  </div>
  <div style="background:#ecf0f1;padding:15px 30px;text-align:center;font-size:12px;color:#7f8c8d;">
    <p style="margin:0;">{{company}}</p>
  </div>
</div>
</body></html>',
    JSON_ARRAY('prenom_destinataire', 'nom_destinataire', 'prenom_garant', 'nom_garant', 'prenom_locataire', 'nom_locataire', 'adresse_logement', 'email_contact', 'company'),
    1
)
ON DUPLICATE KEY UPDATE
    nom = VALUES(nom),
    description = VALUES(description),
    sujet = VALUES(sujet),
    corps_html = VALUES(corps_html),
    variables_disponibles = VALUES(variables_disponibles);
