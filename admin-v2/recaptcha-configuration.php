<?php
/**
 * CONFIGURATION RECAPTCHA
 *
 * Interface pour configurer :
 * - Activation/Désactivation du reCAPTCHA
 * - Type de reCAPTCHA : V2 (case à cocher) ou V3 (score invisible)
 * - Clé site et Clé secrète (fournies par Google)
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Récupérer la valeur d'un paramètre reCAPTCHA
function getRcParam(PDO $pdo, string $cle, $default = null) {
    try {
        $stmt = $pdo->prepare("SELECT valeur, type FROM parametres WHERE cle = ? LIMIT 1");
        $stmt->execute([$cle]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $default;
        switch ($row['type']) {
            case 'boolean': return filter_var($row['valeur'], FILTER_VALIDATE_BOOLEAN);
            case 'integer': return (int)$row['valeur'];
            default:        return $row['valeur'];
        }
    } catch (Exception $e) {
        return $default;
    }
}

// Enregistrer un paramètre
function setRcParam(PDO $pdo, string $cle, string $valeur): void {
    $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = ?")
        ->execute([$valeur, $cle]);
}

// Ensure reCAPTCHA parameters exist in the database
function ensureRcParams(PDO $pdo): void {
    $defaults = [
        ['recaptcha_enabled',    '0',   'recaptcha', 'Activer le reCAPTCHA sur les formulaires publics', 'boolean'],
        ['recaptcha_type',       'v2',  'recaptcha', 'Type de reCAPTCHA : v2 (case à cocher) ou v3 (score invisible)', 'text'],
        ['recaptcha_site_key',   '',    'recaptcha', 'Clé site reCAPTCHA fournie par Google (publique, affichée côté client)', 'text'],
        ['recaptcha_secret_key', '',    'recaptcha', 'Clé secrète reCAPTCHA fournie par Google (confidentielle, utilisée côté serveur uniquement)', 'password'],
        ['recaptcha_min_score',  '0.5', 'recaptcha', 'Score minimal reCAPTCHA V3 (entre 0.0 et 1.0 — plus le score est élevé, plus la sécurité est stricte)', 'text'],
    ];
    foreach ($defaults as [$cle, $valeur, $groupe, $description, $type]) {
        $stmt = $pdo->prepare("SELECT id FROM parametres WHERE cle = ? LIMIT 1");
        $stmt->execute([$cle]);
        if (!$stmt->fetch()) {
            $pdo->prepare("INSERT INTO parametres (cle, valeur, groupe, description, type) VALUES (?, ?, ?, ?, ?)")
                ->execute([$cle, $valeur, $groupe, $description, $type]);
        }
    }
}

ensureRcParams($pdo);

// ─── Traitement du formulaire ─────────────────────────────────────────────────
$errors     = [];
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token CSRF invalide. Veuillez recharger la page.';
    } else {
        $action = $_POST['action'];

        if ($action === 'save_config') {
            $enabled   = isset($_POST['recaptcha_enabled']) ? '1' : '0';
            $type      = in_array($_POST['recaptcha_type'] ?? 'v2', ['v2', 'v3'])
                            ? $_POST['recaptcha_type']
                            : 'v2';
            $siteKey   = trim($_POST['recaptcha_site_key']   ?? '');
            $secretKey = trim($_POST['recaptcha_secret_key'] ?? '');
            $minScore  = (float)($_POST['recaptcha_min_score'] ?? 0.5);
            if ($minScore < 0.0) { $minScore = 0.0; }
            if ($minScore > 1.0) { $minScore = 1.0; }

            // Validate: if enabled, keys must be set (or already present in DB)
            if ($enabled === '1') {
                $existingSiteKey   = getRcParam($pdo, 'recaptcha_site_key',   '');
                $existingSecretKey = getRcParam($pdo, 'recaptcha_secret_key', '');
                if (empty($siteKey) && empty($existingSiteKey)) {
                    $errors[] = 'La clé site est obligatoire pour activer le reCAPTCHA.';
                }
                if (empty($secretKey) && empty($existingSecretKey)) {
                    $errors[] = 'La clé secrète est obligatoire pour activer le reCAPTCHA.';
                }
            }

            if (empty($errors)) {
                setRcParam($pdo, 'recaptcha_enabled', $enabled);
                setRcParam($pdo, 'recaptcha_type',    $type);
                setRcParam($pdo, 'recaptcha_min_score', number_format($minScore, 1, '.', ''));
                if ($siteKey !== '') {
                    setRcParam($pdo, 'recaptcha_site_key', $siteKey);
                }
                if ($secretKey !== '') {
                    setRcParam($pdo, 'recaptcha_secret_key', $secretKey);
                }
                $_SESSION['success'] = 'Configuration reCAPTCHA enregistrée avec succès.';
                header('Location: recaptcha-configuration.php');
                exit;
            }
        }
    }
}

// ─── Chargement des valeurs actuelles ─────────────────────────────────────────
$rcEnabled   = getRcParam($pdo, 'recaptcha_enabled',    false);
$rcType      = getRcParam($pdo, 'recaptcha_type',       'v2');
$rcSiteKey   = getRcParam($pdo, 'recaptcha_site_key',   '');
$rcSecretKey = getRcParam($pdo, 'recaptcha_secret_key', '');
$rcMinScore  = getRcParam($pdo, 'recaptcha_min_score',  '0.5');

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration reCAPTCHA — My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .config-card {
            background: #fff;
            border-radius: 10px;
            padding: 28px 32px;
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }
        .config-card h5 {
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3498db;
        }
        .key-display {
            font-family: monospace;
            background: #f8f9fa;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 0.85rem;
            word-break: break-all;
        }
        .status-badge-on  { background: #198754; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; }
        .status-badge-off { background: #6c757d; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/menu.php'; ?>
<div class="main-content">
    <div class="header" style="background:#fff;padding:20px 30px;border-radius:10px;margin-bottom:24px;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <a href="parametres.php" class="text-muted small d-block mb-1">
                    <i class="bi bi-arrow-left me-1"></i>Retour aux Paramètres
                </a>
                <h4 class="mb-0"><i class="bi bi-shield-lock me-2 text-primary"></i>Sécurité &amp; CAPTCHA</h4>
            </div>
            <span class="<?php echo $rcEnabled ? 'status-badge-on' : 'status-badge-off'; ?>">
                <i class="bi bi-<?php echo $rcEnabled ? 'check-circle' : 'x-circle'; ?> me-1"></i>
                reCAPTCHA <?php echo $rcEnabled ? 'Activé' : 'Désactivé'; ?>
            </span>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show mx-3">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mx-3">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $err): ?>
                    <li><?php echo htmlspecialchars($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="container-fluid px-3">
        <form method="POST" action="recaptcha-configuration.php">
            <input type="hidden" name="action"     value="save_config">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

            <!-- Activation & Type -->
            <div class="config-card">
                <h5><i class="bi bi-toggles me-2"></i>Activation</h5>

                <div class="mb-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch"
                               name="recaptcha_enabled" id="rcEnabled"
                               <?php echo $rcEnabled ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-semibold" for="rcEnabled">
                            Activer le reCAPTCHA sur les formulaires publics
                        </label>
                    </div>
                    <div class="form-text mt-1">
                        Lorsqu'il est activé, tous les formulaires publics contenant un champ reCAPTCHA seront protégés.
                        Le formulaire de candidature est automatiquement protégé.
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label fw-semibold">Type de reCAPTCHA</label>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <div class="card h-100 border <?php echo ($rcType === 'v2') ? 'border-primary border-2' : ''; ?>" style="cursor:pointer;" onclick="document.getElementById('rcV2').click()">
                                <div class="card-body">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="recaptcha_type"
                                               id="rcV2" value="v2" <?php echo ($rcType === 'v2') ? 'checked' : ''; ?>
                                               onchange="highlightType()">
                                        <label class="form-check-label fw-semibold" for="rcV2">
                                            <i class="bi bi-check2-square me-1 text-primary"></i>
                                            reCAPTCHA V2 — Case à cocher
                                        </label>
                                    </div>
                                    <p class="text-muted small mt-2 mb-0">
                                        Affiche le widget "Je ne suis pas un robot" visible par l'utilisateur.
                                        Idéal pour les formulaires de contact.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="card h-100 border <?php echo ($rcType === 'v3') ? 'border-primary border-2' : ''; ?>" style="cursor:pointer;" onclick="document.getElementById('rcV3').click()">
                                <div class="card-body">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="recaptcha_type"
                                               id="rcV3" value="v3" <?php echo ($rcType === 'v3') ? 'checked' : ''; ?>
                                               onchange="highlightType()">
                                        <label class="form-check-label fw-semibold" for="rcV3">
                                            <i class="bi bi-eye-slash me-1 text-success"></i>
                                            reCAPTCHA V3 — Score invisible
                                        </label>
                                    </div>
                                    <p class="text-muted small mt-2 mb-0">
                                        Invisible pour l'utilisateur. Attribue un score de 0 à 1 pour détecter les bots
                                        sans interruption de la navigation.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-text mt-2">
                        Le type choisi s'applique à <strong>tous</strong> les formulaires.
                        Vous devez obtenir des clés correspondant au type sélectionné sur
                        <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">
                            google.com/recaptcha/admin <i class="bi bi-box-arrow-up-right"></i></a>.
                    </div>
                </div>

                <!-- Score minimal V3 -->
                <div class="mb-2" id="v3ScoreBlock" style="<?php echo ($rcType !== 'v3') ? 'display:none' : ''; ?>">
                    <label for="rcMinScore" class="form-label fw-semibold">
                        <i class="bi bi-speedometer2 me-1"></i>Score de sécurité minimal <span class="text-muted fw-normal">(reCAPTCHA V3 uniquement)</span>
                    </label>
                    <div class="d-flex align-items-center gap-3">
                        <input type="range" class="form-range flex-grow-1" id="rcMinScoreRange"
                               min="0" max="1" step="0.1"
                               value="<?php echo htmlspecialchars($rcMinScore); ?>"
                               oninput="document.getElementById('rcMinScore').value=parseFloat(this.value).toFixed(1);document.getElementById('rcMinScoreDisplay').textContent=parseFloat(this.value).toFixed(1);">
                        <input type="number" name="recaptcha_min_score" id="rcMinScore"
                               class="form-control" style="width:90px"
                               min="0" max="1" step="0.1"
                               value="<?php echo htmlspecialchars($rcMinScore); ?>"
                               oninput="document.getElementById('rcMinScoreRange').value=this.value;document.getElementById('rcMinScoreDisplay').textContent=parseFloat(this.value).toFixed(1);">
                        <span class="badge bg-primary fs-6" id="rcMinScoreDisplay"><?php echo htmlspecialchars($rcMinScore); ?></span>
                    </div>
                    <div class="form-text mt-1">
                        Score entre <strong>0.0</strong> (très probablement un bot) et <strong>1.0</strong> (très probablement humain).
                        Une valeur de <strong>0.5</strong> est recommandée. Augmentez à <strong>0.7</strong> pour plus de sécurité
                        (au risque de bloquer certains utilisateurs légitimes).
                    </div>
                </div>
            </div>

            <!-- Clés API -->
            <div class="config-card">
                <h5><i class="bi bi-key me-2"></i>Clés API Google reCAPTCHA</h5>

                <div class="alert alert-info d-flex gap-2 mb-4">
                    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
                    <div>
                        <strong>Comment obtenir vos clés :</strong>
                        Connectez-vous sur <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">
                        google.com/recaptcha/admin</a>, créez un nouveau site, sélectionnez le type correspondant
                        et renseignez votre domaine. Vous obtiendrez une <em>clé site</em> (publique) et une
                        <em>clé secrète</em> (à ne jamais divulguer).
                    </div>
                </div>

                <div class="mb-4">
                    <label for="rcSiteKey" class="form-label fw-semibold">
                        <i class="bi bi-globe me-1"></i>Clé site <span class="text-muted fw-normal">(publique)</span>
                    </label>
                    <input type="text" name="recaptcha_site_key" id="rcSiteKey"
                           class="form-control font-monospace"
                           value="<?php echo htmlspecialchars($rcSiteKey); ?>"
                           placeholder="6Lc…">
                    <div class="form-text">Affichée dans le code HTML de la page (visible côté client).</div>
                    <?php if ($rcSiteKey): ?>
                    <div class="mt-1"><span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Clé site configurée</span></div>
                    <?php else: ?>
                    <div class="mt-1"><span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Aucune clé site configurée</span></div>
                    <?php endif; ?>
                </div>

                <div class="mb-2">
                    <label for="rcSecretKey" class="form-label fw-semibold">
                        <i class="bi bi-lock me-1"></i>Clé secrète <span class="text-muted fw-normal">(confidentielle)</span>
                    </label>
                    <input type="password" name="recaptcha_secret_key" id="rcSecretKey"
                           class="form-control font-monospace"
                           value=""
                           autocomplete="new-password"
                           placeholder="<?php echo $rcSecretKey ? '••••••••  (définie — laissez vide pour conserver)' : 'Entrez votre clé secrète'; ?>">
                    <div class="form-text">
                        Utilisée uniquement côté serveur pour vérifier les réponses reCAPTCHA.
                        Ne la partagez jamais publiquement.
                        Laissez ce champ vide pour conserver la valeur actuelle.
                    </div>
                    <?php if ($rcSecretKey): ?>
                    <div class="mt-1"><span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Clé secrète configurée</span></div>
                    <?php else: ?>
                    <div class="mt-1"><span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Aucune clé secrète configurée</span></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Formulaires publics couverts -->
            <div class="config-card">
                <h5><i class="bi bi-list-check me-2"></i>Formulaires protégés</h5>
                <p class="text-muted mb-3">Lorsque le reCAPTCHA est activé, les formulaires suivants sont protégés :</p>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex align-items-center gap-2">
                        <i class="bi bi-file-earmark-text text-primary"></i>
                        <span><strong>Formulaire de candidature locative</strong> — protégé automatiquement.</span>
                    </li>
                    <li class="list-group-item d-flex align-items-center gap-2">
                        <i class="bi bi-envelope-at text-primary"></i>
                        <span><strong>Formulaires de contact dynamiques</strong> — protégés dès qu'un champ de type
                            <code>recaptcha</code> est ajouté via l'éditeur de formulaire.</span>
                        <a href="formulaires-contact.php" class="btn btn-sm btn-outline-primary ms-auto">
                            <i class="bi bi-pencil me-1"></i>Éditeur de formulaires
                        </a>
                    </li>
                </ul>
            </div>

            <div class="d-flex gap-3 mb-4">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-save me-1"></i>Enregistrer la configuration
                </button>
                <a href="parametres.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Retour
                </a>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function highlightType() {
    document.querySelectorAll('[name="recaptcha_type"]').forEach(function(radio) {
        var card = radio.closest('.card');
        if (radio.checked) {
            card.classList.add('border-primary', 'border-2');
        } else {
            card.classList.remove('border-primary', 'border-2');
        }
    });
    var isV3 = document.getElementById('rcV3').checked;
    document.getElementById('v3ScoreBlock').style.display = isV3 ? '' : 'none';
}
document.querySelectorAll('[name="recaptcha_type"]').forEach(function(r) {
    r.addEventListener('change', highlightType);
});
</script>
</body>
</html>
