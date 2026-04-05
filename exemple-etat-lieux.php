<?php
/**
 * Exemple d'utilisation du module État des lieux
 * 
 * Ce fichier montre comment intégrer le module État des lieux
 * dans votre workflow de gestion locative.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/pdf/generate-etat-lieux.php';

// =============================================================================
// EXEMPLE 1: Générer un état des lieux d'entrée simple
// =============================================================================

function exemple1_etat_lieux_entree_simple() {
    echo "\n=== Exemple 1: État des lieux d'entrée simple ===\n";
    
    $contratId = 123; // ID du contrat
    
    // Générer le PDF avec les données par défaut
    $pdfPath = generateEtatDesLieuxPDF($contratId, 'entree');
    
    if ($pdfPath) {
        echo "✓ PDF généré: $pdfPath\n";
        
        // Envoyer par email
        $emailSent = sendEtatDesLieuxEmail($contratId, 'entree', $pdfPath);
        
        if ($emailSent) {
            echo "✓ Email envoyé avec succès\n";
        } else {
            echo "✗ Erreur lors de l'envoi de l'email\n";
        }
    } else {
        echo "✗ Erreur lors de la génération du PDF\n";
    }
}

// =============================================================================
// EXEMPLE 2: Créer un état des lieux d'entrée avec données personnalisées
// =============================================================================

function exemple2_etat_lieux_entree_personnalise() {
    global $pdo;
    
    echo "\n=== Exemple 2: État des lieux d'entrée personnalisé ===\n";
    
    $contratId = 123;
    
    // 1. Créer l'état des lieux avec données personnalisées
    $referenceUnique = 'EDL-ENTREE-' . time();
    
    $stmt = $pdo->prepare("
        INSERT INTO etat_lieux (
            contrat_id,
            type,
            reference_unique,
            date_etat,
            adresse,
            
            bailleur_nom,
            bailleur_representant,
            compteur_electricite,
            compteur_eau_froide,
            cles_
            cles_boite_lettres,
            cles_total,
            etat_logement,
            etat_general,
            lieu_signature,
            statut
        ) VALUES (?, 'entree', ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'brouillon')
    ");
    
    $stmt->execute([
        $contratId,
        $referenceUnique,
        '123 Avenue des Champs-Élysées',
        'Appartement 4B',
        'MY INVEST IMMOBILIER',
        'Maxime Alexandre',
        '12345',  // Compteur électricité
        '67890',  // Compteur eau froide
        2,        // Clés appartement
        1,        // Clés boîte aux lettres
        3,        // Total clés
        'Pièce principale en excellent état. Parquet récemment rénové. Murs fraîchement peints en blanc.',
        'Cuisine équipée complète. Four, plaque de cuisson, réfrigérateur en parfait état de fonctionnement.',
        'Salle de bain avec douche italienne. Carrelage neuf. Robinetterie moderne.',
        'Le logement est en excellent état général, propre et prêt à être habité.',
        'Nice'
    ]);
    
    echo "✓ État des lieux créé avec ID: " . $pdo->lastInsertId() . "\n";
    
    // 2. Générer le PDF
    $pdfPath = generateEtatDesLieuxPDF($contratId, 'entree');
    
    if ($pdfPath) {
        echo "✓ PDF généré: $pdfPath\n";
        
        // 3. Envoyer par email
        sendEtatDesLieuxEmail($contratId, 'entree', $pdfPath);
        echo "✓ Email envoyé\n";
    }
}

// =============================================================================
// EXEMPLE 3: Créer un état des lieux de sortie avec conclusion
// =============================================================================

function exemple3_etat_lieux_sortie_avec_conclusion() {
    global $pdo;
    
    echo "\n=== Exemple 3: État des lieux de sortie avec conclusion ===\n";
    
    $contratId = 123;
    
    // Créer l'état des lieux de sortie
    $referenceUnique = 'EDL-SORTIE-' . time();
    
    $stmt = $pdo->prepare("
        INSERT INTO etat_lieux (
            contrat_id,
            type,
            reference_unique,
            date_etat,
            adresse,
            
            bailleur_nom,
            compteur_electricite,
            compteur_eau_froide,
            cles_
            cles_boite_lettres,
            cles_total,
            cles_conformite,
            cles_observations,
            etat_logement,
            etat_general,
            comparaison_entree,
            depot_garantie_status,
            depot_garantie_montant_retenu,
            depot_garantie_motif_retenue,
            lieu_signature,
            statut
        ) VALUES (
            ?, 'sortie', ?, CURDATE(), ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, 'brouillon'
        )
    ");
    
    $stmt->execute([
        $contratId,
        $referenceUnique,
        '123 Avenue des Champs-Élysées',
        'Appartement 4B',
        'MY INVEST IMMOBILIER',
        '45678',  // Nouveau compteur électricité
        '91011',  // Nouveau compteur eau froide
        2,        // Clés appartement restituées
        1,        // Clés boîte aux lettres restituées
        3,        // Total clés
        'conforme',  // Conformité
        'Toutes les clés ont été restituées',
        'Pièce principale en bon état. Quelques traces d\'usure normale sur le parquet.',
        'Cuisine propre et bien entretenue. Tous les équipements fonctionnels.',
        'Salle de bain en bon état. Nettoyage complet effectué.',
        'Le logement a été bien entretenu durant la location. Usure normale constatée.',
        'Comparé à l\'état des lieux d\'entrée, le logement présente une usure normale. Aucune dégradation majeure constatée.',
        'restitution_totale',  // Statut du dépôt de garantie
        0,  // Montant retenu
        '',  // Motif retenue
        'Nice'
    ]);
    
    echo "✓ État des lieux de sortie créé\n";
    
    // Générer le PDF
    $pdfPath = generateEtatDesLieuxPDF($contratId, 'sortie');
    
    if ($pdfPath) {
        echo "✓ PDF généré: $pdfPath\n";
        sendEtatDesLieuxEmail($contratId, 'sortie', $pdfPath);
        echo "✓ Email envoyé\n";
    }
}

// =============================================================================
// EXEMPLE 4: Retenue partielle sur le dépôt de garantie
// =============================================================================

function exemple4_depot_garantie_retenue_partielle() {
    global $pdo;
    
    echo "\n=== Exemple 4: Sortie avec retenue partielle ===\n";
    
    $contratId = 123;
    $referenceUnique = 'EDL-SORTIE-' . time();
    
    $stmt = $pdo->prepare("
        INSERT INTO etat_lieux (
            contrat_id,
            type,
            reference_unique,
            date_etat,
            adresse,
            
            compteur_electricite,
            compteur_eau_froide,
            cles_
            cles_boite_lettres,
            cles_total,
            cles_conformite,
            etat_logement,
            etat_general,
            comparaison_entree,
            depot_garantie_status,
            depot_garantie_montant_retenu,
            depot_garantie_motif_retenue,
            statut
        ) VALUES (
            ?, 'sortie', ?, CURDATE(), ?, ?,
            ?, ?, ?, ?, ?, 'non_conforme',
            ?, ?, ?, ?, ?, 'restitution_partielle', ?, ?, 'brouillon'
        )
    ");
    
    $stmt->execute([
        $contratId,
        $referenceUnique,
        '123 Avenue des Champs-Élysées',
        'Appartement 4B',
        '45678', '91011',
        1, 1, 2, // Une clé manquante
        'Trous dans le mur nécessitant réparation. Parquet rayé.',
        'Four défectueux. Hotte endommagée.',
        'Carrelage fissuré dans la douche.',
        'Plusieurs dégradations constatées nécessitant des réparations.',
        'Plusieurs dégradations constatées par rapport à l\'état d\'entrée.',
        'restitution_partielle',
        450.00,  // Montant retenu
        'Réparation des murs (150€), remplacement du four (200€), réparation carrelage (50€), clé manquante (50€)'
    ]);
    
    echo "✓ État des lieux avec retenue créé\n";
    
    // Générer et envoyer
    $pdfPath = generateEtatDesLieuxPDF($contratId, 'sortie');
    if ($pdfPath) {
        echo "✓ PDF généré: $pdfPath\n";
        sendEtatDesLieuxEmail($contratId, 'sortie', $pdfPath);
        echo "✓ Email envoyé avec détails de la retenue\n";
    }
}

// =============================================================================
// EXEMPLE 5: Ajouter des photos (usage interne uniquement)
// =============================================================================

function exemple5_ajouter_photos() {
    global $pdo;
    
    echo "\n=== Exemple 5: Ajouter des photos ===\n";
    
    $etatLieuxId = 1;  // ID de l'état des lieux
    
    // Créer le répertoire pour les photos
    $uploadDir = __DIR__ . '/uploads/etat_lieux_photos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
        echo "✓ Répertoire créé: $uploadDir\n";
    }
    
    // Simuler l'ajout de photos
    $photos = [
        [
            'categorie' => 'compteur_electricite',
            'nom_fichier' => 'compteur_elec_' . time() . '.jpg',
            'description' => 'Photo du compteur électricité à l\'entrée'
        ],
        [
            'categorie' => 'compteur_eau',
            'nom_fichier' => 'compteur_eau_' . time() . '.jpg',
            'description' => 'Photo du compteur eau froide à l\'entrée'
        ],
        [
            'categorie' => 'cles',
            'nom_fichier' => 'cles_' . time() . '.jpg',
            'description' => 'Photo des clés remises au locataire'
        ]
    ];
    
    foreach ($photos as $photo) {
        $cheminFichier = $uploadDir . $photo['nom_fichier'];
        
        // Dans un cas réel, vous déplaceriez le fichier uploadé ici
        // move_uploaded_file($_FILES['photo']['tmp_name'], $cheminFichier);
        
        // Enregistrer dans la base de données
        $stmt = $pdo->prepare("
            INSERT INTO etat_lieux_photos (
                etat_lieux_id,
                categorie,
                nom_fichier,
                chemin_fichier,
                description,
                ordre
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $etatLieuxId,
            $photo['categorie'],
            $photo['nom_fichier'],
            $cheminFichier,
            $photo['description'],
            0
        ]);
        
        echo "✓ Photo ajoutée: {$photo['nom_fichier']}\n";
    }
    
    echo "\nNote: Les photos sont conservées en interne uniquement.\n";
    echo "Elles ne sont PAS incluses dans le PDF envoyé au locataire.\n";
}

// =============================================================================
// EXEMPLE 6: Workflow complet - De l'entrée à la sortie
// =============================================================================

function exemple6_workflow_complet() {
    echo "\n=== Exemple 6: Workflow complet ===\n";
    
    $contratId = 123;
    
    // ÉTAPE 1: État des lieux d'entrée lors de la prise de possession
    echo "\n--- ÉTAPE 1: État des lieux d'entrée ---\n";
    $pdfEntree = generateEtatDesLieuxPDF($contratId, 'entree');
    if ($pdfEntree) {
        echo "✓ État des lieux d'entrée généré\n";
        sendEtatDesLieuxEmail($contratId, 'entree', $pdfEntree);
        echo "✓ Email envoyé au locataire\n";
    }
    
    // ÉTAPE 2: Pendant la location (pas d'état des lieux)
    echo "\n--- ÉTAPE 2: Pendant la location ---\n";
    echo "Le locataire occupe le logement...\n";
    
    // ÉTAPE 3: État des lieux de sortie à la fin du bail
    echo "\n--- ÉTAPE 3: État des lieux de sortie ---\n";
    $pdfSortie = generateEtatDesLieuxPDF($contratId, 'sortie');
    if ($pdfSortie) {
        echo "✓ État des lieux de sortie généré\n";
        sendEtatDesLieuxEmail($contratId, 'sortie', $pdfSortie);
        echo "✓ Email envoyé au locataire\n";
    }
    
    echo "\n✓ Workflow complet terminé\n";
}

// =============================================================================
// EXEMPLE 7: Intégration dans le processus de signature
// =============================================================================

function exemple7_integration_signature() {
    echo "\n=== Exemple 7: Intégration avec signature de bail ===\n";
    
    $contratId = 123;
    
    // Après la signature du bail par tous les locataires
    echo "1. Bail signé par tous les locataires ✓\n";
    
    // Générer automatiquement l'état des lieux d'entrée
    echo "2. Génération automatique de l'état des lieux d'entrée...\n";
    $pdfPath = generateEtatDesLieuxPDF($contratId, 'entree');
    
    if ($pdfPath) {
        echo "   ✓ PDF généré: $pdfPath\n";
        
        // Envoyer l'email avec l'état des lieux
        echo "3. Envoi de l'email au locataire...\n";
        $emailSent = sendEtatDesLieuxEmail($contratId, 'entree', $pdfPath);
        
        if ($emailSent) {
            echo "   ✓ Email envoyé\n";
            echo "   ✓ Copie envoyée à gestion@myinvest-immobilier.com\n";
        }
        
        echo "\n✓ Le locataire a reçu:\n";
        echo "  - Le contrat de bail signé\n";
        echo "  - L'état des lieux d'entrée\n";
        echo "\nProchaines étapes:\n";
        echo "  - Versement du dépôt de garantie\n";
        echo "  - Remise des clés\n";
    }
}

// =============================================================================
// INSTRUCTIONS D'UTILISATION
// =============================================================================

echo "\n";
echo "=============================================================================\n";
echo "EXEMPLES D'UTILISATION DU MODULE ÉTAT DES LIEUX\n";
echo "=============================================================================\n";
echo "\n";
echo "Ce fichier contient 7 exemples d'utilisation:\n";
echo "\n";
echo "1. exemple1_etat_lieux_entree_simple()\n";
echo "   - Génération simple avec données par défaut\n";
echo "\n";
echo "2. exemple2_etat_lieux_entree_personnalise()\n";
echo "   - Création avec données personnalisées\n";
echo "\n";
echo "3. exemple3_etat_lieux_sortie_avec_conclusion()\n";
echo "   - État de sortie avec conclusion et dépôt de garantie\n";
echo "\n";
echo "4. exemple4_depot_garantie_retenue_partielle()\n";
echo "   - Gestion d'une retenue sur le dépôt\n";
echo "\n";
echo "5. exemple5_ajouter_photos()\n";
echo "   - Ajout de photos (usage interne)\n";
echo "\n";
echo "6. exemple6_workflow_complet()\n";
echo "   - Workflow complet de l'entrée à la sortie\n";
echo "\n";
echo "7. exemple7_integration_signature()\n";
echo "   - Intégration avec le processus de signature\n";
echo "\n";
echo "=============================================================================\n";
echo "\n";
echo "Pour exécuter un exemple, décommentez l'appel de fonction ci-dessous:\n";
echo "\n";

// Décommentez l'exemple que vous souhaitez tester:
// exemple1_etat_lieux_entree_simple();
// exemple2_etat_lieux_entree_personnalise();
// exemple3_etat_lieux_sortie_avec_conclusion();
// exemple4_depot_garantie_retenue_partielle();
// exemple5_ajouter_photos();
// exemple6_workflow_complet();
// exemple7_integration_signature();

echo "Note: Ce fichier nécessite une connexion à la base de données.\n";
echo "Assurez-vous d'avoir exécuté la migration 021 avant de tester.\n";
echo "\n";
