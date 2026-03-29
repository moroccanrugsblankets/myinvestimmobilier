<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update') {
        $id = (int)$_POST['template_id'];
        $stmt = $pdo->prepare("UPDATE email_templates SET nom = ?, sujet = ?, corps_html = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([
            $_POST['nom'],
            $_POST['sujet'],
            $_POST['corps_html'],
            $id
        ]);
        $_SESSION['success'] = "Template mis à jour avec succès";
        header('Location: email-templates.php');
        exit;
    } elseif ($_POST['action'] === 'update_order') {
        // Handle AJAX request to update template order
        header('Content-Type: application/json');
        
        $order = json_decode($_POST['order'], true);
        if (is_array($order)) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE email_templates SET ordre = ? WHERE id = ?");
                foreach ($order as $index => $templateId) {
                    $stmt->execute([$index + 1, (int)$templateId]);
                }
                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid order data']);
        }
        exit;
    }
}

// Get template to edit if ID provided
$template = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM email_templates WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all templates
$stmt = $pdo->query("SELECT * FROM email_templates ORDER BY ordre ASC, id ASC");
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Templates d'Email - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- SortableJS for drag & drop -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <!-- TinyMCE Cloud - API key is public and domain-restricted -->
    <script src="https://cdn.tiny.cloud/1/odjqanpgdv2zolpduplee65ntoou1b56hg6gvgxvrt8dreh0/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .template-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .template-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .template-card.sortable-ghost {
            opacity: 0.4;
            background: #f0f0f0;
        }
        .template-card.sortable-drag {
            opacity: 0.8;
            transform: rotate(2deg);
        }
        .drag-handle {
            cursor: grab;
            color: #6c757d;
            margin-right: 10px;
        }
        .drag-handle:active {
            cursor: grabbing;
        }
        .template-card h5 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        .template-card .badge {
            font-size: 0.75rem;
        }
        .variables-info {
            background: #e8f4f8;
            border-left: 4px solid #3498db;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .variables-info h6 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        .variable-tag {
            display: inline-block;
            background: #3498db;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            margin: 2px;
            font-family: 'Courier New', monospace;
        }
        .code-editor {
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            min-height: 400px;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4>Templates d'Email</h4>
                    <p class="text-muted mb-0">Gérer les modèles d'emails automatiques</p>
                </div>
                <?php if ($template): ?>
                <a href="email-templates.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Retour à la liste
                </a>
                <?php else: ?>
                <div class="text-muted small">
                    <i class="bi bi-arrows-move"></i> Glissez-déposez pour réorganiser
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($template): ?>
            <!-- Edit Template Form -->
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="bi bi-pencil"></i> Modifier le template: <?php echo htmlspecialchars($template['nom']); ?>
                    </h5>
                    
                    <div class="variables-info mt-3">
                        <h6><i class="bi bi-info-circle"></i> Variables disponibles</h6>
                        <p class="mb-2 small">Utilisez ces variables dans le sujet et le corps de l'email. Elles seront automatiquement remplacées par les vraies valeurs.</p>
                        <?php 
                        $variables = json_decode($template['variables_disponibles'], true);
                        if ($variables):
                            foreach ($variables as $var):
                        ?>
                            <span class="variable-tag">{{<?php echo $var; ?>}}</span>
                        <?php 
                            endforeach;
                        endif;
                        ?>
                        <span class="variable-tag" style="background: #27ae60;">{{signature}}</span>
                        <span class="variable-tag" style="background: #8e44ad;">{{company}}</span>
                        <p class="mt-2 mb-0 small"><strong>Note:</strong> Les variables <code>{{signature}}</code> et <code>{{company}}</code> sont disponibles pour tous les templates. La signature est configurée dans les paramètres ; le nom de la société dans <a href="parametres.php">Paramètres → Général</a>.</p>
                    </div>

                    <form method="POST" action="email-templates.php">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Nom du template</label>
                            <input type="text" name="nom" class="form-control" value="<?php echo htmlspecialchars($template['nom']); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Sujet de l'email</label>
                            <input type="text" name="sujet" class="form-control" value="<?php echo htmlspecialchars($template['sujet']); ?>" required>
                            <small class="text-muted">Vous pouvez utiliser les variables comme {{nom}}, {{prenom}}, etc.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Corps HTML de l'email</label>
                            <textarea name="corps_html" class="form-control code-editor" required><?php echo htmlspecialchars($template['corps_html']); ?></textarea>
                            <small class="text-muted">Code HTML complet avec les styles inline. Les variables seront remplacées lors de l'envoi.</small>
                        </div>
                        
                        <div class="text-end">
                            <a href="email-templates.php" class="btn btn-secondary">Annuler</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Enregistrer les modifications
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- List Templates -->
            <div class="row" id="templates-list">
                <?php foreach ($templates as $tpl): ?>
                <div class="col-md-6 col-lg-4" data-template-id="<?php echo $tpl['id']; ?>">
                    <div class="template-card">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="d-flex align-items-start flex-grow-1">
                                <i class="bi bi-grip-vertical drag-handle fs-5"></i>
                                <h5 class="mb-0"><?php echo htmlspecialchars($tpl['nom']); ?></h5>
                            </div>
                            <?php if ($tpl['actif']): ?>
                                <span class="badge bg-success">Actif</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactif</span>
                            <?php endif; ?>
                        </div>
                        
                        <p class="text-muted small mb-2">
                            <strong>ID:</strong> <code><?php echo htmlspecialchars($tpl['identifiant']); ?></code>
                        </p>
                        
                        <?php if ($tpl['description']): ?>
                        <p class="small mb-3"><?php echo htmlspecialchars($tpl['description']); ?></p>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <strong class="small">Sujet:</strong>
                            <p class="small text-muted mb-0"><?php echo htmlspecialchars($tpl['sujet']); ?></p>
                        </div>

                        <?php if (!empty($tpl['variables_disponibles'])): ?>
                        <div class="mb-3">
                            <strong class="small">Variables :</strong><br>
                            <?php
                            // Support both JSON arrays and comma-separated strings
                            $vars = json_decode($tpl['variables_disponibles'], true);
                            if (!is_array($vars)) {
                                $vars = array_filter(array_map('trim', explode(',', $tpl['variables_disponibles'])));
                            }
                            foreach ($vars as $v):
                                if (trim($v) !== ''):
                            ?>
                                <span class="variable-tag" style="font-size:0.75rem;padding:2px 5px;margin:1px;">{{<?php echo htmlspecialchars(trim($v)); ?>}}</span>
                            <?php endif; endforeach; ?>
                            <span class="variable-tag" style="background:#27ae60;font-size:0.75rem;padding:2px 5px;margin:1px;">{{signature}}</span>
                            <span class="variable-tag" style="background:#8e44ad;font-size:0.75rem;padding:2px 5px;margin:1px;">{{company}}</span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">
                                <i class="bi bi-clock"></i> <?php echo date('d/m/Y', strtotime($tpl['updated_at'])); ?>
                            </small>
                            <a href="email-templates.php?edit=<?php echo $tpl['id']; ?>" class="btn btn-sm btn-primary">
                                <i class="bi bi-pencil"></i> Modifier
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize drag & drop for template cards
        document.addEventListener('DOMContentLoaded', function() {
            const templatesList = document.getElementById('templates-list');
            
            if (templatesList) {
                // Initialize Sortable
                const sortable = Sortable.create(templatesList, {
                    animation: 150,
                    ghostClass: 'sortable-ghost',
                    dragClass: 'sortable-drag',
                    handle: '.drag-handle',
                    onEnd: function(evt) {
                        // Get the new order of template IDs
                        const templateItems = templatesList.querySelectorAll('[data-template-id]');
                        const order = [];
                        templateItems.forEach(function(item) {
                            order.push(item.getAttribute('data-template-id'));
                        });
                        
                        // Send AJAX request to save the new order
                        saveTemplateOrder(order);
                    }
                });
            }
        });
        
        // Function to save template order via AJAX
        function saveTemplateOrder(order) {
            const formData = new FormData();
            formData.append('action', 'update_order');
            formData.append('order', JSON.stringify(order));
            
            fetch('email-templates.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Order saved successfully');
                    // Optional: Show a success message
                    showNotification('Ordre sauvegardé avec succès', 'success');
                } else {
                    console.error('Failed to save order:', data.error);
                    showNotification('Erreur lors de la sauvegarde de l\'ordre', 'error');
                }
            })
            .catch(error => {
                console.error('Error saving order:', error);
                showNotification('Erreur lors de la sauvegarde de l\'ordre', 'error');
            });
        }
        
        // Function to show notification
        function showNotification(message, type) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show position-fixed`;
            alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(alertDiv);
            
            // Auto-remove after 3 seconds
            setTimeout(() => {
                alertDiv.remove();
            }, 3000);
        }
        
        // Initialize TinyMCE on the email body editor
        tinymce.init({
            selector: 'textarea.code-editor',
            height: 500,
            menubar: true,
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'media', 'table', 'help', 'wordcount'
            ],
            toolbar: 'undo redo | blocks | bold italic forecolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat | code | help',
            content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; }',
            branding: false,
            promotion: false,
            // Preserve full HTML document structure including <html>, <head>, <style> tags
            verify_html: false,
            extended_valid_elements: 'style,link[href|rel],head,html[lang],meta[*],body[*]',
            valid_children: '+body[style],+head[style]',
            // Don't remove tags or attributes
            forced_root_block: false,
            // --- Configuration pour forcer les liens absolus ---
            relative_urls: false,
            remove_script_host: false,
            convert_urls: false,
            // Preserve DOCTYPE and full document structure
            doctype: '<!DOCTYPE html>',
            setup: function(editor) {
                editor.on('init', function() {
                    console.log('TinyMCE initialized successfully');
                });
            }
        });
    </script>
</body>
</html>
