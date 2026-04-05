<?php
/**
 * Action : Restaurer un contrat supprimé ou clôturé
 * - Contrat supprimé (deleted_at IS NOT NULL) : efface deleted_at et restaure les enregistrements liés
 * - Contrat clôturé (statut = 'fin') : remet le statut à 'valide'
 */
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contrats.php');
    exit;
}

$contrat_id = isset($_POST['contrat_id']) ? (int)$_POST['contrat_id'] : 0;
$source = isset($_POST['source']) ? $_POST['source'] : 'supprimes';

if (!$contrat_id) {
    $_SESSION['error'] = "ID de contrat invalide";
    header('Location: contrats.php');
    exit;
}

// Get contract details (including soft-deleted ones, using contrat_logement for frozen reference)
$stmt = $pdo->prepare("
    SELECT c.*, COALESCE(cl.reference, l.reference) as logement_ref
    FROM contrats c
    LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
    LEFT JOIN logements l ON c.logement_id = l.id
    WHERE c.id = ?
");
$stmt->execute([$contrat_id]);
$contrat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contrat) {
    $_SESSION['error'] = "Contrat introuvable";
    header('Location: contrats.php');
    exit;
}

try {
    $pdo->beginTransaction();

    if ($contrat['deleted_at'] !== null) {
        // Restore soft-deleted contract: clear deleted_at
        $pdo->prepare("UPDATE contrats SET deleted_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$contrat_id]);

        // Restore related soft-deleted records
        $pdo->prepare("UPDATE etats_lieux SET deleted_at = NULL WHERE contrat_id = ? AND deleted_at IS NOT NULL")->execute([$contrat_id]);
        $pdo->prepare("UPDATE inventaires SET deleted_at = NULL WHERE contrat_id = ? AND deleted_at IS NOT NULL")->execute([$contrat_id]);
        $pdo->prepare("UPDATE quittances SET deleted_at = NULL WHERE contrat_id = ? AND deleted_at IS NOT NULL")->execute([$contrat_id]);
        $pdo->prepare("UPDATE loyers_tracking SET deleted_at = NULL WHERE contrat_id = ? AND deleted_at IS NOT NULL")->execute([$contrat_id]);

        // Restore candidature status if linked
        if ($contrat['candidature_id']) {
            $pdo->prepare("UPDATE candidatures SET statut = 'contrat_envoye' WHERE id = ?")->execute([$contrat['candidature_id']]);
        }

        // Mark logement as occupied
        if ($contrat['logement_id']) {
            $pdo->prepare("UPDATE logements SET statut = 'en_location' WHERE id = ?")->execute([$contrat['logement_id']]);
        }

        $action = 'contrat_restaure_supprime';
        $details = "Contrat {$contrat['reference_unique']} restauré depuis la corbeille";

    } elseif ($contrat['statut'] === 'fin') {
        // Restore closed contract: revert status to 'valide'
        $pdo->prepare("UPDATE contrats SET statut = 'valide', updated_at = NOW() WHERE id = ?")->execute([$contrat_id]);

        // Mark logement as occupied again
        if ($contrat['logement_id']) {
            $pdo->prepare("UPDATE logements SET statut = 'en_location' WHERE id = ?")->execute([$contrat['logement_id']]);
        }

        $action = 'contrat_restaure_cloture';
        $details = "Contrat {$contrat['reference_unique']} restauré depuis les contrats clôturés";

    } else {
        $pdo->rollBack();
        $_SESSION['error'] = "Ce contrat n'est ni supprimé ni clôturé, impossible de le restaurer.";
        header('Location: contrats.php');
        exit;
    }

    // Log the restoration action
    $pdo->prepare("
        INSERT INTO logs (type_entite, entite_id, action, details, ip_address, created_at)
        VALUES ('contrat', ?, ?, ?, ?, NOW())
    ")->execute([$contrat_id, $action, $details, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);

    $pdo->commit();

    $_SESSION['success'] = "Contrat {$contrat['reference_unique']} restauré avec succès.";

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Erreur lors de la restauration du contrat: " . $e->getMessage());
    $_SESSION['error'] = "Erreur lors de la restauration du contrat: " . $e->getMessage();
}

header('Location: contrats.php');
exit;
