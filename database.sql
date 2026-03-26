-- Base de données unifiée pour la gestion complète des baux
-- My Invest Immobilier - Système complet
-- Version 2.0 - Base de données unique consolidée
-- 
-- Cette base unique gère:
-- - Candidatures et workflow de sélection
-- - Contrats de bail et signature électronique
-- - États des lieux et gestion du cycle de vie
-- - Paiements et dépôts de garantie
-- - Interface admin et authentification

CREATE DATABASE IF NOT EXISTS bail_signature CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bail_signature;

-- =====================================================
-- GESTION DES LOGEMENTS
-- =====================================================

CREATE TABLE IF NOT EXISTS logements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) UNIQUE NOT NULL,
    adresse TEXT NOT NULL,
    type VARCHAR(50) NOT NULL,
    surface DECIMAL(5,2),
    loyer DECIMAL(10,2) NOT NULL,
    charges DECIMAL(10,2) NOT NULL,
    depot_garantie DECIMAL(10,2) NOT NULL,
    parking ENUM('Aucun', '1 place') DEFAULT 'Aucun',
    statut ENUM('disponible', 'en_location', 'maintenance', 'indisponible') DEFAULT 'disponible',
    date_disponibilite DATE,
    description TEXT,
    equipements TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_reference (reference),
    INDEX idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- GESTION DES CANDIDATURES
-- =====================================================

