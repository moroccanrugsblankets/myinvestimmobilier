<?php
/**
 * Formulaire de candidature locative - MyInvestImmobilier
 * Page accessible publiquement pour soumettre une candidature
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Générer un token CSRF si nécessaire
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

// Récupérer le paramètre ref (md5 de la référence du logement)
$ref_param = isset($_GET['ref']) ? $_GET['ref'] : null;
// Valider le format MD5 (32 caractères hexadécimaux)
if ($ref_param && !preg_match('/^[a-f0-9]{32}$/i', $ref_param)) {
    $ref_param = null;
}
$selected_logement_id = null;

// Récupérer la liste des logements disponibles
try {
    $stmt = $pdo->query("SELECT id, reference, adresse, type, loyer FROM logements WHERE statut = 'disponible' ORDER BY reference");
    $logements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Si un paramètre ref est fourni, chercher le logement correspondant
    if ($ref_param) {
        foreach ($logements as $logement) {
            if (md5($logement['reference']) === $ref_param) {
                $selected_logement_id = $logement['id'];
                $selected_logement    = $logement;
                break;
            }
        }
        // Also search logements not in "disponible" status (in case ref points to non-available)
        if (!$selected_logement_id) {
            $stmtAll = $pdo->prepare("SELECT id, reference, adresse, type, loyer FROM logements WHERE MD5(reference) = ? AND deleted_at IS NULL LIMIT 1");
            $stmtAll->execute([$ref_param]);
            $row = $stmtAll->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $selected_logement_id = $row['id'];
                $selected_logement    = $row;
            }
        }
    }
} catch (PDOException $e) {
    error_log("Erreur récupération logements: " . $e->getMessage());
    $logements = [];
}
// Pre-selected logement info (when ref is in URL)
$selected_logement = $selected_logement ?? null;
$ref_locks_logement = ($ref_param && $selected_logement_id);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidature Locative - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <?php if (!empty($config['RECAPTCHA_ENABLED']) && $config['RECAPTCHA_ENABLED']): ?>
    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo htmlspecialchars($config['RECAPTCHA_SITE_KEY']); ?>"></script>
    <?php endif; ?>
    <style>
        .form-section {
            display: none;
        }
        .form-section.active {
            display: block;
        }
        .required-field::after {
            content: " *";
            color: red;
        }
        .progress-bar-custom {
            height: 5px;
            background-color: #e9ecef;
            margin-bottom: 2rem;
        }
        .progress-bar-custom .progress {
            height: 100%;
            background-color: #0d6efd;
            transition: width 0.3s ease;
        }
        .visale-link {
            color: #0d6efd;
            cursor: pointer;
            text-decoration: underline;
        }
        .document-upload-zone {
            position: relative;
            border: 2px dashed #dee2e6;
            border-radius: 0.375rem;
            padding: 2rem;
            text-align: center;
            background-color: #f8f9fa;
            transition: all 0.3s;
        }
        .document-upload-zone:hover {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }
        .document-upload-zone.drag-over {
            border-color: #0d6efd;
            background-color: #cfe2ff;
        }
        .file-list {
            min-height: 0;
            transition: all 0.3s ease;
        }
        .file-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 0.375rem;
            animation: slideIn 0.3s ease-out;
            transition: all 0.2s ease;
        }
        .file-list-item:hover {
            background-color: #e9ecef;
            border-color: #dee2e6;
            transform: translateX(2px);
        }
        .file-list-item .file-info {
            display: flex;
            align-items: center;
            flex: 1;
        }
        .file-list-item .file-icon {
            font-size: 1.5rem;
            margin-right: 0.75rem;
        }
        .file-list-item .file-icon.pdf {
            color: #dc3545;
        }
        .file-list-item .file-icon.image {
            color: #0d6efd;
        }
        .file-list-item .file-details {
            display: flex;
            flex-direction: column;
        }
        .file-list-item .file-name {
            font-weight: 500;
            color: #212529;
            word-break: break-word;
        }
        .file-list-item .file-size {
            font-size: 0.875rem;
            color: #6c757d;
        }
        .btn-remove-file {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 1.25rem;
            padding: 0.25rem;
            transition: all 0.2s ease;
            border-radius: 0.25rem;
        }
        .btn-remove-file:hover {
            background-color: #dc3545;
            color: white;
            transform: scale(1.1);
        }
        .btn-remove-file:focus {
            outline: 2px solid #dc3545;
            outline-offset: 2px;
        }
        .file-count-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: #198754;
            color: white;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.875rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            animation: scaleIn 0.3s ease-out;
        }
        .file-upload-success {
            display: inline-block;
            color: #198754;
            font-size: 0.875rem;
            margin-left: 0.5rem;
            animation: fadeIn 0.3s ease-in;
        }
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <nav class="navbar navbar-light bg-light shadow-sm">
        <div class="container">
            <a class="navbar-brand" href="/">
                <i class="bi bi-house-door-fill me-2"></i>
                <strong>My Invest Immobilier</strong>
            </a>
        </div>
    </nav>

    <div class="container my-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <!-- En-tête -->
                <div class="text-center mb-4">
                    <h1 class="h2 mb-3">Candidature Locative</h1>
                    <p class="text-muted">Complétez votre dossier de candidature en quelques minutes</p>
                </div>

                <!-- Barre de progression -->
                <div class="progress-bar-custom">
                    <div class="progress" id="progressBar" style="width: 0%"></div>
                </div>

                <!-- Messages -->
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Formulaire multi-étapes -->
                <form id="candidatureForm" method="POST" action="submit.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <!-- Section 1: Informations personnelles -->
                    <div class="form-section active" data-section="1">
                        <div class="card shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-person-fill me-2"></i>Informations Personnelles</h5>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="nom" class="form-label required-field">Nom</label>
                                        <input type="text" class="form-control" id="nom" name="nom" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="prenom" class="form-label required-field">Prénom</label>
                                        <input type="text" class="form-control" id="prenom" name="prenom" required>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="email" class="form-label required-field">Email</label>
                                        <input type="email" class="form-control" id="email" name="email" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="telephone" class="form-label required-field">Téléphone</label>
                                        <input type="tel" class="form-control" id="telephone" name="telephone" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <?php if ($ref_locks_logement && $selected_logement): ?>
                                    <!-- When ref is in URL: show the pre-selected logement as read-only info -->
                                    <input type="hidden" name="logement_id" value="<?php echo (int)$selected_logement_id; ?>">
                                    <label class="form-label required-field">Logement</label>
                                    <div class="alert alert-info py-2 mb-0 d-flex align-items-center gap-2">
                                        <i class="bi bi-building fs-5"></i>
                                        <div>
                                            <strong><?php echo htmlspecialchars($selected_logement['reference']); ?></strong>
                                            — <?php echo htmlspecialchars($selected_logement['adresse']); ?>
                                            <?php if ($selected_logement['type']): ?>
                                            <span class="text-muted">(<?php echo htmlspecialchars($selected_logement['type']); ?>)</span>
                                            <?php endif; ?>
                                            — <strong><?php echo number_format($selected_logement['loyer'], 0, ',', ' '); ?> €/mois</strong>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <label for="logement_id" class="form-label required-field">Logement souhaité</label>
                                    <select class="form-select" id="logement_id" name="logement_id" required>
                                        <option value="">-- Sélectionnez un logement --</option>
                                        <?php foreach ($logements as $logement): ?>
                                            <option value="<?php echo $logement['id']; ?>"<?php echo ($selected_logement_id && $selected_logement_id == $logement['id']) ? ' selected' : ''; ?>>
                                                <?php echo htmlspecialchars($logement['reference']); ?> - 
                                                <?php echo htmlspecialchars($logement['type']); ?> - 
                                                <?php echo htmlspecialchars($logement['adresse']); ?> - 
                                                <?php echo number_format($logement['loyer'], 0, ',', ' '); ?>€/mois
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-3">
                            <button type="button" class="btn btn-primary" onclick="nextSection(2)">
                                Suivant <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Section 2: Situation professionnelle -->
                    <div class="form-section" data-section="2">
                        <div class="card shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-briefcase-fill me-2"></i>Situation Professionnelle</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="statut_professionnel" class="form-label required-field">Statut professionnel</label>
                                    <select class="form-select" id="statut_professionnel" name="statut_professionnel" required>
                                        <option value="">-- Sélectionnez --</option>
                                        <option value="CDI">CDI</option>
                                        <option value="CDD">CDD</option>
                                        <option value="Indépendant">Indépendant</option>
                                        <option value="Autre">Autre</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="periode_essai" class="form-label required-field">Période d'essai</label>
                                    <select class="form-select" id="periode_essai" name="periode_essai" required>
                                        <option value="">-- Sélectionnez --</option>
                                        <option value="En cours">En cours</option>
                                        <option value="Dépassée">Dépassée</option>
                                        <option value="Non applicable">Non applicable</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between mt-3">
                            <button type="button" class="btn btn-secondary" onclick="prevSection(1)">
                                <i class="bi bi-arrow-left"></i> Précédent
                            </button>
                            <button type="button" class="btn btn-primary" onclick="nextSection(3)">
                                Suivant <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Section 3: Revenus & solvabilité -->
                    <div class="form-section" data-section="3">
                        <div class="card shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Revenus</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="revenus_mensuels" class="form-label required-field">Revenus nets mensuels</label>
                                    <select class="form-select" id="revenus_mensuels" name="revenus_mensuels" required>
                                        <option value="">-- Sélectionnez --</option>
                                        <option value="< 2300">< 2300 €</option>
                                        <option value="2300-3000">2300-3000 €</option>
                                        <option value="3000+">3000 € et +</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="type_revenus" class="form-label required-field">Type de revenus</label>
                                    <select class="form-select" id="type_revenus" name="type_revenus" required>
                                        <option value="">-- Sélectionnez --</option>
                                        <option value="Salaires">Salaires</option>
                                        <option value="Indépendant">Indépendant</option>
                                        <option value="Retraite/rente">Retraite/rente</option>
                                        <option value="Autres">Autres</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between mt-3">
                            <button type="button" class="btn btn-secondary" onclick="prevSection(2)">
                                <i class="bi bi-arrow-left"></i> Précédent
                            </button>
                            <button type="button" class="btn btn-primary" onclick="nextSection(4)">
                                Suivant <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Section 4: Situation de logement -->
                    <div class="form-section" data-section="4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-house-fill me-2"></i>Logement Actuel</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="situation_logement" class="form-label required-field">Situation actuelle</label>
                                    <select class="form-select" id="situation_logement" name="situation_logement" required>
                                        <option value="">-- Sélectionnez --</option>
                                        <option value="Locataire">Locataire</option>
                                        <option value="Hébergé">Hébergé</option>
                                        <option value="Propriétaire">Propriétaire</option>
                                        <option value="Autre">Autre</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="preavis_donne" class="form-label required-field">Préavis déjà donné ?</label>
                                    <select class="form-select" id="preavis_donne" name="preavis_donne" required>
                                        <option value="">-- Sélectionnez --</option>
                                        <option value="Oui">Oui</option>
                                        <option value="Non">Non</option>
                                        <option value="Non concerné">Non concerné</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between mt-3">
                            <button type="button" class="btn btn-secondary" onclick="prevSection(3)">
                                <i class="bi bi-arrow-left"></i> Précédent
                            </button>
                            <button type="button" class="btn btn-primary" onclick="nextSection(5)">
                                Suivant <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Section 5: Occupation & Garanties -->
                    <div class="form-section" data-section="5">
                        <div class="card shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-people-fill me-2"></i>Occupation</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="nb_occupants" class="form-label required-field">Nombre total d'occupants prévus</label>
                                    <select class="form-select" id="nb_occupants" name="nb_occupants" required>
                                        <option value="">-- Sélectionnez --</option>
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="Autre">Autre</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="garantie_visale" class="form-label required-field">
                                        Pouvez-vous bénéficier de la garantie Visale ?
                                        <span class="visale-link" data-bs-toggle="modal" data-bs-target="#visaleModal">
                                            <i class="bi bi-info-circle"></i> En savoir plus
                                        </span>
                                    </label>
                                    <select class="form-select" id="garantie_visale" name="garantie_visale" required>
                                        <option value="">-- Sélectionnez --</option>
                                        <option value="Oui">Oui</option>
                                        <option value="Non">Non</option>
                                        <option value="Je ne sais pas">Je ne sais pas</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between mt-3">
                            <button type="button" class="btn btn-secondary" onclick="prevSection(4)">
                                <i class="bi bi-arrow-left"></i> Précédent
                            </button>
                            <button type="button" class="btn btn-primary" onclick="nextSection(6)">
                                Suivant <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Section 6: Documents -->
                    <div class="form-section" data-section="6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-file-earmark-text-fill me-2"></i>Documents</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted mb-4">
                                    <i class="bi bi-info-circle"></i> 
                                    Tous les documents ci-dessous sont obligatoires. Formats acceptés : PDF, JPG, PNG (max 5 Mo par fichier)
                                </p>

                                <!-- Pièce d'identité -->
                                <div class="mb-4">
                                    <label class="form-label required-field">
                                        <i class="bi bi-person-vcard me-2"></i>
                                        Pièce d'identité ou passeport en cours de validité
                                    </label>
                                    <div class="document-upload-zone" data-doc-type="piece_identite">
                                        <i class="bi bi-cloud-upload fs-3 text-muted"></i>
                                        <p class="mb-2">Glissez-déposez vos fichiers ici ou</p>
                                        <label class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-folder2-open"></i> Parcourir
                                            <input type="file" class="d-none document-input" name="piece_identite[]" 
                                                   accept=".pdf,.jpg,.jpeg,.png" multiple required 
                                                   data-doc-type="piece_identite">
                                        </label>
                                    </div>
                                    <div class="file-list mt-2" data-doc-type="piece_identite"></div>
                                </div>

                                <!-- Bulletins de salaire -->
                                <div class="mb-4">
                                    <label class="form-label required-field">
                                        <i class="bi bi-file-earmark-text me-2"></i>
                                        3 derniers bulletins de salaire
                                    </label>
                                    <div class="document-upload-zone" data-doc-type="bulletins_salaire">
                                        <i class="bi bi-cloud-upload fs-3 text-muted"></i>
                                        <p class="mb-2">Glissez-déposez vos fichiers ici ou</p>
                                        <label class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-folder2-open"></i> Parcourir
                                            <input type="file" class="d-none document-input" name="bulletins_salaire[]" 
                                                   accept=".pdf,.jpg,.jpeg,.png" multiple required 
                                                   data-doc-type="bulletins_salaire">
                                        </label>
                                    </div>
                                    <div class="file-list mt-2" data-doc-type="bulletins_salaire"></div>
                                </div>

                                <!-- Contrat de travail -->
                                <div class="mb-4">
                                    <label class="form-label required-field">
                                        <i class="bi bi-file-earmark-check me-2"></i>
                                        Contrat de travail
                                    </label>
                                    <div class="document-upload-zone" data-doc-type="contrat_travail">
                                        <i class="bi bi-cloud-upload fs-3 text-muted"></i>
                                        <p class="mb-2">Glissez-déposez vos fichiers ici ou</p>
                                        <label class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-folder2-open"></i> Parcourir
                                            <input type="file" class="d-none document-input" name="contrat_travail[]" 
                                                   accept=".pdf,.jpg,.jpeg,.png" multiple required 
                                                   data-doc-type="contrat_travail">
                                        </label>
                                    </div>
                                    <div class="file-list mt-2" data-doc-type="contrat_travail"></div>
                                </div>

                                <!-- Avis d'imposition -->
                                <div class="mb-4">
                                    <label class="form-label required-field">
                                        <i class="bi bi-file-earmark-ruled me-2"></i>
                                        Dernier avis d'imposition
                                    </label>
                                    <div class="document-upload-zone" data-doc-type="avis_imposition">
                                        <i class="bi bi-cloud-upload fs-3 text-muted"></i>
                                        <p class="mb-2">Glissez-déposez vos fichiers ici ou</p>
                                        <label class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-folder2-open"></i> Parcourir
                                            <input type="file" class="d-none document-input" name="avis_imposition[]" 
                                                   accept=".pdf,.jpg,.jpeg,.png" multiple required 
                                                   data-doc-type="avis_imposition">
                                        </label>
                                    </div>
                                    <div class="file-list mt-2" data-doc-type="avis_imposition"></div>
                                </div>

                                <!-- Quittances de loyer -->
                                <div class="mb-4">
                                    <label class="form-label required-field">
                                        <i class="bi bi-receipt me-2"></i>
                                        3 dernières quittances de loyer
                                    </label>
                                    <div class="document-upload-zone" data-doc-type="quittances_loyer">
                                        <i class="bi bi-cloud-upload fs-3 text-muted"></i>
                                        <p class="mb-2">Glissez-déposez vos fichiers ici ou</p>
                                        <label class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-folder2-open"></i> Parcourir
                                            <input type="file" class="d-none document-input" name="quittances_loyer[]" 
                                                   accept=".pdf,.jpg,.jpeg,.png" multiple required 
                                                   data-doc-type="quittances_loyer">
                                        </label>
                                    </div>
                                    <div class="file-list mt-2" data-doc-type="quittances_loyer"></div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between mt-3">
                            <button type="button" class="btn btn-secondary" onclick="prevSection(5)">
                                <i class="bi bi-arrow-left"></i> Précédent
                            </button>
                            <button type="button" class="btn btn-primary" onclick="nextSection(7)">
                                Suivant <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Section 7: Récapitulatif -->
                    <div class="form-section" data-section="7">
                        <div class="card shadow-sm">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="bi bi-check-circle-fill me-2"></i>Récapitulatif</h5>
                            </div>
                            <div class="card-body">
                                <div id="recapitulatif"></div>

                                <div class="alert alert-info mt-4">
                                    <h6><i class="bi bi-clock-history me-2"></i>Délai de traitement</h6>
                                    <p class="mb-0">Votre candidature sera étudiée et vous recevrez une réponse par email dans un délai <strong>entre 1 et 4 jours ouvrés</strong>.</p>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="accepte_conditions" name="accepte_conditions" required>
                                    <label class="form-check-label" for="accepte_conditions">
                                        J'accepte que mes données soient traitées dans le cadre de cette candidature locative conformément à la réglementation RGPD <span class="text-danger">*</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between mt-3">
                            <button type="button" class="btn btn-secondary" onclick="prevSection(6)">
                                <i class="bi bi-arrow-left"></i> Précédent
                            </button>
                            <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                                <i class="bi bi-send-fill"></i> Envoyer ma candidature
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Garantie Visale -->
    <div class="modal fade" id="visaleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-shield-check me-2"></i>Qu'est-ce que la Garantie Visale ?
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>Définition</h6>
                    <p>La garantie Visale est une caution locative gratuite proposée par Action Logement. Elle se porte garant pour vous auprès du propriétaire en cas d'impayés de loyer.</p>

                    <h6 class="mt-3">Qui peut en bénéficier ?</h6>
                    <ul>
                        <li>Les jeunes de moins de 30 ans (salariés ou en recherche d'emploi)</li>
                        <li>Les salariés de plus de 30 ans en situation de mobilité professionnelle</li>
                        <li>Les salariés du secteur privé en CDD ou en intérim</li>
                    </ul>

                    <h6 class="mt-3">Avantages</h6>
                    <ul>
                        <li>Gratuit pour le locataire</li>
                        <li>Remplace la caution solidaire (garant personne physique)</li>
                        <li>Facilite l'accès au logement</li>
                        <li>Rassure le propriétaire</li>
                    </ul>

                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Pour en savoir plus :</strong> Rendez-vous sur 
                        <a href="https://www.visale.fr" target="_blank" rel="noopener">www.visale.fr</a>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (!empty($config['RECAPTCHA_ENABLED']) && $config['RECAPTCHA_ENABLED']): ?>
    <script>
        // Exposer la clé site reCAPTCHA pour le JavaScript
        window.RECAPTCHA_SITE_KEY = '<?php echo htmlspecialchars($config['RECAPTCHA_SITE_KEY']); ?>';
    </script>
    <?php endif; ?>
    <script src="candidature.js"></script>
</body>
</html>
