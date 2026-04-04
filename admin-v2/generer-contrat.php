<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/mail-templates.php';

// Get candidature ID if provided
$candidature_id = isset($_GET['candidature_id']) ? (int)$_GET['candidature_id'] : 0;
$candidature = null;
$logement_from_candidature = null;

if ($candidature_id) {
    // Fetch candidature with its associated logement
    $stmt = $pdo->prepare("
        SELECT c.*, l.id as logement_id, l.reference as logement_reference, 
               l.adresse as logement_adresse, l.type as logement_type,
               l.loyer, l.charges, l.depot_garantie, l.type_contrat as logement_type_contrat
        FROM candidatures c
        LEFT JOIN logements l ON c.logement_id = l.id
        WHERE c.id = ?
    ");
    $stmt->execute([$candidature_id]);
    $candidature = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If candidature has an associated logement, store it separately
    if ($candidature && $candidature['logement_id']) {
        $logement_from_candidature = [
            'id' => $candidature['logement_id'],
            'reference' => $candidature['logement_reference'],
            'adresse' => $candidature['logement_adresse'],
            'type' => $candidature['logement_type'],
            'loyer' => $candidature['loyer'],
            'charges' => $candidature['charges'],
            'depot_garantie' => $candidature['depot_garantie'],
            'type_contrat' => $candidature['logement_type_contrat'] ?? 'meuble',
        ];
    }
}

// Get all available properties
$stmt = $pdo->query("SELECT * FROM logements WHERE statut = 'disponible' ORDER BY reference");
$logements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get applications that can have contracts
$stmt = $pdo->query("
    SELECT id, reference_unique, nom, prenom, email, statut
    FROM candidatures 
    WHERE statut = 'accepte'
    ORDER BY created_at DESC
");
$candidatures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $logement_id = (int)$_POST['logement_id'];
    $candidature_id = (int)$_POST['candidature_id'];
    $nb_locataires = (int)$_POST['nb_locataires'];
    $date_prise_effet = $_POST['date_prise_effet'];
    $type_contrat = in_array($_POST['type_contrat'] ?? '', ['meuble', 'non_meuble', 'sur_mesure'])
        ? $_POST['type_contrat']
        : 'meuble';
    
    // Generate unique reference
    $reference_unique = 'BAIL-' . strtoupper(uniqid());
    
    // Get expiration delay from parameters table, fallback to 24 hours
    $expiryHours = getParameter('delai_expiration_lien_contrat', 24);
    $date_expiration = date('Y-m-d H:i:s', strtotime('+' . $expiryHours . ' hours'));
    
    // Create contract (include type_contrat if column exists)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO contrats (reference_unique, candidature_id, logement_id, statut, date_creation, date_expiration, date_prise_effet, nb_locataires, type_contrat)
            VALUES (?, ?, ?, 'en_attente', NOW(), ?, ?, ?, ?)
        ");
        $stmt->execute([$reference_unique, $candidature_id, $logement_id, $date_expiration, $date_prise_effet, $nb_locataires, $type_contrat]);
    } catch (PDOException $e) {
        // Fallback if type_contrat column doesn't exist yet (migration not run)
        error_log("generer-contrat: type_contrat column missing, falling back: " . $e->getMessage());
        $stmt = $pdo->prepare("
            INSERT INTO contrats (reference_unique, candidature_id, logement_id, statut, date_creation, date_expiration, date_prise_effet, nb_locataires)
            VALUES (?, ?, ?, 'en_attente', NOW(), ?, ?, ?)
        ");
        $stmt->execute([$reference_unique, $candidature_id, $logement_id, $date_expiration, $date_prise_effet, $nb_locataires]);
    }
    $contrat_id = $pdo->lastInsertId();
    
    // Snapshot logement data into contrat_logement (frozen at contract creation time)
    try {
        $logSnapshot = $pdo->prepare("
            SELECT l.reference, l.adresse, l.type, l.surface, l.loyer, l.charges,
                   l.depot_garantie, l.parking,
                   COALESCE(l.description, '') as description,
                   COALESCE(l.equipements, '') as equipements,
                   COALESCE(l.type_contrat, 'meuble') as type_contrat,
                   COALESCE(l.duree_garantie, 1) as duree_garantie,
                   l.lien_externe, l.dpe_file, l.dpe_classe, l.dpe_ges, l.dpe_numero,
                   l.dpe_valable_jusqu_a,
                   COALESCE(l.default_cles_appartement, 2) as default_cles_appartement,
                   COALESCE(l.default_cles_boite_lettres, 1) as default_cles_boite_lettres,
                   l.default_etat_logement
            FROM logements l WHERE l.id = ?
        ");
        $logSnapshot->execute([$logement_id]);
        $logData = $logSnapshot->fetch(PDO::FETCH_ASSOC);

        if ($logData) {
            // Snapshot equipment list from inventaire_equipements
            $equipJson = null;
            try {
                $eqStmt = $pdo->prepare("
                    SELECT ie.*, ic.nom as categorie_nom
                    FROM inventaire_equipements ie
                    LEFT JOIN inventaire_categories ic ON ie.categorie_id = ic.id
                    WHERE ie.logement_id = ? ORDER BY ie.ordre ASC
                ");
                $eqStmt->execute([$logement_id]);
                $equipItems = $eqStmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($equipItems)) {
                    $equipJson = json_encode($equipItems, JSON_UNESCAPED_UNICODE);
                }
            } catch (\Exception $eqEx) {
                error_log("generer-contrat: equipment snapshot failed: " . $eqEx->getMessage());
            }

            $insSnap = $pdo->prepare("
                INSERT IGNORE INTO contrat_logement (
                    contrat_id, logement_id, reference, adresse, type, surface,
                    loyer, charges, depot_garantie, parking, description,
                    type_contrat, duree_garantie, lien_externe,
                    dpe_file, dpe_classe, dpe_ges, dpe_numero, dpe_valable_jusqu_a,
                    default_cles_appartement, default_cles_boite_lettres, default_etat_logement,
                    equipements, equipements_json
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");
            $insSnap->execute([
                $contrat_id,
                $logement_id,
                $logData['reference'],
                $logData['adresse'],
                $logData['type'],
                $logData['surface'],
                $logData['loyer'],
                $logData['charges'],
                $logData['depot_garantie'],
                $logData['parking'],
                $logData['description'],
                $logData['type_contrat'],
                $logData['duree_garantie'],
                $logData['lien_externe'],
                $logData['dpe_file'],
                $logData['dpe_classe'],
                $logData['dpe_ges'],
                $logData['dpe_numero'],
                $logData['dpe_valable_jusqu_a'],
                $logData['default_cles_appartement'],
                $logData['default_cles_boite_lettres'],
                $logData['default_etat_logement'],
                $logData['equipements'],
                $equipJson,
            ]);
        }
    } catch (\Exception $snapEx) {
        // Non-blocking: log the error but continue (table may not exist yet)
        error_log("generer-contrat: contrat_logement snapshot failed: " . $snapEx->getMessage());
    }
    // For now, we keep it as is since the contract is still 'en_attente'
    
    // Update candidature status
    $stmt = $pdo->prepare("UPDATE candidatures SET statut = 'contrat_envoye', logement_id = ? WHERE id = ?");
    $stmt->execute([$logement_id, $candidature_id]);
    
    // Log action using polymorphic structure
    $stmt = $pdo->prepare("
        INSERT INTO logs (type_entite, entite_id, action, details, ip_address, created_at)
        VALUES ('contrat', ?, 'Contrat généré', ?, ?, NOW())
    ");
    $stmt->execute([
        $contrat_id,
        "Contrat $reference_unique créé pour candidature ID $candidature_id et logement ID $logement_id",
        $_SERVER['REMOTE_ADDR']
    ]);
    
    // Generate signature token
    $token_signature = bin2hex(random_bytes(32));
    
    // Store token in contract
    $stmt = $pdo->prepare("UPDATE contrats SET token_signature = ? WHERE id = ?");
    $stmt->execute([$token_signature, $contrat_id]);
    
    // Get candidature email for sending
    $stmt = $pdo->prepare("SELECT email, nom, prenom FROM candidatures WHERE id = ?");
    $stmt->execute([$candidature_id]);
    $candidature_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get logement info for email
    $stmt = $pdo->prepare("SELECT adresse, type_contrat, COALESCE(dpe_file, '') as dpe_file FROM logements WHERE id = ?");
    $stmt->execute([$logement_id]);
    $logement_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($candidature_info && $candidature_info['email'] && $logement_info) {
        // Create signature link
        $signature_link = $config['SITE_URL'] . '/signature/index.php?token=' . $token_signature;
        
        // Format expiration date for email (e.g., "02/02/2026 à 15:30")
        $date_expiration_formatted = date('d/m/Y à H:i', strtotime($date_expiration));

        // Compute duree_garantie dynamically from type_contrat
        $dureeGarantie = getDureeGarantie($logement_info['type_contrat'] ?? 'meuble') . ' mois';

        // Generate a direct link to the DPE file on the server
        $lienDpe = '';
        if (!empty($logement_info['dpe_file'])) {
            $dpeFile = $logement_info['dpe_file'];
            // Validate: no path traversal, must be a relative path
            if (strpos($dpeFile, '..') === false && strpos($dpeFile, '/') !== 0) {
                $lienDpe = rtrim($config['SITE_URL'], '/') . '/' . $dpeFile;
            }
        }
        
        // Préparer les variables pour le template
        $variables = [
            'nom' => $candidature_info['nom'],
            'prenom' => $candidature_info['prenom'],
            'email' => $candidature_info['email'],
            'adresse' => $logement_info['adresse'],
            'lien_signature' => $signature_link,
            'date_expiration_lien_contrat' => $date_expiration_formatted,
            'duree_garantie' => $dureeGarantie,
            'lien_telechargement_dpe' => $lienDpe,
        ];
        
        // Send invitation email (no attachment – DPE download link is in the template variable)
        $emailSent = sendTemplatedEmail('contrat_signature', $candidature_info['email'], $variables, null, true, false, ['contexte' => 'contrat_id=' . $contrat_id]);
        
        if ($emailSent) {
            // Log email sending success
            $stmt = $pdo->prepare("
                INSERT INTO logs (type_entite, entite_id, action, details, ip_address, created_at)
                VALUES ('contrat', ?, 'Email envoyé', ?, ?, NOW())
            ");
            $stmt->execute([
                $contrat_id,
                "Email de signature envoyé à " . $candidature_info['email'],
                $_SERVER['REMOTE_ADDR']
            ]);
            
            $_SESSION['success'] = "Contrat généré avec succès et email envoyé à {$candidature_info['email']}. Référence: $reference_unique";
        } else {
            error_log("Erreur lors de l'envoi de l'email de signature à {$candidature_info['email']}");
            $_SESSION['warning'] = "Contrat généré mais l'email n'a pas pu être envoyé. Référence: $reference_unique";
        }
    } else {
        $_SESSION['warning'] = "Contrat généré mais aucun email trouvé pour la candidature. Référence: $reference_unique";
    }
    
    header('Location: contrats.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Générer un Contrat - Admin MyInvest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .header {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .form-card {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <h4>Générer un Nouveau Contrat</h4>
            <p class="text-muted mb-0">Créer un contrat de bail et envoyer le lien de signature</p>
        </div>

        <div class="form-card">
            <form action="generer-contrat.php" method="POST">
                <div class="row g-3">
                    <?php if ($candidature): ?>
                        <!-- Candidature field - Read-only when candidature_id is provided -->
                        <div class="col-md-6">
                            <label class="form-label">Candidature</label>
                            <input type="text" class="form-control" readonly 
                                   value="<?php echo htmlspecialchars($candidature['reference_unique'] . ' - ' . $candidature['prenom'] . ' ' . $candidature['nom']); ?>">
                            <input type="hidden" name="candidature_id" value="<?php echo $candidature['id']; ?>">
                            <small class="form-text text-muted">Candidature sélectionnée</small>
                        </div>

                        <!-- Logement field - Read-only when candidature has associated logement -->
                        <div class="col-md-6">
                            <label class="form-label">Logement</label>
                            <?php if ($logement_from_candidature): ?>
                                <input type="text" class="form-control" readonly 
                                       value="<?php echo htmlspecialchars($logement_from_candidature['reference'] . ' (' . $logement_from_candidature['type'] . ' - ' . $logement_from_candidature['loyer'] . '€/mois)'); ?>">
                                <input type="hidden" name="logement_id" value="<?php echo $logement_from_candidature['id']; ?>">
                                <input type="hidden" id="hidden_loyer" value="<?php echo $logement_from_candidature['loyer']; ?>">
                                <input type="hidden" id="hidden_charges" value="<?php echo $logement_from_candidature['charges']; ?>">
                                <input type="hidden" id="hidden_depot" value="<?php echo $logement_from_candidature['depot_garantie']; ?>">
                                <input type="hidden" id="hidden_type_contrat" value="<?php echo htmlspecialchars($logement_from_candidature['type_contrat']); ?>">
                                <small class="form-text text-muted">Logement associé à la candidature</small>
                            <?php else: ?>
                                <select name="logement_id" class="form-select" required id="logement_select">
                                    <option value="">-- Sélectionner un logement --</option>
                                    <?php foreach ($logements as $logement): ?>
                                        <option value="<?php echo $logement['id']; ?>"
                                                data-loyer="<?php echo $logement['loyer']; ?>"
                                                data-charges="<?php echo $logement['charges']; ?>"
                                                data-depot="<?php echo $logement['depot_garantie']; ?>"
                                                data-type-contrat="<?php echo htmlspecialchars($logement['type_contrat'] ?? 'meuble'); ?>">
                                            <?php echo htmlspecialchars($logement['reference']); ?>
                                            (<?php echo htmlspecialchars($logement['type']); ?> - <?php echo $logement['loyer']; ?>€/mois)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">La candidature n'a pas de logement associé</small>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- Original dropdowns when no candidature_id is provided -->
                        <div class="col-md-6">
                            <label class="form-label">Candidature *</label>
                            <select name="candidature_id" class="form-select" required id="candidature_select">
                                <option value="">-- Sélectionner une candidature --</option>
                                <?php foreach ($candidatures as $cand): ?>
                                    <option value="<?php echo $cand['id']; ?>">
                                        <?php echo htmlspecialchars($cand['reference_unique'] . ' - ' . $cand['prenom'] . ' ' . $cand['nom']); ?>
                                        (<?php echo htmlspecialchars($cand['statut']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Seules les candidatures acceptées sont affichées</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Logement *</label>
                            <select name="logement_id" class="form-select" required id="logement_select">
                                <option value="">-- Sélectionner un logement --</option>
                                <?php foreach ($logements as $logement): ?>
                                    <option value="<?php echo $logement['id']; ?>"
                                            data-loyer="<?php echo $logement['loyer']; ?>"
                                            data-charges="<?php echo $logement['charges']; ?>"
                                            data-depot="<?php echo $logement['depot_garantie']; ?>"
                                            data-type-contrat="<?php echo htmlspecialchars($logement['type_contrat'] ?? 'meuble'); ?>">
                                        <?php echo htmlspecialchars($logement['reference']); ?>
                                        (<?php echo htmlspecialchars($logement['type']); ?> - <?php echo $logement['loyer']; ?>€/mois)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Logements disponibles</small>
                        </div>
                    <?php endif; ?>

                    <div class="col-md-6">
                        <label class="form-label">Nombre de locataires *</label>
                        <select name="nb_locataires" class="form-select" required>
                            <option value="">---</option>
                            <option value="1">1 locataire</option>
                            <option value="2">2 locataires</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Date de prise d'effet *</label>
                        <input type="date" name="date_prise_effet" class="form-control" required 
                               min="<?php echo date('Y-m-d'); ?>">
                        <small class="form-text text-muted">Date d'entrée dans le logement</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Type de contrat *</label>
                        <select name="type_contrat" class="form-select" required>
                            <option value="meuble">Meublé</option>
                            <option value="non_meuble">Non meublé</option>
                            <option value="sur_mesure">Sur mesure</option>
                        </select>
                        <small class="form-text text-muted">Détermine le modèle de contrat utilisé</small>
                    </div>

                    <div class="col-12">
                        <div class="alert alert-info">
                            <h6><i class="bi bi-info-circle"></i> Informations importantes</h6>
                            <ul class="mb-0">
                                <li>Le contrat sera créé avec un statut "En attente"</li>
                                <li>Un lien de signature valide 24h sera généré</li>
                                <li>Le locataire recevra un email avec les instructions</li>
                                <li>Le statut de la candidature passera à "Contrat envoyé"</li>
                                <li>Le logement sera marqué comme "En location" une fois le contrat signé</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Preview Card -->
                    <div class="col-12" id="preview_card" style="display: none;">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0">Aperçu du contrat</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <strong>Loyer mensuel:</strong> <span id="preview_loyer">-</span> €
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Provisions sur charges:</strong> <span id="preview_charges">-</span> €
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Total mensuel:</strong> <span id="preview_total">-</span> €
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Dépôt de garantie:</strong> <span id="preview_depot">-</span> €
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <hr>
                        <div class="d-flex justify-content-between">
                            <a href="contrats.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Annuler
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Générer le contrat et envoyer
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show preview when logement is selected
        document.getElementById('logement_select')?.addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            if (option.value) {
                const loyer = parseFloat(option.dataset.loyer);
                const charges = parseFloat(option.dataset.charges);
                const depot = parseFloat(option.dataset.depot);
                const total = loyer + charges;
                
                document.getElementById('preview_loyer').textContent = loyer.toFixed(2);
                document.getElementById('preview_charges').textContent = charges.toFixed(2);
                document.getElementById('preview_total').textContent = total.toFixed(2);
                document.getElementById('preview_depot').textContent = depot.toFixed(2);
                document.getElementById('preview_card').style.display = 'block';

                // Auto-select type_contrat based on logement
                const typeContrat = option.dataset.typeContrat || 'meuble';
                const typeSelect = document.querySelector('[name="type_contrat"]');
                if (typeSelect) { typeSelect.value = typeContrat; }
            } else {
                document.getElementById('preview_card').style.display = 'none';
            }
        });
        
        // Auto-show preview if logement is already selected (from candidature)
        window.addEventListener('DOMContentLoaded', function() {
            const hiddenLoyer = document.getElementById('hidden_loyer');
            const hiddenCharges = document.getElementById('hidden_charges');
            const hiddenDepot = document.getElementById('hidden_depot');
            
            if (hiddenLoyer && hiddenCharges && hiddenDepot) {
                const loyer = parseFloat(hiddenLoyer.value);
                const charges = parseFloat(hiddenCharges.value);
                const depot = parseFloat(hiddenDepot.value);
                const total = loyer + charges;
                
                document.getElementById('preview_loyer').textContent = loyer.toFixed(2);
                document.getElementById('preview_charges').textContent = charges.toFixed(2);
                document.getElementById('preview_total').textContent = total.toFixed(2);
                document.getElementById('preview_depot').textContent = depot.toFixed(2);
                document.getElementById('preview_card').style.display = 'block';

                // Auto-select type_contrat from candidature logement
                const hiddenTypeContrat = document.getElementById('hidden_type_contrat');
                if (hiddenTypeContrat && hiddenTypeContrat.value) {
                    const typeSelect = document.querySelector('[name="type_contrat"]');
                    if (typeSelect) { typeSelect.value = hiddenTypeContrat.value; }
                }
            }
        });
    </script>
</body>
</html>
