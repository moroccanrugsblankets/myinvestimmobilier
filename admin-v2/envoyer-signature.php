<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/mail-templates.php';

// Vérifier l'ID du contrat
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: contrats.php?error=invalid_id');
    exit;
}

$contrat_id = (int)$_GET['id'];

// Récupérer les informations du contrat
$stmt = $pdo->prepare("
    SELECT c.*, l.reference, l.adresse, l.loyer, l.charges, l.depot_garantie,
           l.type_contrat,
           COALESCE(l.dpe_file, '') as dpe_file,
           ca.nom, ca.prenom, ca.email
    FROM contrats c
    JOIN logements l ON c.logement_id = l.id
    LEFT JOIN candidatures ca ON c.candidature_id = ca.id
    WHERE c.id = ?
");
$stmt->execute([$contrat_id]);
$contrat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contrat) {
    header('Location: contrats.php?error=not_found');
    exit;
}

// Traiter l'envoi du lien de signature
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $nb_locataires = (int)$_POST['nb_locataires'];
        $email_principal = $_POST['email_principal'];
        
        // Générer un token unique
        $token = bin2hex(random_bytes(32));
        
        // Get expiration delay from parameters table, fallback to 24 hours
        $expiryHours = getParameter('delai_expiration_lien_contrat', 24);
        $date_expiration = date('Y-m-d H:i:s', strtotime('+' . $expiryHours . ' hours'));
        
        // Mettre à jour le contrat avec le token
        $stmt = $pdo->prepare("
            UPDATE contrats 
            SET token_signature = ?, 
                date_expiration = ?,
                nb_locataires = ?,
                statut = 'en_attente'
            WHERE id = ?
        ");
        $stmt->execute([$token, $date_expiration, $nb_locataires, $contrat_id]);
        
        // Créer le lien de signature
        $signature_link = $config['SITE_URL'] . '/signature/index.php?token=' . $token;
        
        // Format expiration date for email (e.g., "02/02/2026 à 15:30")
        $date_expiration_formatted = date('d/m/Y à H:i', strtotime($date_expiration));

        // Compute duree_garantie dynamically from type_contrat
        $dureeGarantie = getDureeGarantie($contrat['type_contrat'] ?? 'meuble') . ' mois';

        // Generate a secure download link for the DPE file (no direct attachment)
        $lienDpe = '';
        if (!empty($contrat['dpe_file'])) {
            $dpePath = dirname(__DIR__) . '/' . $contrat['dpe_file'];
            $tokenUrl = createDocumentToken($dpePath, 'dpe', 'DPE.pdf');
            if ($tokenUrl) {
                $lienDpe = $tokenUrl;
            }
        }
        
        // Préparer les variables pour le template
        $variables = [
            'nom' => $contrat['nom'],
            'prenom' => $contrat['prenom'],
            'email' => $email_principal,
            'adresse' => $contrat['adresse'],
            'lien_signature' => $signature_link,
            'date_expiration_lien_contrat' => $date_expiration_formatted,
            'duree_garantie' => $dureeGarantie,
            'lien_telechargement_dpe' => $lienDpe,
        ];

        // Send invitation email (no attachment – DPE download link is in the template variable)
        $emailSent = sendTemplatedEmail('contrat_signature', $email_principal, $variables, null, true, false, ['contexte' => 'contrat_id=' . $contrat_id]);
        
        if (!$emailSent) {
            error_log("Erreur lors de l'envoi de l'email de signature à $email_principal");
            throw new Exception("Erreur lors de l'envoi de l'email");
        }
        
        // Logger l'action
        $stmt = $pdo->prepare("
            INSERT INTO logs (type_entite, entite_id, action, details, ip_address)
            VALUES (?, ?, 'signature_link_sent', ?, ?)
        ");
        $stmt->execute([
            'contrat',
            $contrat_id,
            "Lien de signature envoyé à $email_principal pour $nb_locataires locataire(s)",
            $_SERVER['REMOTE_ADDR']
        ]);
        
        // Mettre à jour le statut de la candidature si elle existe
        if (!empty($contrat['candidature_id'])) {
            $stmt = $pdo->prepare("UPDATE candidatures SET statut = 'contrat_envoye' WHERE id = ?");
            $stmt->execute([$contrat['candidature_id']]);
        }
        
        // Mettre à jour le statut du logement
        $stmt = $pdo->prepare("UPDATE logements SET statut = 'reserve' WHERE id = ?");
        $stmt->execute([$contrat['logement_id']]);
        
        header('Location: contrats.php?success=signature_sent');
        exit;
        
    } catch (Exception $e) {
        $error = "Erreur lors de l'envoi : " . $e->getMessage();
    }
}

