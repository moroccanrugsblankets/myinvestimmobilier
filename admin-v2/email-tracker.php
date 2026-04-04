<?php
/**
 * SUIVI DES EMAILS ENVOYÉS
 *
 * Interface permettant de consulter, rechercher et filtrer
 * tous les emails envoyés par l'application.
 */
ob_start();
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

// ─── Actions AJAX ─────────────────────────────────────────────────────────────

// Voir le contenu d'un email (modal)
if (isset($_GET['action']) && $_GET['action'] === 'view_email' && isset($_GET['id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM email_logs WHERE id = ?");
    $stmt->execute([$id]);
    $email = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($email) {
        // Sanitize all string fields to ensure valid UTF-8 before JSON encoding
        array_walk_recursive($email, function (&$v) {
            if (is_string($v)) {
                $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
            }
        });
        $json = json_encode(['success' => true, 'email' => $email], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        echo $json ?: json_encode(['success' => false, 'error' => 'Erreur de sérialisation du contenu']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Email introuvable']);
    }
    exit;
}

// Télécharger une pièce jointe d'un email loggé
if (isset($_GET['action']) && $_GET['action'] === 'download_attachment' && isset($_GET['id'])) {
    ob_end_clean();
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT piece_jointe FROM email_logs WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['piece_jointe'])) {
        http_response_code(404);
        echo 'Pièce jointe introuvable.';
        exit;
    }
    // Handle comma-separated multiple files: serve the first one
    $storedPath = explode(', ', $row['piece_jointe'])[0];
    // Normalize path separators
    $normalized = str_replace('\\', '/', $storedPath);
    $projectRoot = realpath(dirname(__DIR__));
    // Try as relative path first (strip leading slash), then as absolute path for backwards compatibility
    $relativePath = ltrim($normalized, '/');
    $absolutePath = realpath($projectRoot . '/' . $relativePath);
    if (!$absolutePath || !is_file($absolutePath)) {
        $absolutePath = realpath($normalized) ?: null;
    }
    // Security: ensure the resolved path is inside the project directory
    if (!$absolutePath || strpos($absolutePath, $projectRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($absolutePath)) {
        http_response_code(404);
        echo 'Fichier introuvable.';
        exit;
    }
    $filename = basename($absolutePath);
    // Strip any characters that could cause header injection
    $safeFilename = preg_replace('/[^\w\-.]/', '_', $filename);
    // Detect MIME type for inline viewing (e.g. PDFs display in browser)
    $detected = mime_content_type($absolutePath);
    $mimeType = $detected ?: 'application/octet-stream';
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . $safeFilename . '"');
    header('Content-Length: ' . filesize($absolutePath));
    readfile($absolutePath);
    exit;
}

// ─── Paramètres de recherche / filtres ────────────────────────────────────────

$search       = trim($_GET['search'] ?? '');
$filterStatut = $_GET['statut'] ?? '';
$filterDate   = $_GET['date'] ?? '';
$filterTemplate = trim($_GET['template'] ?? '');

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

// ─── Construction de la requête ───────────────────────────────────────────────

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = "(destinataire LIKE ? OR sujet LIKE ? OR contexte LIKE ?)";
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($filterStatut !== '') {
    $where[]  = "statut = ?";
    $params[] = $filterStatut;
}

if ($filterDate !== '') {
    $where[]  = "DATE(date_envoi) = ?";
    $params[] = $filterDate;
}

if ($filterTemplate !== '') {
    $where[]  = "template_id LIKE ?";
    $params[] = '%' . $filterTemplate . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Compter le total
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM email_logs $whereSql");
$countStmt->execute($params);
$totalEmails = (int)$countStmt->fetchColumn();
$totalPages  = max(1, (int)ceil($totalEmails / $perPage));

// Récupérer les emails
$listParams   = array_merge($params, [$perPage, $offset]);
$listStmt = $pdo->prepare("
    SELECT id, destinataire, sujet, statut, template_id, contexte, piece_jointe, date_envoi, message_erreur
    FROM email_logs
    $whereSql
    ORDER BY date_envoi DESC
    LIMIT ? OFFSET ?
");
$listStmt->execute($listParams);
$emails = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques globales (sans filtre)
$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(statut = 'success') AS success,
        SUM(statut = 'error')   AS error,
        COUNT(DISTINCT destinataire) AS destinataires_uniques
    FROM email_logs
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Liste des templates pour le filtre
$templatesStmt = $pdo->query("SELECT DISTINCT template_id FROM email_logs WHERE template_id IS NOT NULL ORDER BY template_id");
$templatesList = $templatesStmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suivi des Emails - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stat-value { font-size: 2rem; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.85rem; margin-top: 4px; }
        .stat-card.success .stat-value { color: #28a745; }
        .stat-card.error   .stat-value { color: #dc3545; }
        .stat-card.total   .stat-value { color: #007bff; }
        .stat-card.unique  .stat-value { color: #6f42c1; }
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .email-table th { white-space: nowrap; }
        .badge-success { background-color: #28a745; }
        .badge-error   { background-color: #dc3545; }
        .truncate { max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; vertical-align: bottom; }
        .email-preview-body { max-height: 70vh; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; background: #fff; }
        .pagination .page-link { cursor: pointer; }
    </style>
</head>
<body>
<div class="d-flex">
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content flex-grow-1 p-4">

        <!-- En-tête -->
        <div class="header d-flex justify-content-between align-items-center">
            <div>
                <h2><i class="bi bi-envelope-check"></i> Suivi des Emails</h2>
                <p class="text-muted mb-0">Historique de tous les emails envoyés par l'application</p>
            </div>
        </div>

        <!-- Statistiques -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card total">
                    <div class="stat-value"><?= number_format((int)$stats['total']) ?></div>
                    <div class="stat-label">Emails envoyés (total)</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card success">
                    <div class="stat-value"><?= number_format((int)$stats['success']) ?></div>
                    <div class="stat-label">Envois réussis</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card error">
                    <div class="stat-value"><?= number_format((int)$stats['error']) ?></div>
                    <div class="stat-label">Échecs d'envoi</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card unique">
                    <div class="stat-value"><?= number_format((int)$stats['destinataires_uniques']) ?></div>
                    <div class="stat-label">Destinataires uniques</div>
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="filter-card">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label"><i class="bi bi-search"></i> Recherche</label>
                    <input type="text" name="search" class="form-control"
                           placeholder="Destinataire, sujet, contexte…"
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label"><i class="bi bi-funnel"></i> Statut</label>
                    <select name="statut" class="form-select">
                        <option value="">Tous</option>
                        <option value="success" <?= $filterStatut === 'success' ? 'selected' : '' ?>>✅ Réussi</option>
                        <option value="error"   <?= $filterStatut === 'error'   ? 'selected' : '' ?>>❌ Échec</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><i class="bi bi-calendar"></i> Date</label>
                    <input type="date" name="date" class="form-control"
                           value="<?= htmlspecialchars($filterDate) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label"><i class="bi bi-file-earmark-text"></i> Template</label>
                    <select name="template" class="form-select">
                        <option value="">Tous</option>
                        <?php foreach ($templatesList as $tpl): ?>
                            <option value="<?= htmlspecialchars($tpl) ?>"
                                <?= $filterTemplate === $tpl ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tpl) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="bi bi-search"></i> Filtrer
                    </button>
                    <a href="email-tracker.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Tableau des emails -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-table"></i>
                    <?= number_format($totalEmails) ?> email<?= $totalEmails > 1 ? 's' : '' ?>
                    <?= ($search || $filterStatut || $filterDate || $filterTemplate) ? '<small class="text-muted">(filtrés)</small>' : '' ?>
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($emails)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                        <p class="mt-2">Aucun email trouvé.</p>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover email-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Destinataire</th>
                                <th>Sujet</th>
                                <th>Template</th>
                                <th>Contexte</th>
                                <th>P.J.</th>
                                <th>Statut</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($emails as $email): ?>
                            <tr>
                                <td class="text-muted small"><?= $email['id'] ?></td>
                                <td class="text-nowrap small">
                                    <?= date('d/m/Y', strtotime($email['date_envoi'])) ?><br>
                                    <span class="text-muted"><?= date('H:i:s', strtotime($email['date_envoi'])) ?></span>
                                </td>
                                <td>
                                    <span class="truncate" style="max-width:180px;" title="<?= htmlspecialchars($email['destinataire']) ?>">
                                        <?= htmlspecialchars($email['destinataire']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="truncate" title="<?= htmlspecialchars($email['sujet']) ?>">
                                        <?= htmlspecialchars($email['sujet']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($email['template_id']): ?>
                                        <span class="badge bg-secondary small"><?= htmlspecialchars($email['template_id']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted">
                                    <?= htmlspecialchars($email['contexte'] ?? '—') ?>
                                </td>
                                <td class="small">
                                    <?php if ($email['piece_jointe']): ?>
                                        <?php $firstFile = str_replace('\\', '/', explode(', ', $email['piece_jointe'])[0]); ?>
                                        <a href="<?= htmlspecialchars($firstFile) ?>"
                                           title="<?= htmlspecialchars(basename($firstFile)) ?>"
                                           target="_blank"
                                           class="text-decoration-none">
                                            <i class="bi bi-paperclip"></i>
                                            <span class="d-none d-xl-inline"><?= htmlspecialchars(basename($firstFile)) ?></span>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($email['statut'] === 'success'): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle"></i> Envoyé</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger" title="<?= htmlspecialchars($email['message_erreur'] ?? '') ?>">
                                            <i class="bi bi-x-circle"></i> Échec
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="email-details.php?id=<?= $email['id'] ?>"
                                       target="_blank" rel="noopener"
                                       title="Voir le contenu">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="card-footer">
                <nav>
                    <ul class="pagination pagination-sm mb-0 justify-content-center">
                        <?php
                        $queryBase = http_build_query(array_filter([
                            'search'   => $search,
                            'statut'   => $filterStatut,
                            'date'     => $filterDate,
                            'template' => $filterTemplate,
                        ]));
                        $queryBase = $queryBase ? '&' . $queryBase : '';
                        ?>
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page - 1 . $queryBase ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        <?php
                        $start = max(1, $page - 2);
                        $end   = min($totalPages, $page + 2);
                        for ($p = $start; $p <= $end; $p++):
                        ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $p . $queryBase ?>"><?= $p ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page + 1 . $queryBase ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <div class="text-center text-muted small mt-1">
                    Page <?= $page ?> / <?= $totalPages ?>
                    (<?= number_format($totalEmails) ?> résultat<?= $totalEmails > 1 ? 's' : '' ?>)
                </div>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- .main-content -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
