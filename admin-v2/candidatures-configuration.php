<?php
/**
 * Configuration des Candidatures — Workflow et Délais
 * My Invest Immobilier
 *
 * Interface pour configurer les règles de traitement automatique des candidatures :
 * - Délai de réponse automatique
 * - Jours ouvrés pris en compte
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_workflow') {
    $hasError = false;

    $fieldsToUpdate = [
        'delai_reponse_valeur' => 'integer',
        'delai_reponse_unite'  => 'string',
        'jours_ouvres_debut'   => 'integer',
        'jours_ouvres_fin'     => 'integer',
    ];

    foreach ($fieldsToUpdate as $cle => $type) {
        if (!isset($_POST[$cle])) {
            continue;
        }
        $valeur = trim($_POST[$cle]);
        if ($type === 'integer') {
            $valeur = (string)(int)$valeur;
        }
        $stmt = $pdo->prepare("
            INSERT INTO parametres (cle, valeur, type, groupe, description)
            VALUES (?, ?, ?, 'workflow', '')
            ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), groupe = 'workflow', updated_at = NOW()
        ");
        $stmt->execute([$cle, $valeur, $type]);
    }

    if (!$hasError) {
        $_SESSION['success'] = "Configuration du workflow mise à jour avec succès";
    }
    header('Location: candidatures-configuration.php');
    exit;
}

// Load current values
$delaiValeur = (int)getParameter('delai_reponse_valeur', 4);
$delaiUnite  = getParameter('delai_reponse_unite', 'jours');
$joursDebut  = (int)getParameter('jours_ouvres_debut', 1);
$joursFin    = (int)getParameter('jours_ouvres_fin', 5);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration Candidatures - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .config-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .config-card h5 {
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3498db;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="header" style="background: white; padding: 20px 30px; border-radius: 10px; margin-bottom: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4><i class="bi bi-gear me-2"></i>Configuration des Candidatures</h4>
                    <p class="text-muted mb-0">Workflow et délais de traitement automatique</p>
                </div>
                <a href="candidatures.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Retour aux candidatures
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="action" value="update_workflow">

            <!-- Workflow et Délais -->
            <div class="config-card">
                <h5><i class="bi bi-arrow-repeat"></i> Workflow et Délais</h5>
                <p class="text-muted mb-4">
                    Configurez les délais d'envoi automatique des réponses aux candidatures et les jours ouvrés pris en compte.
                </p>

                <!-- Délai de réponse automatique -->
                <div class="mb-4">
                    <label class="form-label fw-semibold">Délai de réponse automatique</label>
                    <div class="form-text text-muted mb-2">
                        Délai avant l'envoi automatique de la réponse aux candidatures
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Valeur</label>
                            <input type="number"
                                   name="delai_reponse_valeur"
                                   class="form-control"
                                   value="<?php echo htmlspecialchars($delaiValeur); ?>"
                                   min="1"
                                   required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unité</label>
                            <select name="delai_reponse_unite" class="form-select">
                                <option value="minutes" <?php echo $delaiUnite === 'minutes' ? 'selected' : ''; ?>>Minutes</option>
                                <option value="heures"  <?php echo $delaiUnite === 'heures'  ? 'selected' : ''; ?>>Heures</option>
                                <option value="jours"   <?php echo $delaiUnite === 'jours'   ? 'selected' : ''; ?>>Jours (ouvrés)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Jours ouvrés -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Jours ouvrés</label>
                    <div class="form-text text-muted mb-2">
                        Définissez la plage des jours ouvrés (1 = Lundi, 7 = Dimanche)
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Premier jour ouvré</label>
                            <select name="jours_ouvres_debut" class="form-select">
                                <?php
                                $jours = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
                                foreach ($jours as $num => $label): ?>
                                <option value="<?php echo $num; ?>" <?php echo $joursDebut === $num ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Dernier jour ouvré</label>
                            <select name="jours_ouvres_fin" class="form-select">
                                <?php foreach ($jours as $num => $label): ?>
                                <option value="<?php echo $num; ?>" <?php echo $joursFin === $num ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Enregistrer la configuration
                    </button>
                </div>
            </div>

        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