$page_title = "Envoyer Lien de Signature";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar -->
        <div class="bg-dark text-white p-3" style="width: 250px; min-height: 100vh;">
            <h4 class="mb-4">My Invest Immobilier</h4>
            <nav class="nav flex-column">
                <a class="nav-link text-white" href="index.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a class="nav-link text-white" href="candidatures.php"><i class="bi bi-file-earmark-text"></i> Candidatures</a>
                <a class="nav-link text-white" href="logements.php"><i class="bi bi-house"></i> Logements</a>
                <a class="nav-link text-white active" href="contrats.php"><i class="bi bi-file-earmark-check"></i> Contrats</a>
                <hr class="text-white">
                <a class="nav-link text-white" href="logout.php"><i class="bi bi-box-arrow-right"></i> Déconnexion</a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="flex-grow-1 p-4">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="bi bi-send"></i> Envoyer Lien de Signature</h2>
                    <a href="contrats.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Retour
                    </a>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-8">
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">Informations du Contrat</h5>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <strong>Référence:</strong> <?php echo htmlspecialchars($contrat['reference']); ?>
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Statut:</strong>
                                        <span class="badge bg-info"><?php echo ucfirst(str_replace('_', ' ', $contrat['statut'])); ?></span>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <strong>Logement:</strong> <?php echo htmlspecialchars($contrat['adresse']); ?>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <strong>Loyer:</strong> <?php echo number_format($contrat['loyer'], 2); ?> €
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Charges:</strong> <?php echo number_format($contrat['charges'], 2); ?> €
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Dépôt:</strong> <?php echo number_format($contrat['depot_garantie'], 2); ?> €
                                    </div>
                                </div>
                                <?php if ($contrat['nom']): ?>
                                <div class="row">
                                    <div class="col-12">
                                        <strong>Candidat:</strong> <?php echo htmlspecialchars($contrat['prenom'] . ' ' . $contrat['nom']); ?>
                                        (<?php echo htmlspecialchars($contrat['email']); ?>)
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0">Configuration de la Signature</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label for="nb_locataires" class="form-label">
                                            <strong>Nombre de locataires</strong>
                                        </label>
                                        <select class="form-select" id="nb_locataires" name="nb_locataires" required>
                                            <option value="1">1 locataire</option>
                                            <option value="2">2 locataires</option>
                                        </select>
                                        <small class="text-muted">
                                            Sélectionnez le nombre de personnes qui doivent signer le bail
                                        </small>
                                    </div>

                                    <div class="mb-3">
                                        <label for="email_principal" class="form-label">
                                            <strong>Email du locataire principal</strong>
                                        </label>
                                        <input type="email" class="form-control" id="email_principal" 
                                               name="email_principal" 
                                               value="<?php echo htmlspecialchars($contrat['email'] ?? ''); ?>" 
                                               required>
                                        <small class="text-muted">
                                            Le lien de signature sera envoyé à cette adresse
                                        </small>
                                    </div>

                                    <div class="alert alert-info">
                                        <h6><i class="bi bi-info-circle"></i> Information importante</h6>
                                        <ul class="mb-0">
                                            <li>Le lien de signature sera valide pendant <strong>24 heures</strong></li>
                                            <li>Le locataire devra compléter toutes les étapes :
                                                <ol>
                                                    <li>Accepter la procédure</li>
                                                    <li>Renseigner ses informations personnelles</li>
                                                    <li>Apposer sa signature électronique</li>
                                                    <li>Télécharger sa pièce d'identité (recto + verso)</li>
                                                </ol>
                                            </li>
                                            <li>Si 2 locataires, le second devra effectuer les mêmes démarches</li>
                                            <li>Le statut du contrat passera automatiquement à "Contrat envoyé"</li>
                                        </ul>
                                    </div>

                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="bi bi-send"></i> Envoyer le Lien de Signature
                                        </button>
                                        <a href="contrats.php" class="btn btn-secondary">Annuler</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-warning">
                                <h6 class="mb-0">Procédure de Signature</h6>
                            </div>
                            <div class="card-body">
                                <ol class="small">
                                    <li class="mb-2"><strong>Email automatique</strong><br>
                                        Le locataire reçoit un email avec le lien</li>
                                    <li class="mb-2"><strong>Acceptation</strong><br>
                                        Le locataire accepte ou refuse la procédure</li>
                                    <li class="mb-2"><strong>Informations</strong><br>
                                        Nom, prénom, date de naissance, email, date de prise d'effet</li>
                                    <li class="mb-2"><strong>Signature</strong><br>
                                        Signature électronique sur canvas + mention "Lu et approuvé"</li>
                                    <li class="mb-2"><strong>Documents</strong><br>
                                        Upload pièce d'identité recto + verso</li>
                                    <li class="mb-2"><strong>Second locataire</strong><br>
                                        Si oui, répéter les étapes 3 à 5</li>
                                    <li class="mb-2"><strong>Confirmation</strong><br>
                                        Page de confirmation avec instructions de paiement</li>
                                </ol>
                            </div>
                        </div>

                        <div class="card mt-3">
                            <div class="card-header bg-secondary text-white">
                                <h6 class="mb-0">Actions Automatiques</h6>
                            </div>
                            <div class="card-body small">
                                <p><i class="bi bi-check-circle text-success"></i> Changement statut contrat</p>
                                <p><i class="bi bi-check-circle text-success"></i> Changement statut logement</p>
                                <p><i class="bi bi-check-circle text-success"></i> Enregistrement dans les logs</p>
                                <p><i class="bi bi-check-circle text-success"></i> Email automatique au locataire</p>
                                <p class="mb-0"><i class="bi bi-check-circle text-success"></i> Tracking IP et horodatage</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.bundle.min.js"></script>
</body>
</html>
