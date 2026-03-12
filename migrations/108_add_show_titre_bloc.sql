-- Migration 108: Bloc titre sur les pages publiques
-- Date: 2026-03-12
-- Description:
--   Ajoute le champ `show_titre_bloc` à `frontend_pages`.
--   Quand activé, un bandeau pleine largeur bleu (#1a2a47) affiche le titre
--   de la page centré en blanc sur les pages du front office.

ALTER TABLE frontend_pages
    ADD COLUMN show_titre_bloc TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = afficher le bloc titre bleu en haut de la page publique'
    AFTER is_homepage;
