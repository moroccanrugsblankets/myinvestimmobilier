<?php
/**
 * Guide des réparations locatives — Page publique
 *
 * URL par défaut : /guide-reparations.php
 * Le contenu et le slug sont configurables depuis l'interface admin.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$contenu = getParameter('guide_reparations_contenu', '<p>Ce guide n\'est pas encore configuré.</p>');
$companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';
$signalementUrl = rtrim($config['SITE_URL'] ?? '', '/') . '/signalement/form.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guide des réparations locatives — <?php echo htmlspecialchars($companyName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f0f4f8; }
        .guide-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
        .header-brand { background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%); color: #fff; border-radius: 14px 14px 0 0; padding: 28px 30px 22px; }
        .header-brand h1 { font-size: 1.5rem; margin-bottom: 0.25rem; }
        .guide-content h1 { font-size: 1.6rem; color: #2c3e50; margin-top: 0; }
        .guide-content h2 { font-size: 1.2rem; color: #2c3e50; margin-top: 1.5rem; border-bottom: 2px solid #e9ecef; padding-bottom: 6px; }
        .guide-content h3 { font-size: 1rem; color: #3498db; margin-top: 1.2rem; }
        .guide-content hr { border-color: #dee2e6; margin: 1.5rem 0; }
        .guide-content ul { padding-left: 1.4rem; }
        .guide-content li { margin-bottom: 4px; }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-md-10">

            <div class="guide-card mb-4">
                <div class="header-brand">
                    <h1>📘 Guide des réparations locatives</h1>
                    <p class="mb-0 opacity-75"><?php echo htmlspecialchars($companyName); ?></p>
                </div>

                <div class="p-4 p-md-5 guide-content">
                    <?php echo $contenu; ?>
                </div>
            </div>

            <div class="text-center">
                <a href="<?php echo htmlspecialchars($signalementUrl); ?>" class="btn btn-primary">
                    <i class="bi bi-exclamation-triangle me-2"></i>Signaler une anomalie
                </a>
            </div>

            <p class="text-center text-muted small mt-4">
                <?php echo htmlspecialchars($companyName); ?>
                <?php if (!empty($config['COMPANY_EMAIL'])): ?>
                &nbsp;—&nbsp;
                <a href="mailto:<?php echo htmlspecialchars($config['COMPANY_EMAIL']); ?>" class="text-muted">
                    <?php echo htmlspecialchars($config['COMPANY_EMAIL']); ?>
                </a>
                <?php endif; ?>
            </p>

        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
