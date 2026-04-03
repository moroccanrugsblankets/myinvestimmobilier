<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/inventaire-standard-items.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $logement_id = (int)$_POST['logement_id'];
    $type = $_POST['type'];
    $date_inventaire = $_POST['date_inventaire'];
    
    // Validate inputs
    if (!in_array($type, ['entree', 'sortie'])) {
        $_SESSION['error'] = "Type d'inventaire invalide";
        header('Location: inventaires.php');
        exit;
    }
    
    // Validate date format and reasonableness
    $date = DateTime::createFromFormat('Y-m-d', $date_inventaire);
    if (!$date || $date->format('Y-m-d') !== $date_inventaire) {
        $_SESSION['error'] = "Format de date invalide";
        header('Location: inventaires.php');
        exit;
    }
    
    // Check date is not too far in the past or future (within 5 years)
    $now = new DateTime();
    $diff = $now->diff($date);
    if ($diff->y > 5) {
        $_SESSION['error'] = "La date ne peut pas être à plus de 5 ans dans le passé ou le futur";
        header('Location: inventaires.php');
        exit;
    }
    
    // Get logement info
    $stmt = $pdo->prepare("
        SELECT l.*
        FROM logements l
        WHERE l.id = ?
    ");
    $stmt->execute([$logement_id]);
    $logement = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$logement) {
        $_SESSION['error'] = "Logement introuvable";
        header('Location: inventaires.php');
        exit;
    }
    
    // Find the active contract for this logement
    $stmt = $pdo->prepare("
        SELECT c.*
        FROM contrats c
        WHERE c.logement_id = ? AND c.statut = 'valide'
        ORDER BY c.date_creation DESC
        LIMIT 1
    ");
    $stmt->execute([$logement_id]);
    $contrat = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contrat) {
        $_SESSION['error'] = "Aucun contrat validé trouvé pour ce logement";
        header('Location: inventaires.php');
        exit;
    }
    
    $contrat_id = $contrat['id'];
    
    // Get tenant(s) from contract
    $stmt = $pdo->prepare("SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 2");
    $stmt->execute([$contrat_id]);
    $locataires = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($locataires)) {
        $_SESSION['error'] = "Aucun locataire trouvé pour ce contrat";
        header('Location: inventaires.php');
        exit;
    }
    
    // Build locataire_nom_complet from all tenants
    $locataire_noms = array_map(function($loc) {
        return $loc['prenom'] . ' ' . $loc['nom'];
    }, $locataires);
    $locataire_nom_complet = implode(' et ', $locataire_noms);
    $locataire_email = $locataires[0]['email']; // Use first tenant's email
    
    // Check for duplicate (exclude soft-deleted inventories)
    $stmt = $pdo->prepare("SELECT id FROM inventaires WHERE contrat_id = ? AND type = ? AND deleted_at IS NULL");
    $stmt->execute([$contrat_id, $type]);
    if ($stmt->fetch()) {
        $_SESSION['error'] = "Un inventaire de ce type existe déjà pour ce contrat";
        header('Location: inventaires.php');
        exit;
    }
    
    // Generate unique reference
    $reference = 'INV-' . strtoupper($type[0]) . '-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Use standardized inventory items (not logement-specific equipment)
    $equipements_data = generateStandardInventoryData();
    
    // Insert new inventaire
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO inventaires (
                contrat_id, logement_id, type, date_inventaire, reference_unique,
                adresse,
                locataire_nom_complet, locataire_email,
                bailleur_nom,
                equipements_data,
                statut, created_at, created_by
            ) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'brouillon', NOW(), ?)
        ");
        
        $stmt->execute([
            $contrat_id,
            $logement_id,
            $type,
            $date_inventaire,
            $reference,
            $logement['adresse'],
            $locataire_nom_complet,
            $locataire_email,
            'SCI My Invest Immobilier, représentée par Maxime ALEXANDRE',
            json_encode($equipements_data, JSON_UNESCAPED_UNICODE),
            $_SESSION['username'] ?? 'admin'
        ]);
        
        $inventaire_id = $pdo->lastInsertId();
        
        // Insert tenant records for signatures
        foreach ($locataires as $loc) {
            $stmt = $pdo->prepare("
                INSERT INTO inventaire_locataires (inventaire_id, locataire_id, nom, prenom, email)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $inventaire_id,
                $loc['id'],
                $loc['nom'],
                $loc['prenom'],
                $loc['email']
            ]);
        }
        
        $pdo->commit();
        
        $_SESSION['success'] = "Inventaire créé avec succès";
        header("Location: edit-inventaire.php?id=$inventaire_id");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur lors de la création de l'inventaire: " . $e->getMessage();
        error_log("Erreur création inventaire: " . $e->getMessage());
        header('Location: inventaires.php');
        exit;
    }
}

// Should not reach here - redirect if accessed without POST
header('Location: inventaires.php');
exit;
