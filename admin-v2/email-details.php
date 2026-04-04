<?php
/**
 * DÉTAIL D'UN EMAIL ENVOYÉ
 *
 * Affiche le contenu complet d'un email loggé.
 * Accessible depuis email-tracker.php (ouverture dans un nouvel onglet).
 */
ob_start();
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('ID invalide.');
}

$stmt = $pdo->prepare("SELECT * FROM email_logs WHERE id = ?");
$stmt->execute([$id]);
$email = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$email) {
    http_response_code(404);
    die('Email introuvable.');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détail Email #<?= $id ?> - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f4f6fb; }
        .detail-card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 30px; margin-bottom: 24px; }
        .meta-label { font-weight: 600; color: #495057; min-width: 140px; display: inline-block; }
        .email-preview { border: 1px solid #dee2e6; border-radius: 6px; background: #fff; min-height: 400px; width: 100%; }
        .page-header { background: #fff; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); padding: 20px 30px; margin-bottom: 24px; display: flex; align-items: center; gap: 16px; }
    </style>
</head>
<body>
<div class="container py-4" style="max-width:960px;">

    <div class="page-header">
        <a href="email-tracker.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Retour
        </a>
        <div>
            <h4 class="mb-0"><i class="bi bi-envelope-open"></i> Détail de l'email <span class="text-muted">#<?= $id ?></span></h4>
        </div>
    </div>

    <!-- Métadonnées -->
    <div class="detail-card">
        <div class="row g-3">
            <div class="col-md-6">
                <p class="mb-2">
                    <span class="meta-label">Destinataire :</span>
                    <?= htmlspecialchars($email['destinataire']) ?>
                </p>
                <p class="mb-2">
                    <span class="meta-label">Sujet :</span>
                    <?= htmlspecialchars($email['sujet']) ?>
                </p>
                <p class="mb-2">
                    <span class="meta-label">Date d'envoi :</span>
                    <?= htmlspecialchars($email['date_envoi']) ?>
                </p>
                <p class="mb-0">
                    <span class="meta-label">Statut :</span>
                    <?php if ($email['statut'] === 'success'): ?>
                        <span class="badge bg-success"><i class="bi bi-check-circle"></i> Envoyé</span>
                    <?php else: ?>
                        <span class="badge bg-danger"><i class="bi bi-x-circle"></i> Échec</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-md-6">
                <?php if (!empty($email['template_id'])): ?>
                <p class="mb-2">
                    <span class="meta-label">Template :</span>
                    <span class="badge bg-secondary"><?= htmlspecialchars($email['template_id']) ?></span>
                </p>
                <?php endif; ?>
                <?php if (!empty($email['contexte'])): ?>
                <p class="mb-2">
                    <span class="meta-label">Contexte :</span>
                    <?= htmlspecialchars($email['contexte']) ?>
                </p>
                <?php endif; ?>
                <?php if (!empty($email['message_erreur'])): ?>
                <p class="mb-0">
                    <span class="meta-label">Erreur :</span>
                    <span class="text-danger"><?= htmlspecialchars($email['message_erreur']) ?></span>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($email['piece_jointe'])): ?>
        <hr>
        <p class="mb-1"><strong><i class="bi bi-paperclip"></i> Pièce(s) jointe(s) :</strong></p>
        <?php
        $files = explode(', ', $email['piece_jointe']);
        foreach ($files as $index => $f):
            $f = str_replace('\\', '/', trim($f));
            $fname = basename($f);
        ?>
            <a href="email-tracker.php?action=download_attachment&id=<?= $id ?>&file_index=<?= $index ?>"
               class="btn btn-sm btn-outline-secondary me-2 mb-1" target="_blank" rel="noopener">
                <i class="bi bi-file-earmark-arrow-down"></i> <?= htmlspecialchars($fname) ?>
            </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Corps de l'email -->
    <div class="detail-card">
        <h6 class="mb-3"><i class="bi bi-file-text"></i> Contenu de l'email</h6>
        <?php if (!empty($email['corps_html'])): ?>
            <iframe class="email-preview"
                    srcdoc="<?= htmlspecialchars($email['corps_html']) ?>"
                    sandbox="allow-same-origin"
                    style="min-height:500px; height:70vh;">
            </iframe>
        <?php else: ?>
            <p class="text-muted"><em>Aucun contenu disponible.</em></p>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
