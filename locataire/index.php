<?php
/**
 * Portail locataire — Espace locataire
 * My Invest Immobilier
 *
 * Portail permettant au locataire de :
 *  - S'identifier par son adresse email
 *  - Choisir entre "Déclaration d'anomalie" et "Procédure de Départ"
 *
 * La déclaration d'anomalie est gérée sur /signalement/form.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// ── État courant ──────────────────────────────────────────────────────────────
$errors      = [];
$state       = $_SESSION['portal_state']     ?? 'auth';
$locataire   = $_SESSION['portal_locataire'] ?? null;
$emailSaisi  = $_SESSION['portal_email']     ?? '';

// Sécurité : si les données de session locataire semblent incomplètes, revenir à l'authentification
if ($locataire !== null && (empty($locataire['id']) || empty($locataire['contrat_id']))) {
    $locataire = null;
    $state     = 'auth';
    unset($_SESSION['portal_state'], $_SESSION['portal_locataire'], $_SESSION['portal_email']);
}

// Rediriger vers le formulaire si l'état est dans le wizard anomalie
if (in_array($state, ['anomalie1', 'anomalie2', 'anomalie3'])) {
    header('Location: /signalement/form.php');
    exit;
}

// ── Traitement des POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Authentification par email ─────────────────────────────────────────
    if ($action === 'auth') {
        $emailSaisi = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($emailSaisi, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adresse email invalide.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT loc.id, loc.prenom, loc.nom, loc.email, loc.telephone,
                           c.id as contrat_id, c.reference_unique as contrat_ref,
                           l.id as logement_id, l.adresse, l.reference as logement_ref
                    FROM locataires loc
                    INNER JOIN contrats c ON loc.contrat_id = c.id
                    INNER JOIN logements l ON c.logement_id = l.id
                    WHERE LOWER(loc.email) = ?
                      AND c.statut = 'valide'
                      AND (c.date_prise_effet IS NULL OR c.date_prise_effet <= CURDATE())
                    ORDER BY c.date_prise_effet DESC, c.id DESC
                    LIMIT 1
                ");
                $stmt->execute([$emailSaisi]);
                $locataire = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log('locataire/index.php portal auth DB error: ' . $e->getMessage());
                $errors[] = 'Erreur interne. Veuillez réessayer plus tard.';
            }

            if (empty($errors) && !$locataire) {
                $errors[] = "Aucun contrat actif trouvé pour cette adresse email. Vérifiez l'adresse saisie ou contactez votre gestionnaire.";
            }

            if (empty($errors) && $locataire) {
                $_SESSION['portal_locataire'] = $locataire;
                $_SESSION['portal_email']     = $emailSaisi;
                $_SESSION['portal_state']     = 'choice';
                $state = 'choice';
            }
        }

    // ── Choix : anomalie ───────────────────────────────────────────────────
    } elseif ($action === 'choose_anomalie' && $locataire) {
        $_SESSION['portal_state'] = 'anomalie1';
        header('Location: /signalement/form.php');
        exit;

    // ── Choix : procédure de départ ────────────────────────────────────────
    } elseif ($action === 'choose_depart' && $locataire) {
        $token = $locataire['contrat_ref'] ?? '';
        header('Location: /signature/procedure-depart.php?token=' . urlencode($token));
        exit;

    // ── Déconnexion ────────────────────────────────────────────────────────
    } elseif ($action === 'logout') {
        unset($_SESSION['portal_state'], $_SESSION['portal_locataire'], $_SESSION['portal_email'], $_SESSION['portal_checklist']);
        header('Location: /locataire/');
        exit;
    }
}

$companyName  = $config['COMPANY_NAME']  ?? 'My Invest Immobilier';
$siteUrl      = rtrim($config['SITE_URL'] ?? '', '/');
$companyEmail = $config['COMPANY_EMAIL'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portail locataire — <?php echo htmlspecialchars($companyName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($siteUrl . '/assets/css/frontoffice.css'); ?>">
    <style>
        .portal-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .portal-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: #fff;
            padding: 32px 36px 26px;
        }
        .portal-body { padding: 36px; }
        .choice-box {
            display: block;
            border: 2px solid #dee2e6;
            border-radius: 12px;
            padding: 28px 20px;
            text-align: center;
            cursor: pointer;
            transition: border-color .15s, background .15s, transform .1s;
            text-decoration: none;
            color: inherit;
            background: #fff;
            width: 100%;
        }
        .choice-box:hover {
            border-color: #3498db;
            background: #eaf4fd;
            transform: translateY(-2px);
        }
        .choice-box .choice-icon  { font-size: 2.8rem; margin-bottom: 10px; }
        .choice-box .choice-title { font-size: 1.05rem; font-weight: 700; color: #2c3e50; }
        .choice-box .choice-desc  { font-size: 0.85rem; color: #6c757d; margin-top: 6px; }
    </style>
</head>
<body>
<?php
require_once __DIR__ . '/../includes/header-frontoffice.php';
renderFrontOfficeHeader($siteUrl, $companyName);
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12">

            <div class="portal-card">

                <?php /* ── En-tête ── */ ?>
                <div class="portal-header">
                    <h2 class="mb-1 fs-4">
                        <?php if ($state === 'auth'): ?>
                            <i class="bi bi-house-door me-2"></i>Portail locataire
                        <?php elseif ($state === 'choice'): ?>
                            <i class="bi bi-grid me-2"></i>Bonjour, <?php echo htmlspecialchars($locataire['prenom']); ?> !
                        <?php endif; ?>
                    </h2>
                    <p class="mb-0 opacity-75" style="font-size:.92rem;">
                        <?php if ($state === 'auth'): ?>
                            <?php echo htmlspecialchars($companyName); ?>
                        <?php elseif ($locataire): ?>
                            <i class="bi bi-house me-1"></i><?php echo htmlspecialchars($locataire['adresse']); ?>
                            <?php if (!empty($locataire['logement_ref'])): ?>
                                &nbsp;—&nbsp;<span class="font-monospace"><?php echo htmlspecialchars($locataire['logement_ref']); ?></span>
                            <?php endif; ?>
                            &nbsp;—&nbsp;<i class="bi bi-person me-1"></i><?php echo htmlspecialchars($locataire['prenom'] . ' ' . $locataire['nom']); ?>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="portal-body">

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger mb-4">
                            <i class="bi bi-exclamation-circle-fill me-2"></i>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($errors as $e): ?>
                                    <li><?php echo htmlspecialchars($e); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php /* ════════════════
                     *  AUTHENTIFICATION
                     * ════════════════ */ ?>
                    <?php if ($state === 'auth'): ?>

                        <p class="mb-1">
                            Afin de traiter votre demande efficacement, une identification est requise.
                        </p>
                        <p class="text-muted mb-4">
                            Merci de vous identifier avec l'adresse e-mail renseignée dans votre contrat de location.
                        </p>

                        <form method="POST" novalidate>
                            <input type="hidden" name="action" value="auth">
                            <div class="mb-4">
                                <label class="form-label fw-semibold" for="email">
                                    Votre adresse e-mail <span class="text-danger">*</span>
                                </label>
                                <input type="email" class="form-control form-control-lg" id="email" name="email"
                                       required autofocus placeholder="votre@email.fr"
                                       value="<?php echo htmlspecialchars($emailSaisi); ?>">
                            </div>
                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="bi bi-arrow-right me-2"></i>Continuer
                            </button>
                        </form>

                    <?php /* ════════════════
                     *  CHOIX
                     * ════════════════ */ ?>
                    <?php elseif ($state === 'choice'): ?>

                        <p class="text-muted mb-4">Que souhaitez-vous faire ?</p>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <form method="POST">
                                    <input type="hidden" name="action" value="choose_anomalie">
                                    <button type="submit" class="choice-box border-0">
                                        <div class="choice-icon">🛠️</div>
                                        <div class="choice-title">Déclaration d'anomalie</div>
                                        <div class="choice-desc">Signalez un problème dans votre logement</div>
                                    </button>
                                </form>
                            </div>
                            <div class="col-md-6">
                                <form method="POST">
                                    <input type="hidden" name="action" value="choose_depart">
                                    <button type="submit" class="choice-box border-0">
                                        <div class="choice-icon">🏠</div>
                                        <div class="choice-title">Procédure de Départ</div>
                                        <div class="choice-desc">Initiez votre départ du logement</div>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="text-end">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="logout">
                                <button type="submit" class="btn btn-link btn-sm text-muted p-0">
                                    <i class="bi bi-box-arrow-left me-1"></i>Se déconnecter
                                </button>
                            </form>
                        </div>

                    <?php endif; ?>

                </div>
            </div>

            <?php if ($locataire && $state !== 'auth'): ?>
            <p class="text-center mt-3 text-muted small">
                <?php echo htmlspecialchars($companyName); ?>
                <?php if (!empty($companyEmail)): ?>
                    &nbsp;—&nbsp;<a href="mailto:<?php echo htmlspecialchars($companyEmail); ?>" class="text-muted"><?php echo htmlspecialchars($companyEmail); ?></a>
                <?php endif; ?>
            </p>
            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
