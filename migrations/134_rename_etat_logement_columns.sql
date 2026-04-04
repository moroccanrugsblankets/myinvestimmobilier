-- Migration 134 : Renommer les colonnes liées à l'état du logement
--
-- 1. Renommer piece_principale → etat_logement dans etats_lieux
--    La colonne unique "Description de l'état du logement" remplace les 3 anciens champs.
--    Les colonnes coin_cuisine et salle_eau_wc sont conservées pour compatibilité ascendante
--    (données historiques), mais ne sont plus utilisées dans les formulaires.
--
-- 2. Renommer default_etat_piece_principale → default_etat_logement dans logements
--    Les colonnes default_etat_cuisine et default_etat_salle_eau sont conservées
--    pour compatibilité ascendante mais ne sont plus utilisées.

ALTER TABLE etats_lieux
    CHANGE COLUMN piece_principale etat_logement TEXT;

ALTER TABLE logements
    CHANGE COLUMN default_etat_piece_principale default_etat_logement TEXT NULL;
