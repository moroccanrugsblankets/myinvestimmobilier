-- Migration 098 : Ajout du template email "Facture d'Intervention"
-- Date: 2026-03-06
-- Description:
--   Crée le template email `facture_intervention` pour l'envoi de facture au locataire.
--   - Pas de background linear-gradient (utilise une couleur unie #2c3e50)
--   - Inclut la variable {{logement_reference}}
--   - Compatible avec le système de Templates d'Emails de l'interface admin

INSERT INTO email_templates
    (identifiant, nom, sujet, corps_html, variables_disponibles, description, actif, ordre, created_at)
VALUES (
    'facture_intervention',
    'Facture d\'Intervention',
    'Facture d\'intervention — {{reference}}',
    '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>Facture d\'Intervention</title></head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 640px; margin: 0 auto; padding: 20px; background: #f4f6f9;">
<div style="background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden;">
  <!-- En-tête -->
  <div style="background: #2c3e50; color: #fff; padding: 30px; text-align: center;">
    <h1 style="margin: 0; font-size: 24px;">📄 Facture d\'Intervention</h1>
    <p style="margin: 10px 0 0; opacity: 0.85;">{{company}}</p>
  </div>
  <!-- Corps -->
  <div style="padding: 30px;">
    <p>Bonjour {{prenom}} {{nom}},</p>
    <p>Veuillez trouver ci-dessous la facture relative à l\'intervention effectuée dans votre logement.</p>
    <!-- Détails -->
    <div style="background: #f8f9fa; border-left: 4px solid #3498db; padding: 15px; margin: 20px 0; border-radius: 0 5px 5px 0;">
      <p style="margin: 5px 0;"><strong>N° Facture :</strong> <code>{{reference}}</code></p>
      <p style="margin: 5px 0;"><strong>Signalement :</strong> {{reference_sig}} — {{titre}}</p>
      <p style="margin: 5px 0;"><strong>Logement :</strong> {{adresse}}</p>
      <p style="margin: 5px 0;"><strong>Réf. logement :</strong> <code>{{logement_reference}}</code></p>
      <p style="margin: 5px 0;"><strong>Date :</strong> {{date_facture}}</p>
    </div>
    <!-- Lignes -->
    {{lignes_html}}
    <!-- Signature -->
    {{signature}}
  </div>
  <!-- Pied de page -->
  <div style="background: #f8f9fa; padding: 15px; text-align: center; border-top: 1px solid #e0e0e0;">
    <p style="margin: 0; color: #666; font-size: 12px;">{{company}}</p>
  </div>
</div>
</body>
</html>',
    'prenom,nom,reference,reference_sig,titre,adresse,logement_reference,montant_total,lignes_html,date_facture,company,contrat_ref',
    'Email de facture d\'intervention envoyé au locataire depuis le module Décomptes',
    1,
    310,
    NOW()
) ON DUPLICATE KEY UPDATE identifiant = identifiant;
