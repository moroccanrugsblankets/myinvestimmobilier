-- Migration 104 : Template email confirmation de paiement de décompte
-- Date: 2026-03-10
-- Description:
--   Crée le template email `decompte_paiement_confirme` envoyé automatiquement
--   au locataire après le règlement en ligne d'un décompte d'intervention via Stripe.
--   Une copie BCC est envoyée aux administrateurs.

INSERT INTO email_templates
    (identifiant, nom, sujet, corps_html, variables_disponibles, description, actif, ordre, created_at)
VALUES (
    'decompte_paiement_confirme',
    'Confirmation de paiement - Décompte',
    'Paiement confirmé — Décompte {{reference}} — {{company}}',
    '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>Paiement confirmé</title></head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 640px; margin: 0 auto; padding: 20px; background: #f4f6f9;">
<div style="background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden;">
  <!-- En-tête -->
  <div style="background: #27ae60; color: #fff; padding: 30px; text-align: center;">
    <h1 style="margin: 0; font-size: 24px;">✅ Paiement confirmé</h1>
    <p style="margin: 10px 0 0; opacity: 0.85;">{{company}}</p>
  </div>
  <!-- Corps -->
  <div style="padding: 30px;">
    <p>Bonjour {{prenom}} {{nom}},</p>
    <p>Nous vous confirmons la bonne réception de votre paiement pour le décompte d''intervention <strong>{{reference}}</strong>.</p>
    <!-- Récapitulatif -->
    <div style="background: #f8f9fa; border-left: 4px solid #27ae60; padding: 15px; margin: 20px 0; border-radius: 0 5px 5px 0;">
      <p style="margin: 5px 0;"><strong>N° Décompte :</strong> <code>{{reference}}</code></p>
      <p style="margin: 5px 0;"><strong>Logement :</strong> {{adresse}}</p>
      <p style="margin: 5px 0;"><strong>Montant réglé :</strong> <strong style="color:#27ae60;">{{montant_total}} €</strong></p>
      <p style="margin: 5px 0;"><strong>Date de paiement :</strong> {{date_paiement}}</p>
    </div>
    <p>Merci pour votre règlement dans les délais impartis.</p>
    <p>Si vous avez des questions, n''hésitez pas à nous contacter.</p>
    <!-- Signature -->
    {{signature}}
  </div>
  <!-- Pied de page -->
  <div style="background: #f8f9fa; padding: 15px; text-align: center; border-top: 1px solid #e0e0e0;">
    <p style="margin: 0; color: #666; font-size: 12px;">{{company}} — Paiement sécurisé via Stripe</p>
  </div>
</div>
</body>
</html>',
    'prenom,nom,reference,adresse,logement_reference,montant_total,date_paiement,company,signature',
    'Email de confirmation envoyé automatiquement au locataire après le règlement en ligne d''un décompte via Stripe. Une copie BCC est envoyée aux administrateurs.',
    1,
    316,
    NOW()
) ON DUPLICATE KEY UPDATE identifiant = identifiant;