CREATE TABLE IF NOT EXISTS candidatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_unique VARCHAR(100) UNIQUE NOT NULL,
    response_token VARCHAR(64) UNIQUE NULL COMMENT 'Token sécurisé pour réponses par email (accept/reject)',
    logement_id INT,
    
    -- Informations personnelles
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    telephone VARCHAR(20),
    
    -- Situation professionnelle
    statut_professionnel ENUM('CDI', 'CDD', 'Indépendant', 'Autre') NOT NULL,
    periode_essai ENUM('En cours', 'Dépassée', 'Non applicable') NOT NULL,
    
    -- Revenus
    revenus_mensuels ENUM('< 2300', '2300-3000', '3000+') NOT NULL,
    type_revenus ENUM('Salaires', 'Indépendant', 'Retraite/rente', 'Autres') NOT NULL,
    
    -- Situation logement
    situation_logement ENUM('Locataire', 'Hébergé', 'Propriétaire', 'Autre') NOT NULL,
    preavis_donne ENUM('Oui', 'Non', 'Non concerné') NOT NULL,
    
    -- Occupation
    nb_occupants ENUM('1', '2', 'Autre') NOT NULL,
    nb_occupants_autre VARCHAR(50),
    
    -- Garanties
    garantie_visale ENUM('Oui', 'Non', 'Je ne sais pas') NOT NULL,
    
    -- Workflow
    statut ENUM('en_cours', 'refuse', 'accepte', 'refus_apres_visite', 'contrat_envoye', 'contrat_signe') DEFAULT 'en_cours',
    date_soumission TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_reponse_auto TIMESTAMP NULL,
    date_reponse_envoyee TIMESTAMP NULL,
    reponse_automatique ENUM('accepte', 'refuse', 'en_attente') DEFAULT 'en_attente',
    motif_refus TEXT,
    
    -- Visite
    visite_confirmee BOOLEAN DEFAULT FALSE,
    date_visite DATETIME NULL,
    notes_visite TEXT,
    
    -- Admin
    priorite INT DEFAULT 0,
    notes_admin TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (logement_id) REFERENCES logements(id) ON DELETE SET NULL,
    INDEX idx_reference (reference_unique),
    INDEX idx_response_token (response_token),
    INDEX idx_statut (statut),
    INDEX idx_email (email),
    INDEX idx_date_soumission (date_soumission),
    INDEX idx_date_reponse_auto (date_reponse_auto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- DOCUMENTS DES CANDIDATURES
-- =====================================================

CREATE TABLE IF NOT EXISTS candidature_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidature_id INT NOT NULL,
    type_document ENUM('piece_identite', 'bulletins_salaire', 'contrat_travail', 'avis_imposition', 'quittances_loyer', 'justificatif_revenus', 'justificatif_domicile', 'autre') NOT NULL,
    nom_fichier VARCHAR(255) NOT NULL,
    nom_original VARCHAR(255) NOT NULL,
    chemin_fichier VARCHAR(500) NOT NULL,
    taille_fichier INT,
    mime_type VARCHAR(100),
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (candidature_id) REFERENCES candidatures(id) ON DELETE CASCADE,
    INDEX idx_candidature (candidature_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- CONTRATS DE BAIL
-- =====================================================

CREATE TABLE IF NOT EXISTS contrats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_unique VARCHAR(100) UNIQUE NOT NULL,
    candidature_id INT,
    logement_id INT NOT NULL,
    
    -- Dates
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_prise_effet DATE NULL,
    date_fin_prevue DATE NULL,
    date_signature TIMESTAMP NULL,
    date_expiration TIMESTAMP NULL,
    
    -- Statut
    statut ENUM('en_attente', 'signe', 'en_verification', 'valide', 'expire', 'annule', 'actif', 'termine') DEFAULT 'en_attente',
    nb_locataires INT DEFAULT 1,
    
    -- Financier
    depot_recu BOOLEAN DEFAULT FALSE,
    date_reception_depot TIMESTAMP NULL,
    montant_depot DECIMAL(10,2),
    
    -- Lien signature
    token_signature VARCHAR(100) UNIQUE,
    
    -- Validation tracking
    date_verification TIMESTAMP NULL COMMENT 'Date de vérification par admin',
    date_validation TIMESTAMP NULL COMMENT 'Date de validation finale',
    validation_notes TEXT NULL COMMENT 'Notes de vérification/validation',
    motif_annulation TEXT NULL COMMENT 'Raison de l''annulation du contrat',
    verified_by INT NULL COMMENT 'Admin qui a vérifié',
    validated_by INT NULL COMMENT 'Admin qui a validé',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (candidature_id) REFERENCES candidatures(id) ON DELETE SET NULL,
    FOREIGN KEY (logement_id) REFERENCES logements(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES administrateurs(id) ON DELETE SET NULL,
    FOREIGN KEY (validated_by) REFERENCES administrateurs(id) ON DELETE SET NULL,
    INDEX idx_reference (reference_unique),
    INDEX idx_statut (statut),
    INDEX idx_token (token_signature)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- LOCATAIRES
-- =====================================================

CREATE TABLE IF NOT EXISTS locataires (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contrat_id INT NOT NULL,
    ordre INT DEFAULT 1,
    
    -- Informations personnelles
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    date_naissance DATE NOT NULL,
    email VARCHAR(255) NOT NULL,
    telephone VARCHAR(20),
    
    -- Signature
    signature_data LONGTEXT,
    signature_ip VARCHAR(45),
    signature_timestamp TIMESTAMP NULL,
    mention_lu_approuve TEXT,
    
    -- Documents
    piece_identite_recto VARCHAR(255),
    piece_identite_verso VARCHAR(255),
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (contrat_id) REFERENCES contrats(id) ON DELETE CASCADE,
    INDEX idx_contrat_ordre (contrat_id, ordre),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ÉTATS DES LIEUX
-- =====================================================

CREATE TABLE IF NOT EXISTS etats_lieux (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contrat_id INT NOT NULL,
    type ENUM('entree', 'sortie') NOT NULL,
    date_etat DATE NOT NULL,
    
    -- Participants
    locataire_present BOOLEAN DEFAULT TRUE,
    bailleur_representant VARCHAR(100),
    
    -- Observations générales
    etat_general TEXT,
    observations TEXT,
    
    -- Pièce par pièce (JSON)
    details_pieces JSON,
    
    -- Photos/Documents
    photos JSON,
    
    -- Signature
    signature_locataire TEXT,
    signature_bailleur TEXT,
    date_signature TIMESTAMP NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (contrat_id) REFERENCES contrats(id) ON DELETE CASCADE,
    INDEX idx_contrat (contrat_id),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- DÉGRADATIONS ET VÉTUSTÉ
-- =====================================================

CREATE TABLE IF NOT EXISTS degradations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    etat_lieux_id INT NOT NULL,
    contrat_id INT NOT NULL,
    
    -- Description
    piece VARCHAR(100) NOT NULL,
    element VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    
    -- Coûts
    cout_reparation DECIMAL(10,2),
    taux_vetuste DECIMAL(5,2) DEFAULT 0,
    cout_final DECIMAL(10,2),
    
    -- Photos
    photos JSON,
    
    statut ENUM('identifie', 'evalue', 'facture', 'paye') DEFAULT 'identifie',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (etat_lieux_id) REFERENCES etats_lieux(id) ON DELETE CASCADE,
    FOREIGN KEY (contrat_id) REFERENCES contrats(id) ON DELETE CASCADE,
    INDEX idx_etat_lieux (etat_lieux_id),
    INDEX idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- PAIEMENTS ET DÉPÔTS
-- =====================================================

CREATE TABLE IF NOT EXISTS paiements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contrat_id INT NOT NULL,
    type ENUM('depot_garantie', 'loyer', 'charges', 'remboursement_depot', 'reparation', 'autre') NOT NULL,
    
    montant DECIMAL(10,2) NOT NULL,
    date_paiement DATE,
    mode_paiement VARCHAR(50),
    reference_paiement VARCHAR(100),
    
    statut ENUM('attendu', 'recu', 'rembourse') DEFAULT 'attendu',
    notes TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (contrat_id) REFERENCES contrats(id) ON DELETE CASCADE,
    INDEX idx_contrat (contrat_id),
    INDEX idx_type (type),
    INDEX idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- LOGS ET TRAÇABILITÉ
-- =====================================================

CREATE TABLE IF NOT EXISTS logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_entite ENUM('candidature', 'contrat', 'logement', 'paiement', 'etat_lieux', 'autre') NOT NULL,
    entite_id INT,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_type_entite (type_entite, entite_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ADMINISTRATEURS
-- =====================================================

CREATE TABLE IF NOT EXISTS administrateurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    nom VARCHAR(100),
    prenom VARCHAR(100),
    role ENUM('admin', 'gestionnaire', 'comptable') DEFAULT 'gestionnaire',
    actif BOOLEAN DEFAULT TRUE,
    derniere_connexion TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_username (username),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- DONNÉES DE TEST
-- =====================================================

-- Logement RP-01
INSERT INTO logements (reference, adresse, type, surface, loyer, charges, depot_garantie, parking, statut, description) 
VALUES (
    'RP-01', 
    '15 rue de la Paix, 74100 Annemasse', 
    'T1 Bis', 
    26.00, 
    890.00, 
    140.00, 
    1780.00, 
    'Aucun',
    'disponible',
    'Logement meublé conforme au décret n°2015-981. Cuisine équipée, installations sanitaires complètes.'
)
ON DUPLICATE KEY UPDATE reference=reference;

-- Administrateur par défaut (password: password - À CHANGER EN PRODUCTION)
INSERT INTO administrateurs (username, password_hash, email, nom, prenom, role) 
VALUES (
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password
    'contact@myinvest-immobilier.com',
    'ALEXANDRE',
    'Maxime',
    'admin'
)
ON DUPLICATE KEY UPDATE username=username;

-- =====================================================
-- VUES UTILES
-- =====================================================

-- Vue pour les candidatures en attente de réponse automatique
CREATE OR REPLACE VIEW candidatures_a_traiter AS
SELECT 
    c.*,
    l.reference as logement_reference,
    l.adresse as logement_adresse,
    DATEDIFF(NOW(), c.date_soumission) as jours_depuis_soumission,
    CASE 
        WHEN c.revenus_mensuels = '< 2300' THEN 0
        WHEN c.statut_professionnel = 'CDI' AND c.periode_essai = 'Dépassée' AND c.revenus_mensuels IN ('2300-3000', '3000+') THEN 1
        WHEN c.statut_professionnel = 'CDD' AND c.revenus_mensuels = '3000+' THEN 1
        ELSE 0
    END as criteres_valides
FROM candidatures c
LEFT JOIN logements l ON c.logement_id = l.id
WHERE c.reponse_automatique = 'en_attente'
AND c.statut = 'en_cours';

-- Vue pour le tableau de bord admin
CREATE OR REPLACE VIEW dashboard_stats AS
SELECT 
    (SELECT COUNT(*) FROM candidatures WHERE statut = 'en_cours') as candidatures_en_cours,
    (SELECT COUNT(*) FROM candidatures WHERE statut = 'accepte') as candidatures_acceptees,
    (SELECT COUNT(*) FROM candidatures WHERE statut = 'refuse') as candidatures_refusees,
    (SELECT COUNT(*) FROM contrats WHERE statut = 'actif') as contrats_actifs,
    (SELECT COUNT(*) FROM logements WHERE statut = 'disponible') as logements_disponibles,
    (SELECT COUNT(*) FROM candidatures WHERE date_soumission >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as candidatures_semaine;
