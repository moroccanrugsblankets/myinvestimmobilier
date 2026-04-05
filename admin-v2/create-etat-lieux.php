<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

// Handle GET request with contrat_id (direct link from contrat-detail.php)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['contrat_id'])) {
    $contrat_id = (int)$_GET['contrat_id'];
    $type = $_GET['type'] ?? '';

    if (!in_array($type, ['entree', 'sortie'])) {
        $_SESSION['error'] = "Type d'état des lieux invalide";
        header('Location: etats-lieux.php');
        exit;
    }

    if (!$contrat_id) {
        $_SESSION['error'] = "ID de contrat invalide";
        header('Location: etats-lieux.php');
        exit;
    }

    // Get contract and logement info
    $stmt = $pdo->prepare("
        SELECT c.*,
               l.adresse,
               l.default_cles_appartement, l.default_cles_boite_lettres,
               l.default_etat_logement
        FROM contrats c
        LEFT JOIN logements l ON c.logement_id = l.id
        WHERE c.id = ? AND c.statut = 'valide'
    ");
    $stmt->execute([$contrat_id]);
    $contrat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contrat) {
        $_SESSION['error'] = "Contrat validé introuvable";
        header('Location: etats-lieux.php');
        exit;
    }

    // Get tenant(s)
    $stmt = $pdo->prepare("SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 2");
    $stmt->execute([$contrat_id]);
    $locataires = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($locataires)) {
        $_SESSION['error'] = "Aucun locataire trouvé pour ce contrat";
        header('Location: etats-lieux.php');
        exit;
    }

    $locataire_noms = array_map(function($loc) {
        return $loc['prenom'] . ' ' . $loc['nom'];
    }, $locataires);
    $locataire_nom_complet = implode(' et ', $locataire_noms);
    $locataire_email = $locataires[0]['email'];

    // Check for duplicate
    $stmt = $pdo->prepare("SELECT id FROM etats_lieux WHERE contrat_id = ? AND type = ?");
    $stmt->execute([$contrat_id, $type]);
    if ($existing = $stmt->fetch()) {
        // Already exists – redirect to its edit page
        header('Location: edit-etat-lieux.php?id=' . $existing['id']);
        exit;
    }

    $date_etat = date('Y-m-d');
    $reference = 'EDL-' . strtoupper($type[0]) . '-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

    $default_cles_appartement = null;
    $default_cles_boite_lettres = null;
    $default_cles_autre = null;
    $default_cles_total = null;
    $default_cles_observations = null;
    $default_compteur_electricite = null;
    $default_compteur_eau_froide = null;
    $default_etat_logement = null;
    $default_etat_general = null;
    $default_observations = null;
    $etat_entree_id = null;

    if ($type === 'entree') {
        $default_cles_appartement = (int)($contrat['default_cles_appartement'] ?? 2);
        $default_cles_boite_lettres = (int)($contrat['default_cles_boite_lettres'] ?? 1);
        $default_cles_autre = 0;
        $default_cles_total = $default_cles_appartement + $default_cles_boite_lettres;

        $default_room_description = "• Revêtement de sol : parquet très bon état d'usage\n• Murs : peintures très bon état\n• Plafond : peintures très bon état\n• Installations électriques et plomberie : fonctionnelles";

        $default_etat_logement = $contrat['default_etat_logement'] ?? $default_room_description;
        $default_etat_general = "Le logement a fait l'objet d'une remise en état générale avant l'entrée dans les lieux.\nIl est propre, entretenu et ne présente aucune dégradation apparente au jour de l'état des lieux.\nAucune anomalie constatée.";
    } elseif ($type === 'sortie') {
        $stmt = $pdo->prepare("SELECT id FROM etats_lieux WHERE contrat_id = ? AND type = 'entree' ORDER BY date_etat DESC LIMIT 1");
        $stmt->execute([$contrat_id]);
        $etat_entree = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($etat_entree) {
            $etat_entree_id = $etat_entree['id'];
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO etats_lieux (
                contrat_id, type, date_etat, reference_unique,
                adresse,
                bailleur_nom, locataire_nom_complet, locataire_email,
                compteur_electricite, compteur_eau_froide,
                cles_appartement, cles_boite_lettres, cles_autre, cles_total, cles_observations,
                etat_logement,
                etat_general, observations,
                statut, created_at, created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'brouillon', NOW(), ?)
        ");

        $stmt->execute([
            $contrat_id,
            $type,
            $date_etat,
            $reference,
            $contrat['adresse'],
            'SCI My Invest Immobilier, représentée par Maxime ALEXANDRE',
            $locataire_nom_complet,
            $locataire_email,
            $default_compteur_electricite,
            $default_compteur_eau_froide,
            $default_cles_appartement,
            $default_cles_boite_lettres,
            $default_cles_autre,
            $default_cles_total,
            $default_cles_observations,
            $default_etat_logement,
            $default_etat_general,
            $default_observations,
            $_SESSION['username'] ?? 'admin'
        ]);

        $etat_lieux_id = $pdo->lastInsertId();
        header("Location: edit-etat-lieux.php?id=$etat_lieux_id");
        exit;
    } catch (PDOException $e) {
        error_log("Error creating état des lieux: " . $e->getMessage());
        $_SESSION['error'] = "Erreur lors de la création de l'état des lieux";
        header('Location: etats-lieux.php');
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $logement_id = (int)$_POST['logement_id'];
    $type = $_POST['type'];
    $date_etat = $_POST['date_etat'];
    
    // Validate inputs
    if (!in_array($type, ['entree', 'sortie'])) {
        $_SESSION['error'] = "Type d'état des lieux invalide";
        header('Location: etats-lieux.php');
        exit;
    }
    
    // Validate date format and reasonableness
    $date = DateTime::createFromFormat('Y-m-d', $date_etat);
    if (!$date || $date->format('Y-m-d') !== $date_etat) {
        $_SESSION['error'] = "Format de date invalide";
        header('Location: etats-lieux.php');
        exit;
    }
    
    // Check date is not too far in the past or future (within 5 years)
    $now = new DateTime();
    $diff = $now->diff($date);
    if ($diff->y > 5) {
        $_SESSION['error'] = "La date ne peut pas être à plus de 5 ans dans le passé ou le futur";
        header('Location: etats-lieux.php');
        exit;
    }
    
    // Find the active contract for this logement
    $stmt = $pdo->prepare("
        SELECT c.*,
               l.adresse, 
               l.default_cles_appartement, l.default_cles_boite_lettres,
               l.default_etat_logement
        FROM contrats c
        LEFT JOIN logements l ON c.logement_id = l.id
        WHERE c.logement_id = ? AND c.statut = 'valide'
        ORDER BY c.date_creation DESC
        LIMIT 1
    ");
    $stmt->execute([$logement_id]);
    $contrat = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contrat) {
        $_SESSION['error'] = "Aucun contrat validé trouvé pour ce logement";
        header('Location: etats-lieux.php');
        exit;
    }
    
    $contrat_id = $contrat['id'];
    
    // Get tenant(s) from contract
    $stmt = $pdo->prepare("SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 2");
    $stmt->execute([$contrat_id]);
    $locataires = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($locataires)) {
        $_SESSION['error'] = "Aucun locataire trouvé pour ce contrat";
        header('Location: etats-lieux.php');
        exit;
    }
    
    // Build locataire_nom_complet from all tenants
    $locataire_noms = array_map(function($loc) {
        return $loc['prenom'] . ' ' . $loc['nom'];
    }, $locataires);
    $locataire_nom_complet = implode(' et ', $locataire_noms);
    $locataire_email = $locataires[0]['email']; // Use first tenant's email
    
    // Check for duplicate
    $stmt = $pdo->prepare("SELECT id FROM etats_lieux WHERE contrat_id = ? AND type = ?");
    $stmt->execute([$contrat_id, $type]);
    if ($stmt->fetch()) {
        $_SESSION['error'] = "Un état des lieux de ce type existe déjà pour ce contrat";
        header('Location: etats-lieux.php');
        exit;
    }
    
    // Generate unique reference
    $reference = 'EDL-' . strtoupper($type[0]) . '-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Prepare default values
    $default_cles_appartement = null;
    $default_cles_boite_lettres = null;
    $default_cles_autre = null;
    $default_cles_total = null;
    $default_cles_observations = null;
    $default_compteur_electricite = null;
    $default_compteur_eau_froide = null;
    $default_etat_logement = null;
    $default_etat_general = null;
    $default_observations = null;
    $etat_entree_id = null;
    
    // For entry: use logement defaults
    if ($type === 'entree') {
        $default_cles_appartement = (int)($contrat['default_cles_appartement'] ?? 2);
        $default_cles_boite_lettres = (int)($contrat['default_cles_boite_lettres'] ?? 1);
        $default_cles_autre = 0;
        $default_cles_total = $default_cles_appartement + $default_cles_boite_lettres;
        
        // Default room description template - used for main room
        $default_room_description = "• Revêtement de sol : parquet très bon état d'usage\n• Murs : peintures très bon état\n• Plafond : peintures très bon état\n• Installations électriques et plomberie : fonctionnelles";
        
        $default_etat_logement = $contrat['default_etat_logement'] ?? $default_room_description;
        
        $default_etat_general = "Le logement a fait l'objet d'une remise en état générale avant l'entrée dans les lieux.\nIl est propre, entretenu et ne présente aucune dégradation apparente au jour de l'état des lieux.\nAucune anomalie constatée.";
    }
    // For exit: DO NOT copy data - leave fields empty for user input
    // Entry data will be displayed as visual reference only in the edit form
    else if ($type === 'sortie') {
        // Find entry state to verify it exists (required for visual reference)
        $stmt = $pdo->prepare("SELECT id FROM etats_lieux WHERE contrat_id = ? AND type = 'entree' ORDER BY date_etat DESC LIMIT 1");
        $stmt->execute([$contrat_id]);
        $etat_entree = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($etat_entree) {
            // Store entry ID for visual reference display later (not for copying)
            $etat_entree_id = $etat_entree['id'];
        }
        
        // All fields remain NULL/empty - user will enter exit values manually
        // No auto-copy from entry state
    }
    
    // Insert new état des lieux with initial data
    try {
        // Prepare INSERT with comprehensive field list
        // Fields organized by category for maintainability
        $stmt = $pdo->prepare("
            INSERT INTO etats_lieux (
                -- Basic identification
                contrat_id, type, date_etat, reference_unique,
                adresse, 
                -- Participants
                bailleur_nom, locataire_nom_complet, locataire_email,
                -- Meter readings (compteurs)
                compteur_electricite, compteur_eau_froide,
                -- Keys (clés)
                cles_appartement, cles_boite_lettres, cles_autre, cles_total, cles_observations,
                -- Room descriptions
                etat_logement, 
                -- General state and observations
                etat_general, observations,
                -- Metadata
                statut, created_at, created_by
            ) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'brouillon', NOW(), ?)
        ");
        
        // Execute with parameters in same order as field list above
        $stmt->execute([
            // Basic identification (4 params)
            $contrat_id, 
            $type, 
            $date_etat, 
            $reference,
            // Address info (1 param)
            $contrat['adresse'],
            // Participants (3 params)
            'SCI My Invest Immobilier, représentée par Maxime ALEXANDRE',
            $locataire_nom_complet,
            $locataire_email,
            // Meter readings (2 params)
            $default_compteur_electricite,
            $default_compteur_eau_froide,
            // Keys (5 params)
            $default_cles_appartement,
            $default_cles_boite_lettres,
            $default_cles_autre,
            $default_cles_total,
            $default_cles_observations,
            // Room description (1 param)
            $default_etat_logement,
            // General state (2 params)
            $default_etat_general,
            $default_observations,
            // Metadata (1 param)
            $_SESSION['username'] ?? 'admin'
        ]);
        
        $etat_lieux_id = $pdo->lastInsertId();
        
        // For exit state: DO NOT copy photos automatically
        // Photos will be displayed as reference only in the edit form
        // User can add new photos for the exit state independently
        
        // Redirect to comprehensive form
        header("Location: edit-etat-lieux.php?id=$etat_lieux_id");
        exit;
        
    } catch (PDOException $e) {
        error_log("Error creating état des lieux: " . $e->getMessage());
        $_SESSION['error'] = "Erreur lors de la création de l'état des lieux";
        header('Location: etats-lieux.php');
        exit;
    }
}

header('Location: etats-lieux.php');
exit;
