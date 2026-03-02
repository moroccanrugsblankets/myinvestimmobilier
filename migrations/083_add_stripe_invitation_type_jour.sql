-- Migration 083: Paramètre type de jour pour l'invitation Stripe
-- Date: 2026-03-02
-- Description: Ajoute le paramètre stripe_paiement_invitation_ouvrable pour définir
--              si le jour d'envoi de l'invitation est un jour ouvrable ou calendaire

INSERT INTO parametres (cle, valeur, type, description, groupe) VALUES
('stripe_paiement_invitation_ouvrable', 'ouvrable', 'string',
 'Type de jour pour l\'envoi de l\'invitation Stripe : "ouvrable" (lun–ven) ou "non_ouvrable" (calendaire)',
 'stripe')
ON DUPLICATE KEY UPDATE cle = cle;
