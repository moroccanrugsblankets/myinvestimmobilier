-- Migration 110: Add 'reserve' status to logements.statut ENUM
-- The 'reserve' status distinguishes reserved properties from rented ones ('en_location').
-- Reserved properties are shown on the public listing page (/logements.php)
-- while rented properties are hidden from the public listing.

ALTER TABLE logements
    MODIFY COLUMN statut ENUM('disponible', 'en_location', 'maintenance', 'indisponible', 'reserve') DEFAULT 'disponible';
