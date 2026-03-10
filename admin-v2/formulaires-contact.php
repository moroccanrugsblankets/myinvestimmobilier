<?php
/**
 * Administration — Formulaires de contact
 * My Invest Immobilier
 *
 * Permet de créer des formulaires dynamiques insérables dans les pages via shortcode.
 * Shortcode : [contact-form id=N]
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
            $stmt = $pdo->prepare("UPDATE contact_form_fields SET ordre = ? WHERE id = ?");
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

    if ($action === 'save_form') {
        $id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $nom         = trim($_POST['nom'] ?? '');
        $emailDest   = trim($_POST['email_dest'] ?? '');
        $msgConfirm  = trim($_POST['message_confirmation'] ?? '');
        $actif       = isset($_POST['actif']) ? 1 : 0;

        if (empty($nom)) {
            $_SESSION['error'] = 'Le nom du formulaire est obligatoire.';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE contact_forms
                    SET nom = ?, email_dest = ?, message_confirmation = ?, actif = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$nom, $emailDest, $msgConfirm, $actif, $id]);
                $_SESSION['success'] = 'Formulaire mis à jour.';
            } else {
                // Auto-generate shortcode
                $shortcode = 'contact-form-' . uniqid();
                $stmt = $pdo->prepare("
                    INSERT INTO contact_forms (nom, shortcode, email_dest, message_confirmation, actif)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$nom, $shortcode, $emailDest, $msgConfirm, $actif]);
                $newId = (int)$pdo->lastInsertId();
                // Set a clean shortcode with the real id
                $pdo->prepare("UPDATE contact_forms SET shortcode = ? WHERE id = ?")
                    ->execute(['[contact-form id=' . $newId . ']', $newId]);
                $_SESSION['success'] = 'Formulaire créé. Shortcode : [contact-form id=' . $newId . ']';
            }
        }
        header('Location: formulaires-contact.php');
        exit;

    } elseif ($action === 'delete_form') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $pdo->prepare("DELETE FROM contact_forms WHERE id = ?")->execute([$id]);
            $_SESSION['success'] = 'Formulaire supprimé.';
        }
        header('Location: formulaires-contact.php');
        exit;

    } elseif ($action === 'save_field') {
        $formId     = isset($_POST['form_id'])   ? (int)$_POST['form_id']  : 0;
        $fieldId    = isset($_POST['field_id'])  ? (int)$_POST['field_id'] : 0;
        $nomChamp   = trim(strtolower(preg_replace('/[^a-z0-9_]/', '_', $_POST['nom_champ'] ?? '')));
        $label      = trim($_POST['label']       ?? '');
        $typeChamp  = $_POST['type_champ']       ?? 'text';
        $placeholder= trim($_POST['placeholder'] ?? '');
        $options    = trim($_POST['options']     ?? '');
        $requis     = isset($_POST['requis'])    ? 1 : 0;
        $ordre      = isset($_POST['ordre'])     ? (int)$_POST['ordre'] : 0;

        $allowedTypes = ['text', 'email', 'tel', 'textarea', 'select', 'checkbox'];
        if (!in_array($typeChamp, $allowedTypes)) $typeChamp = 'text';

        if (empty($nomChamp) || empty($label) || $formId <= 0) {
            $_SESSION['error'] = 'L\'identifiant et le libellé du champ sont obligatoires.';
        } else {
            if ($fieldId > 0) {
                $pdo->prepare("
                    UPDATE contact_form_fields
                    SET nom_champ = ?, label = ?, type_champ = ?, placeholder = ?,
                        options = ?, requis = ?, ordre = ?
                    WHERE id = ? AND form_id = ?
                ")->execute([$nomChamp, $label, $typeChamp, $placeholder, $options, $requis, $ordre, $fieldId, $formId]);
                $_SESSION['success'] = 'Champ mis à jour.';
            } else {
                $pdo->prepare("
                    INSERT INTO contact_form_fields (form_id, nom_champ, label, type_champ, placeholder, options, requis, ordre)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([$formId, $nomChamp, $label, $typeChamp, $placeholder, $options, $requis, $ordre]);
                $_SESSION['success'] = 'Champ ajouté.';
            }
        }
        header('Location: formulaires-contact.php?form=' . $formId);
        exit;

    } elseif ($action === 'delete_field') {
        $fieldId = isset($_POST['field_id']) ? (int)$_POST['field_id'] : 0;
        $formId  = isset($_POST['form_id'])  ? (int)$_POST['form_id']  : 0;
        if ($fieldId > 0) {
            $pdo->prepare("DELETE FROM contact_form_fields WHERE id = ?")->execute([$fieldId]);
            $_SESSION['success'] = 'Champ supprimé.';
        }
        header('Location: formulaires-contact.php?form=' . $formId);
        exit;

    }
}

// ── Chargement des données ────────────────────────────────────────────────────
$forms = [];
try {
    $forms = $pdo->query("SELECT * FROM contact_forms ORDER BY id DESC")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $tableError = true;
}

// Form detail view
$currentForm   = null;
$formFields    = [];
$formSubmissions = [];
$editField     = null;

if (isset($_GET['form'])) {
    $formIdView = (int)$_GET['form'];
    foreach ($forms as $f) {
        if ((int)$f['id'] === $formIdView) {
            $currentForm = $f;
            break;
        }
    }
    if ($currentForm) {
        $formFields = $pdo->prepare("SELECT * FROM contact_form_fields WHERE form_id = ? ORDER BY ordre ASC, id ASC");
        $formFields->execute([$currentForm['id']]);
        $formFields = $formFields->fetchAll(PDO::FETCH_ASSOC);

        // Last 20 submissions
        try {
            $stmtSub = $pdo->prepare("SELECT * FROM contact_form_submissions WHERE form_id = ? ORDER BY created_at DESC LIMIT 20");
            $stmtSub->execute([$currentForm['id']]);
            $formSubmissions = $stmtSub->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        if (isset($_GET['edit_field'])) {
            $efId = (int)$_GET['edit_field'];
            foreach ($formFields as $fld) {
                if ((int)$fld['id'] === $efId) {
                    $editField = $fld;
                    break;
                }
            }
        }
    }
}

$editFormData = null;
if (isset($_GET['edit'])) {
    $efId = (int)$_GET['edit'];
    foreach ($forms as $f) {
        if ((int)$f['id'] === $efId) {
            $editFormData = $f;
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
    <title>Formulaires de contact — My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .drag-handle { cursor: grab; color: #aaa; }
        .drag-handle:active { cursor: grabbing; }
        tr.dragging { opacity: .4; background: #f0f4ff; }
        .shortcode-badge { font-family: monospace; font-size: .85rem; background:#f1f3f5; padding:4px 8px; border-radius:4px; user-select: all; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/menu.php'; ?>
<div class="main-content">
    <!-- Alerts -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show m-3">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show m-3">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="container-fluid p-4">

<?php if ($currentForm): ?>
    <!-- ══════════════════════════════════════════════════════
         DETAIL DU FORMULAIRE — champs + soumissions
    ══════════════════════════════════════════════════════ -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <a href="formulaires-contact.php" class="text-muted small mb-1 d-block">
                <i class="bi bi-arrow-left me-1"></i>Retour aux formulaires
            </a>
            <h2 class="mb-0">
                <i class="bi bi-envelope-at me-2 text-primary"></i>
                <?php echo htmlspecialchars($currentForm['nom']); ?>
            </h2>
            <div class="mt-1 text-muted small">
                Shortcode :
                <span class="shortcode-badge" title="Copiez ce shortcode dans le contenu d'une page">
                    <?php echo htmlspecialchars($currentForm['shortcode']); ?>
                </span>
                <button class="btn btn-sm btn-outline-secondary ms-1 py-0 px-1" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($currentForm['shortcode']); ?>')"><i class="bi bi-clipboard"></i></button>
            </div>
        </div>
        <a href="formulaires-contact.php?edit=<?php echo $currentForm['id']; ?>" class="btn btn-outline-secondary">
            <i class="bi bi-pencil me-1"></i>Modifier le formulaire
        </a>
    </div>

    <div class="row g-4">
        <!-- Champs -->
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-input-cursor-text me-2 text-primary"></i>
                    Champs (<?php echo count($formFields); ?>)
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_field">
                        <input type="hidden" name="form_id" value="<?php echo $currentForm['id']; ?>">
                        <?php if ($editField): ?>
                            <input type="hidden" name="field_id" value="<?php echo $editField['id']; ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Libellé <span class="text-danger">*</span></label>
                            <input type="text" name="label" class="form-control" required
                                   id="labelInput"
                                   value="<?php echo htmlspecialchars($editField['label'] ?? ''); ?>"
                                   placeholder="Ex: Votre message">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Identifiant interne <span class="text-danger">*</span></label>
                            <input type="text" name="nom_champ" class="form-control" required
                                   id="nomChampInput"
                                   value="<?php echo htmlspecialchars($editField['nom_champ'] ?? ''); ?>"
                                   placeholder="Ex: message" pattern="[a-z0-9_]+">
                            <div class="form-text">Lettres minuscules, chiffres et underscores uniquement.</div>
                        </div>
                        <div class="row mb-3">
                            <div class="col">
                                <label class="form-label fw-semibold">Type</label>
                                <select name="type_champ" class="form-select" id="typeChampSelect">
                                    <?php foreach (['text'=>'Texte','email'=>'Email','tel'=>'Téléphone','textarea'=>'Zone de texte','select'=>'Liste déroulante','checkbox'=>'Case à cocher'] as $v => $l): ?>
                                        <option value="<?php echo $v; ?>" <?php echo (!$editField && $v === 'text') || (!empty($editField['type_champ']) && $editField['type_champ'] === $v) ? 'selected' : ''; ?>>
                                            <?php echo $l; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col">
                                <label class="form-label fw-semibold">Ordre</label>
                                <input type="number" name="ordre" class="form-control"
                                       value="<?php echo (int)($editField['ordre'] ?? (count($formFields) + 1) * 10); ?>" min="0" step="10">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Placeholder</label>
                            <input type="text" name="placeholder" class="form-control"
                                   value="<?php echo htmlspecialchars($editField['placeholder'] ?? ''); ?>"
                                   placeholder="Ex: Entrez votre message…">
                        </div>
                        <div class="mb-3" id="optionsWrapper" style="<?php echo (!$editField || $editField['type_champ'] !== 'select') ? 'display:none' : ''; ?>">
                            <label class="form-label fw-semibold">Options <small class="text-muted">(séparées par |)</small></label>
                            <input type="text" name="options" class="form-control"
                                   value="<?php echo htmlspecialchars($editField['options'] ?? ''); ?>"
                                   placeholder="Ex: Option A|Option B|Option C">
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="requis" id="requisSwitch"
                                   <?php echo (!empty($editField['requis'])) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="requisSwitch">Champ obligatoire</label>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-grow-1">
                                <i class="bi bi-<?php echo $editField ? 'save' : 'plus-lg'; ?> me-1"></i>
                                <?php echo $editField ? 'Enregistrer' : 'Ajouter le champ'; ?>
                            </button>
                            <?php if ($editField): ?>
                            <a href="formulaires-contact.php?form=<?php echo $currentForm['id']; ?>" class="btn btn-outline-secondary">Annuler</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Liste des champs -->
        <div class="col-lg-7">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <span class="fw-semibold"><i class="bi bi-list-check me-2 text-primary"></i>Champs du formulaire</span>
                    <small class="text-muted"><i class="bi bi-arrows-move me-1"></i>Glisser pour réordonner</small>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($formFields)): ?>
                        <p class="text-muted text-center py-4">Aucun champ encore. Ajoutez-en via le formulaire ci-contre.</p>
                    <?php else: ?>
                    <table class="table table-hover align-middle mb-0" id="fieldsTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:30px;"></th>
                                <th>Libellé</th>
                                <th>Type</th>
                                <th class="text-center" style="width:70px;">Requis</th>
                                <th style="width:100px;"></th>
                            </tr>
                        </thead>
                        <tbody id="sortableFields">
                        <?php foreach ($formFields as $fld): ?>
                        <tr data-id="<?php echo $fld['id']; ?>">
                            <td><span class="drag-handle"><i class="bi bi-grip-vertical fs-5"></i></span></td>
                            <td>
                                <strong><?php echo htmlspecialchars($fld['label']); ?></strong>
                                <div class="text-muted small"><code><?php echo htmlspecialchars($fld['nom_champ']); ?></code></div>
                            </td>
                            <td><span class="badge bg-secondary-subtle text-secondary"><?php echo htmlspecialchars($fld['type_champ']); ?></span></td>
                            <td class="text-center">
                                <?php echo $fld['requis'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<span class="text-muted">—</span>'; ?>
                            </td>
                            <td class="text-end">
                                <a href="formulaires-contact.php?form=<?php echo $currentForm['id']; ?>&edit_field=<?php echo $fld['id']; ?>"
                                   class="btn btn-sm btn-outline-primary me-1" title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer ce champ ?');">
                                    <input type="hidden" name="action" value="delete_field">
                                    <input type="hidden" name="field_id" value="<?php echo $fld['id']; ?>">
                                    <input type="hidden" name="form_id" value="<?php echo $currentForm['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Soumissions récentes -->
            <?php if (!empty($formSubmissions)): ?>
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-inbox me-2 text-primary"></i>Soumissions récentes (<?php echo count($formSubmissions); ?>)
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Données</th>
                                    <th class="text-center" style="width:60px;">Lu</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($formSubmissions as $sub): ?>
                            <tr>
                                <td class="text-muted small text-nowrap"><?php echo date('d/m/Y H:i', strtotime($sub['created_at'])); ?></td>
                                <td class="small">
                                    <?php
                                    $data = json_decode($sub['donnees'], true) ?? [];
                                    $parts = [];
                                    foreach ($data as $k => $v) {
                                        $parts[] = '<strong>' . htmlspecialchars($k) . '</strong>: ' . htmlspecialchars(is_array($v) ? implode(', ', $v) : $v);
                                    }
                                    echo implode(' · ', $parts);
                                    ?>
                                </td>
                                <td class="text-center">
                                    <?php echo $sub['lu'] ? '<i class="bi bi-check2 text-success"></i>' : '<span class="badge bg-primary-subtle text-primary">Nouveau</span>'; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($editFormData || isset($_GET['new'])): ?>
    <!-- ══════════════════════════════════════════════════════
         FORMULAIRE CRÉATION / MODIFICATION
    ══════════════════════════════════════════════════════ -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <a href="formulaires-contact.php" class="text-muted small mb-1 d-block">
                <i class="bi bi-arrow-left me-1"></i>Retour
            </a>
            <h2 class="mb-0">
                <i class="bi bi-envelope-at me-2 text-primary"></i>
                <?php echo $editFormData ? 'Modifier : ' . htmlspecialchars($editFormData['nom']) : 'Nouveau formulaire'; ?>
            </h2>
        </div>
    </div>
    <div class="card shadow-sm" style="max-width:600px;">
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="save_form">
                <?php if ($editFormData): ?>
                    <input type="hidden" name="id" value="<?php echo $editFormData['id']; ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Nom du formulaire <span class="text-danger">*</span></label>
                    <input type="text" name="nom" class="form-control" required
                           value="<?php echo htmlspecialchars($editFormData['nom'] ?? ''); ?>"
                           placeholder="Ex: Formulaire de contact principal">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Email de destination</label>
                    <input type="email" name="email_dest" class="form-control"
                           value="<?php echo htmlspecialchars($editFormData['email_dest'] ?? ''); ?>"
                           placeholder="contact@example.com">
                    <div class="form-text">Les soumissions seront envoyées à cette adresse.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Message de confirmation</label>
                    <textarea name="message_confirmation" class="form-control" rows="3"
                              placeholder="Votre message a été envoyé. Nous vous répondrons bientôt."><?php echo htmlspecialchars($editFormData['message_confirmation'] ?? ''); ?></textarea>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="actif" id="actifSwitch"
                           <?php echo (!$editFormData || $editFormData['actif']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="actifSwitch">Formulaire actif</label>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Enregistrer
                    </button>
                    <a href="formulaires-contact.php" class="btn btn-outline-secondary">Annuler</a>
                </div>
            </form>
        </div>
    </div>

<?php else: ?>
    <!-- ══════════════════════════════════════════════════════
         LISTE DES FORMULAIRES
    ══════════════════════════════════════════════════════ -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 class="mb-0"><i class="bi bi-envelope-at me-2 text-primary"></i>Formulaires de contact</h2>
            <p class="text-muted mb-0 mt-1">Créez des formulaires dynamiques et insérez-les dans vos pages via shortcode.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="pages-frontoffice.php" class="btn btn-outline-secondary">
                <i class="bi bi-file-richtext me-1"></i>Pages
            </a>
            <a href="formulaires-contact.php?new=1" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Nouveau formulaire
            </a>
        </div>
    </div>

    <?php if (!empty($tableError)): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        Les tables des formulaires de contact n'existent pas encore.
        Veuillez <a href="../run-migrations.php">exécuter les migrations</a> pour les initialiser.
    </div>
    <?php elseif (empty($forms)): ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-envelope-at" style="font-size:3rem;"></i>
            <h5 class="mt-3">Aucun formulaire créé</h5>
            <p>Créez votre premier formulaire et insérez-le dans une page via son shortcode.</p>
            <a href="formulaires-contact.php?new=1" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Créer un formulaire
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nom</th>
                        <th>Shortcode</th>
                        <th class="text-center" style="width:70px;">Champs</th>
                        <th class="text-center" style="width:80px;">Actif</th>
                        <th style="width:140px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($forms as $form): ?>
                <?php
                    try {
                        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM contact_form_fields WHERE form_id = ?");
                        $stmtCount->execute([$form['id']]);
                        $fieldCount = $stmtCount->fetchColumn();
                    } catch (Exception $e) { $fieldCount = '?'; }
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($form['nom']); ?></strong></td>
                    <td>
                        <span class="shortcode-badge"><?php echo htmlspecialchars($form['shortcode']); ?></span>
                        <button class="btn btn-sm btn-outline-secondary ms-1 py-0 px-1 border-0"
                                onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($form['shortcode']); ?>')"
                                title="Copier le shortcode">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </td>
                    <td class="text-center text-muted"><?php echo $fieldCount; ?></td>
                    <td class="text-center">
                        <?php echo $form['actif']
                            ? '<span class="badge bg-success-subtle text-success">Oui</span>'
                            : '<span class="badge bg-secondary-subtle text-secondary">Non</span>'; ?>
                    </td>
                    <td class="text-end">
                        <a href="formulaires-contact.php?form=<?php echo $form['id']; ?>"
                           class="btn btn-sm btn-outline-secondary me-1" title="Gérer les champs">
                            <i class="bi bi-list-check"></i>
                        </a>
                        <a href="formulaires-contact.php?edit=<?php echo $form['id']; ?>"
                           class="btn btn-sm btn-outline-primary me-1" title="Modifier">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer ce formulaire et tous ses champs ?');">
                            <input type="hidden" name="action" value="delete_form">
                            <input type="hidden" name="id" value="<?php echo $form['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>

    </div><!-- /container-fluid -->
</div><!-- /main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-fill nom_champ from label
(function () {
    var labelInput    = document.getElementById('labelInput');
    var nomChampInput = document.getElementById('nomChampInput');
    if (!labelInput || !nomChampInput) return;
    if (nomChampInput.value !== '') return;
    labelInput.addEventListener('input', function () {
        if (nomChampInput.dataset.manualEdit) return;
        nomChampInput.value = this.value
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s]/g, '')
            .trim()
            .replace(/\s+/g, '_')
            .replace(/_+/g, '_');
    });
    nomChampInput.addEventListener('input', function () {
        this.dataset.manualEdit = '1';
    });
}());

// Show/hide options for select type
(function () {
    var sel = document.getElementById('typeChampSelect');
    var wrap = document.getElementById('optionsWrapper');
    if (!sel || !wrap) return;
    sel.addEventListener('change', function () {
        wrap.style.display = this.value === 'select' ? '' : 'none';
    });
}());

// Drag-and-drop reorder for fields
(function () {
    var tbody = document.getElementById('sortableFields');
    if (!tbody) return;
    var dragging = null;
    Array.from(tbody.querySelectorAll('tr')).forEach(function(tr) {
        tr.setAttribute('draggable', 'true');
    });
    tbody.addEventListener('dragstart', function(e) {
        dragging = e.target.closest('tr');
        if (dragging) dragging.classList.add('dragging');
    });
    tbody.addEventListener('dragend', function() {
        if (dragging) { dragging.classList.remove('dragging'); dragging = null; }
        saveFieldOrder();
    });
    tbody.addEventListener('dragover', function(e) {
        e.preventDefault();
        var target = e.target.closest('tr');
        if (target && dragging && target !== dragging) {
            var rect = target.getBoundingClientRect();
            tbody.insertBefore(dragging, e.clientY < rect.top + rect.height / 2 ? target : target.nextSibling);
        }
    });
    function saveFieldOrder() {
        var rows = Array.from(tbody.querySelectorAll('tr[data-id]'));
        var data = rows.map(function(tr, i) { return { id: parseInt(tr.dataset.id, 10), ordre: (i + 1) * 10 }; });
        fetch('formulaires-contact.php?reorder=1', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        }).catch(console.error);
    }
}());
</script>
</body>
</html>
