-- Migration 101 : Ajout du template email "Lien de Paiement Stripe"
-- Date: 2026-03-09
-- Description:
--   Crée le template email `decompte_lien_paiement_stripe` pour l'envoi du lien
--   de paiement Stripe au locataire depuis le module Décomptes.
--   - Contient un bouton de paiement stylisé avec la couleur Stripe (#635bff)
--   - Variables: prenom, nom, reference, adresse, montant_total, lien_paiement,
--     bouton_paiement, date_expiration, company, logement_reference

INSERT INTO email_templates
    (identifiant, nom, sujet, corps_html, variables_disponibles, description, actif, ordre, created_at)
VALUES (
    'decompte_lien_paiement_stripe',
    'Lien de Paiement Stripe',
    'Réglez votre facture {{reference}} — {{company}}',
    '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>Paiement en ligne</title></head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 640px; margin: 0 auto; padding: 20px; background: #f4f6f9;">
<div style="background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden;">
  <!-- En-tête -->
  <div style="background: #635bff; color: #fff; padding: 30px; text-align: center;">
    <h1 style="margin: 0; font-size: 24px;">💳 Paiement en ligne</h1>
    <p style="margin: 10px 0 0; opacity: 0.85;">{{company}}</p>
  </div>
  <!-- Corps -->
  <div style="padding: 30px;">
    <p>Bonjour {{prenom}} {{nom}},</p>
    <p>Votre facture <strong>{{reference}}</strong> d''un montant de <strong>{{montant_total}} €</strong> est disponible pour règlement en ligne.</p>
    <!-- Détails -->
    <div style="background: #f8f9fa; border-left: 4px solid #635bff; padding: 15px; margin: 20px 0; border-radius: 0 5px 5px 0;">
      <p style="margin: 5px 0;"><strong>N° Facture :</strong> <code>{{reference}}</code></p>
      <p style="margin: 5px 0;"><strong>Logement :</strong> {{adresse}}</p>
      <p style="margin: 5px 0;"><strong>Montant :</strong> <strong style="color:#635bff;">{{montant_total}} €</strong></p>
    </div>
    <!-- Bouton de paiement -->
    {{bouton_paiement}}
    <p style="font-size:0.85rem; color:#888; text-align:center;">
      Ou copiez ce lien dans votre navigateur :<br>
      <a href="{{lien_paiement}}" style="color:#635bff; word-break:break-all;">{{lien_paiement}}</a>
    </p>
    <p style="font-size:0.8rem; color:#aaa; text-align:center;">Ce lien expire le {{date_expiration}}.</p>
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
    'prenom,nom,reference,reference_sig,titre,adresse,logement_reference,montant_total,lien_paiement,bouton_paiement,date_expiration,company',
    'Email envoyé au locataire pour le règlement en ligne d''une facture d''intervention via Stripe. Une copie BCC est envoyée aux admins.',
    1,
    315,
    NOW()
) ON DUPLICATE KEY UPDATE identifiant = identifiant;
