<?php
/**
 * Administration — Menu frontoffice configurable
 * My Invest Immobilier
 *
 * Permet d'ajouter, modifier, réordonner et supprimer les éléments du menu
 * de navigation affiché sur le site public.
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

// ── Actions POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle AJAX drag-and-drop reorder (JSON body, ?reorder=1)
    if (isset($_GET['reorder'])) {
        $body  = file_get_contents('php://input');
        $items = json_decode($body, true);
        if (is_array($items)) {
            $stmt = $pdo->prepare("UPDATE frontend_menu_items SET ordre = ? WHERE id = ?");
            foreach ($items as $item) {
                if (isset($item['id'], $item['ordre'])) {
                    $stmt->execute([(int)$item['ordre'], (int)$item['id']]);
                }
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_item') {
        $id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $label  = trim($_POST['label']  ?? '');
        $url    = trim($_POST['url']    ?? '');
        $target = ($_POST['target'] ?? '_self') === '_blank' ? '_blank' : '_self';
        $icone  = trim($_POST['icone']  ?? '');
        $ordre  = isset($_POST['ordre']) ? (int)$_POST['ordre'] : 0;
        $actif  = isset($_POST['actif']) ? 1 : 0;

        if (empty($label) || empty($url)) {
            $_SESSION['error'] = 'Le libellé et l\'URL sont obligatoires.';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE frontend_menu_items
                    SET label = ?, url = ?, target = ?, icone = ?, ordre = ?, actif = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$label, $url, $target, $icone, $ordre, $actif, $id]);
                $_SESSION['success'] = 'Élément mis à jour.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO frontend_menu_items (label, url, target, icone, ordre, actif)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$label, $url, $target, $icone, $ordre, $actif]);
                $_SESSION['success'] = 'Élément ajouté.';
            }
        }
        header('Location: menu-frontoffice.php');
        exit;

    } elseif ($action === 'delete_item') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $pdo->prepare("DELETE FROM frontend_menu_items WHERE id = ?")->execute([$id]);
            $_SESSION['success'] = 'Élément supprimé.';
        }
        header('Location: menu-frontoffice.php');
        exit;
    }
}

// ── Chargement des données ────────────────────────────────────────────────────
$menuItems = [];
try {
    $menuItems = $pdo->query("SELECT * FROM frontend_menu_items ORDER BY ordre ASC, id ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table not yet created — show a warning
    $tableError = true;
}

$editItem = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    foreach ($menuItems as $item) {
        if ($item['id'] === $editId) {
            $editItem = $item;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu frontoffice — My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .drag-handle { cursor: grab; color: #aaa; }
        .drag-handle:active { cursor: grabbing; }
        tr.dragging { opacity: .4; background: #f0f4ff; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/menu.php'; ?>
<div class="main-content">
    <!-- Alerts -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show m-3" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show m-3" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="container-fluid p-4">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h2 class="mb-0"><i class="bi bi-list-ul me-2 text-primary"></i>Menu du site public</h2>
                <p class="text-muted mb-0 mt-1">Gérez les liens affichés dans la barre de navigation du front office.</p>
            </div>
            <a href="pages-frontoffice.php" class="btn btn-outline-secondary">
                <i class="bi bi-file-earmark-text me-1"></i>Gérer les pages
            </a>
        </div>

        <?php if (!empty($tableError)): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            La table <code>frontend_menu_items</code> n'existe pas encore.
            Veuillez <a href="../run-migrations.php">exécuter les migrations</a> pour l'initialiser.
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- ── Formulaire d'ajout / modification ── -->
            <div class="col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-<?php echo $editItem ? 'pencil' : 'plus-circle'; ?> me-2 text-primary"></i>
                        <?php echo $editItem ? 'Modifier l\'élément' : 'Ajouter un élément'; ?>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="save_item">
                            <?php if ($editItem): ?>
                                <input type="hidden" name="id" value="<?php echo $editItem['id']; ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Libellé <span class="text-danger">*</span></label>
                                <input type="text" name="label" class="form-control"
                                       value="<?php echo htmlspecialchars($editItem['label'] ?? ''); ?>"
                                       placeholder="Ex: Nos Services" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">URL <span class="text-danger">*</span></label>
                                <input type="text" name="url" class="form-control"
                                       value="<?php echo htmlspecialchars($editItem['url'] ?? ''); ?>"
                                       placeholder="Ex: /page.php?slug=services" required>
                                <div class="form-text">URL relative (ex: /logements.php) ou absolue.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Icône Bootstrap Icons</label>
                                <div class="input-group">
                                    <span class="input-group-text" id="iconePreview">
                                        <i class="bi <?php echo htmlspecialchars($editItem['icone'] ?? 'bi-link'); ?>"></i>
                                    </span>
                                    <input type="text" name="icone" id="iconeInput" class="form-control"
                                           value="<?php echo htmlspecialchars($editItem['icone'] ?? ''); ?>"
                                           placeholder="Ex: bi-house">
                                </div>
                                <div class="form-text">Classe Bootstrap Icons sans le préfixe <code>bi </code>, ex: <code>bi-house</code>.</div>
                            </div>
                            <div class="row mb-3">
                                <div class="col">
                                    <label class="form-label fw-semibold">Ordre</label>
                                    <input type="number" name="ordre" class="form-control"
                                           value="<?php echo (int)($editItem['ordre'] ?? 10); ?>" min="0" step="10">
                                </div>
                                <div class="col">
                                    <label class="form-label fw-semibold">Ouverture</label>
                                    <select name="target" class="form-select">
                                        <option value="_self" <?php echo (!$editItem || $editItem['target'] === '_self') ? 'selected' : ''; ?>>Même onglet</option>
                                        <option value="_blank" <?php echo ($editItem && $editItem['target'] === '_blank') ? 'selected' : ''; ?>>Nouvel onglet</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="actif" id="actifSwitch"
                                       <?php echo (!$editItem || $editItem['actif']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="actifSwitch">Visible dans le menu</label>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-grow-1">
                                    <i class="bi bi-<?php echo $editItem ? 'save' : 'plus-lg'; ?> me-1"></i>
                                    <?php echo $editItem ? 'Enregistrer' : 'Ajouter'; ?>
                                </button>
                                <?php if ($editItem): ?>
                                <a href="menu-frontoffice.php" class="btn btn-outline-secondary">Annuler</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ── Liste des éléments ── -->
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-white d-flex align-items-center justify-content-between">
                        <span class="fw-semibold"><i class="bi bi-layout-three-columns me-2 text-primary"></i>Éléments du menu (<?php echo count($menuItems); ?>)</span>
                        <small class="text-muted"><i class="bi bi-arrows-move me-1"></i>Glisser-déposer pour réordonner</small>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($menuItems)): ?>
                        <p class="text-muted text-center py-4">Aucun élément. Utilisez le formulaire ci-contre pour en ajouter.</p>
                        <?php else: ?>
                        <table class="table table-hover align-middle mb-0" id="menuTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:30px;"></th>
                                    <th>Libellé</th>
                                    <th>URL</th>
                                    <th class="text-center" style="width:80px;">Ordre</th>
                                    <th class="text-center" style="width:70px;">Visible</th>
                                    <th style="width:100px;"></th>
                                </tr>
                            </thead>
                            <tbody id="sortableBody">
                            <?php foreach ($menuItems as $item): ?>
                            <tr data-id="<?php echo $item['id']; ?>" data-ordre="<?php echo $item['ordre']; ?>">
                                <td><span class="drag-handle"><i class="bi bi-grip-vertical fs-5"></i></span></td>
                                <td>
                                    <?php if (!empty($item['icone'])): ?>
                                        <i class="bi <?php echo htmlspecialchars($item['icone']); ?> me-1 text-muted"></i>
                                    <?php endif; ?>
                                    <strong><?php echo htmlspecialchars($item['label']); ?></strong>
                                    <?php if ($item['target'] === '_blank'): ?>
                                        <small class="text-muted ms-1"><i class="bi bi-box-arrow-up-right"></i></small>
                                    <?php endif; ?>
                                </td>
                                <td><code class="small"><?php echo htmlspecialchars($item['url']); ?></code></td>
                                <td class="text-center text-muted small"><?php echo $item['ordre']; ?></td>
                                <td class="text-center">
                                    <?php if ($item['actif']): ?>
                                        <span class="badge bg-success-subtle text-success">Oui</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary">Non</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="menu-frontoffice.php?edit=<?php echo $item['id']; ?>" class="btn btn-sm btn-outline-primary me-1" title="Modifier">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer cet élément ?');">
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($menuItems)): ?>
                <div class="mt-3 text-end">
                    <a href="<?php echo htmlspecialchars(($config['SITE_URL'] ?? '') . '/logements.php'); ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-eye me-1"></i>Prévisualiser le site public
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Live icon preview
(function () {
    var input   = document.getElementById('iconeInput');
    var preview = document.getElementById('iconePreview');
    if (!input || !preview) return;
    input.addEventListener('input', function () {
        var cls = this.value.trim();
        preview.innerHTML = cls ? '<i class="bi ' + cls.replace(/"/g, '') + '"></i>' : '<i class="bi bi-link"></i>';
    });
}());

// Drag-and-drop reorder
(function () {
    var tbody = document.getElementById('sortableBody');
    if (!tbody) return;
    var dragging = null;

    tbody.addEventListener('dragstart', function (e) {
        dragging = e.target.closest('tr');
        if (dragging) { dragging.classList.add('dragging'); }
    });
    tbody.addEventListener('dragend', function () {
        if (dragging) { dragging.classList.remove('dragging'); dragging = null; }
        saveOrder();
    });
    tbody.addEventListener('dragover', function (e) {
        e.preventDefault();
        var target = e.target.closest('tr');
        if (target && dragging && target !== dragging) {
            var rect = target.getBoundingClientRect();
            if (e.clientY < rect.top + rect.height / 2) {
                tbody.insertBefore(dragging, target);
            } else {
                tbody.insertBefore(dragging, target.nextSibling);
            }
        }
    });

    // Enable draggable on all rows
    Array.from(tbody.querySelectorAll('tr')).forEach(function (tr) {
        tr.setAttribute('draggable', 'true');
    });

    function saveOrder() {
        var rows = Array.from(tbody.querySelectorAll('tr[data-id]'));
        var data = rows.map(function (tr, idx) {
            return { id: parseInt(tr.dataset.id, 10), ordre: (idx + 1) * 10 };
        });
        fetch('menu-frontoffice.php?reorder=1', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        }).catch(console.error);
    }
}());
</script>
</body>
</html>
