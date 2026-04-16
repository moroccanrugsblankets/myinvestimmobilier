<?php
/**
 * Demandes & Documents — Interface admin
 * My Invest Immobilier
 *
 * Liste des demandes soumises par les locataires depuis le portail.
 * Chaque demande peut recevoir une réponse par email via SMTP.
 * Le Reply-To est automatiquement défini sur l'adresse du locataire.
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/mail-templates.php';

$successMsg = '';
$errorMsg   = '';

// ── Traitement : répondre à une demande ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'repondre') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Token CSRF invalide. Veuillez recharger la page.';
    } else {
        $demandeId   = (int)($_POST['demande_id'] ?? 0);
        $reponseObjet = trim($_POST['reponse_objet'] ?? '');
        $reponseMsg   = trim($_POST['reponse_message'] ?? '');

        if ($demandeId <= 0 || empty($reponseObjet) || empty($reponseMsg)) {
            $errorMsg = 'Veuillez renseigner l\'objet et le message de la réponse.';
        } else {
            // Charger la demande
            $stmtD = $pdo->prepare("
                SELECT d.*, CONCAT(loc.prenom, ' ', loc.nom) as locataire_nom,
                       loc.prenom as locataire_prenom
                FROM demandes_documents d
                LEFT JOIN locataires loc ON d.locataire_id = loc.id
                WHERE d.id = ? LIMIT 1
            ");
            $stmtD->execute([$demandeId]);
            $demande = $stmtD->fetch(PDO::FETCH_ASSOC);

            if (!$demande) {
                $errorMsg = 'Demande introuvable.';
            } else {
                $companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';

                // Corps HTML de la réponse
                $reponseHtml = '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:0;">
<div style="max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
  <div style="background:linear-gradient(135deg,#2c3e50,#3498db);padding:30px 35px;">
    <h2 style="color:#fff;margin:0;font-size:20px;">📄 Réponse à votre demande</h2>
    <p style="color:rgba(255,255,255,0.8);margin:6px 0 0;font-size:13px;">' . htmlspecialchars($companyName) . '</p>
  </div>
  <div style="padding:30px 35px;">
    <p style="font-size:15px;">Bonjour ' . htmlspecialchars($demande['locataire_prenom'] ?? $demande['locataire_nom'] ?? '') . ',</p>
    <div style="background:#f0f4f8;border-left:4px solid #3498db;padding:12px 15px;margin-bottom:20px;border-radius:0 5px 5px 0;">
      <p style="margin:0;font-size:12px;color:#666;">En réponse à votre demande :</p>
      <p style="margin:4px 0 0;font-size:13px;font-weight:bold;">' . htmlspecialchars($demande['objet']) . '</p>
      <p style="margin:2px 0 0;font-size:12px;color:#888;font-family:monospace;">' . htmlspecialchars($demande['reference']) . '</p>
    </div>
    <div style="font-size:14px;color:#333;line-height:1.7;white-space:pre-wrap;">' . htmlspecialchars($reponseMsg) . '</div>
    <p style="color:#999;font-size:12px;margin:25px 0 0;border-top:1px solid #eee;padding-top:15px;">
      ' . htmlspecialchars($companyName) . ' — Pour toute question, vous pouvez répondre directement à cet email.
    </p>
  </div>
</div>
</body></html>';

                // Envoi via SMTP — Reply-To = email du locataire
                $adminName  = $_SESSION['admin_nom'] ?? 'Administrateur';
                $sent = sendEmail(
                    $demande['email_locataire'],
                    $reponseObjet,
                    $reponseHtml,
                    null,
                    true,
                    false,
                    $config['MAIL_FROM'] ?? '',
                    $config['MAIL_FROM_NAME'] ?? $companyName,
                    false,
                    ['contexte' => "demande_document_reponse;dem_id=$demandeId"]
                );

                if ($sent) {
                    // Marquer comme traité
                    $pdo->prepare("UPDATE demandes_documents SET statut = 'traite', updated_at = NOW() WHERE id = ?")
                        ->execute([$demandeId]);
                    $successMsg = "Réponse envoyée avec succès à {$demande['email_locataire']}.";
                } else {
                    $errorMsg = "Erreur lors de l'envoi de l'email. Vérifiez la configuration SMTP.";
                }
            }
        }
    }
}

// ── Détail d'une demande spécifique ──────────────────────────────────────────
$detailDemande = null;
if (isset($_GET['id'])) {
    $detailId = (int)$_GET['id'];
    if ($detailId > 0) {
        $stmtDetail = $pdo->prepare("
            SELECT d.*,
                   CONCAT(loc.prenom, ' ', loc.nom) as locataire_nom,
                   loc.prenom as locataire_prenom,
                   l.adresse, l.reference as logement_ref,
                   c.reference_unique as contrat_ref
            FROM demandes_documents d
            LEFT JOIN locataires loc ON d.locataire_id = loc.id
            LEFT JOIN logements l   ON d.logement_id = l.id
            LEFT JOIN contrats c    ON d.contrat_id = c.id
            WHERE d.id = ? LIMIT 1
        ");
        $stmtDetail->execute([$detailId]);
        $detailDemande = $stmtDetail->fetch(PDO::FETCH_ASSOC);
    }
}

// ── Filtres & liste ───────────────────────────────────────────────────────────
$statutFilter = $_GET['statut'] ?? '';
$search       = trim($_GET['search'] ?? '');

$where  = ['1=1'];
$params = [];

if ($statutFilter && in_array($statutFilter, ['nouveau', 'traite'])) {
    $where[]  = 'd.statut = ?';
    $params[] = $statutFilter;
}
if ($search) {
    $where[]  = '(d.objet LIKE ? OR d.reference LIKE ? OR d.email_locataire LIKE ? OR CONCAT(loc.prenom, \' \', loc.nom) LIKE ? OR l.adresse LIKE ?)';
    $s = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $search) . '%';
    for ($i = 0; $i < 5; $i++) $params[] = $s;
}

$whereClause = implode(' AND ', $where);

try {
    $stmt = $pdo->prepare("
        SELECT d.id, d.reference, d.objet, d.statut, d.created_at,
               d.email_locataire, d.fichier_path, d.fichier_nom,
               CONCAT(loc.prenom, ' ', loc.nom) as locataire_nom,
               l.adresse, l.reference as logement_ref
        FROM demandes_documents d
        LEFT JOIN locataires loc ON d.locataire_id = loc.id
        LEFT JOIN logements l   ON d.logement_id = l.id
        WHERE $whereClause
        ORDER BY
            CASE d.statut WHEN 'nouveau' THEN 0 ELSE 1 END,
            d.created_at DESC
    ");
    $stmt->execute($params);
    $demandes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table absente si migration non appliquée
    $demandes = [];
    $errorMsg = 'La table demandes_documents n\'existe pas encore. Veuillez appliquer la migration 139.';
}

// Statistiques
$stats = ['total' => 0, 'nouveau' => 0, 'traite' => 0];
try {
    $statsStmt = $pdo->query("SELECT statut, COUNT(*) as nb FROM demandes_documents GROUP BY statut");
    foreach ($statsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stats['total'] += $row['nb'];
        $stats[$row['statut']] = (int)$row['nb'];
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demandes &amp; Documents — Admin My Invest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .stat-card { background: #fff; border-radius: 10px; padding: 18px 22px; box-shadow: 0 2px 6px rgba(0,0,0,0.07); }
        .stat-card .number { font-size: 2rem; font-weight: 700; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="container-fluid mt-4">

        <!-- En-tête -->
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h1><i class="bi bi-file-earmark-text me-2"></i>Demandes &amp; Documents</h1>
                <p class="text-muted mb-0">Demandes soumises par les locataires via le portail</p>
            </div>
        </div>

        <?php if ($successMsg): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($successMsg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-circle me-2"></i><?php echo htmlspecialchars($errorMsg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="number text-primary"><?php echo $stats['total']; ?></div>
                    <div class="text-muted small">Total</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="number text-warning"><?php echo $stats['nouveau']; ?></div>
                    <div class="text-muted small">Nouveaux</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="number text-success"><?php echo $stats['traite']; ?></div>
                    <div class="text-muted small">Traités</div>
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control form-control-sm"
                               placeholder="Rechercher (objet, email, logement…)"
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="statut" class="form-select form-select-sm">
                            <option value="">Tous les statuts</option>
                            <option value="nouveau" <?php echo $statutFilter === 'nouveau' ? 'selected' : ''; ?>>Nouveau</option>
                            <option value="traite"  <?php echo $statutFilter === 'traite'  ? 'selected' : ''; ?>>Traité</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-primary w-100">
                            <i class="bi bi-search me-1"></i>Filtrer
                        </button>
                    </div>
                    <?php if ($search || $statutFilter): ?>
                    <div class="col-md-2">
                        <a href="demandes-documents.php" class="btn btn-sm btn-outline-secondary w-100">Réinitialiser</a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Tableau -->
        <div class="card">
            <div class="card-body p-0">
                <?php if (empty($demandes)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-inbox" style="font-size:3rem;"></i>
                        <p class="mt-3">Aucune demande trouvée<?php echo ($search || $statutFilter) ? ' pour ces critères' : ''; ?>.</p>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Référence</th>
                                <th>Locataire</th>
                                <th>Logement</th>
                                <th>Objet</th>
                                <th>Pièce jointe</th>
                                <th>Date</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($demandes as $d): ?>
                            <tr>
                                <td class="font-monospace small"><?php echo htmlspecialchars($d['reference']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($d['locataire_nom'] ?? '—'); ?><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($d['email_locataire']); ?></small>
                                </td>
                                <td class="small"><?php echo htmlspecialchars($d['adresse'] ?? '—'); ?></td>
                                <td style="max-width:220px;">
                                    <span title="<?php echo htmlspecialchars($d['objet']); ?>">
                                        <?php echo htmlspecialchars(mb_strlen($d['objet']) > 60 ? mb_substr($d['objet'], 0, 60) . '…' : $d['objet']); ?>
                                    </span>
                                </td>
                                <td class="small">
                                    <?php if (!empty($d['fichier_path'])): ?>
                                        <a href="/<?php echo htmlspecialchars($d['fichier_path']); ?>" target="_blank" class="text-decoration-none">
                                            <i class="bi bi-paperclip me-1"></i><?php echo htmlspecialchars(mb_strlen($d['fichier_nom'] ?? '') > 25 ? mb_substr($d['fichier_nom'], 0, 25) . '…' : ($d['fichier_nom'] ?? 'Fichier')); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?php echo date('d/m/Y H:i', strtotime($d['created_at'])); ?></td>
                                <td>
                                    <?php if ($d['statut'] === 'nouveau'): ?>
                                        <span class="badge bg-warning text-dark">Nouveau</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Traité</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary"
                                            data-bs-toggle="modal" data-bs-target="#modalRepondre"
                                            data-id="<?php echo $d['id']; ?>"
                                            data-reference="<?php echo htmlspecialchars($d['reference']); ?>"
                                            data-objet="<?php echo htmlspecialchars($d['objet']); ?>"
                                            data-email="<?php echo htmlspecialchars($d['email_locataire']); ?>"
                                            data-locataire="<?php echo htmlspecialchars($d['locataire_nom'] ?? ''); ?>"
                                            data-message="<?php echo htmlspecialchars($d['message'] ?? ''); ?>"
                                            data-fichier="<?php echo htmlspecialchars($d['fichier_path'] ?? ''); ?>"
                                            data-fichier-nom="<?php echo htmlspecialchars($d['fichier_nom'] ?? ''); ?>">
                                        <i class="bi bi-reply me-1"></i>Répondre
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        </div><!-- /container-fluid -->
    </div><!-- /main-content -->

<!-- ═══════════════════════════════════════════════════════════════════
     Modal : Répondre à une demande
═══════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalRepondre" tabindex="-1" aria-labelledby="modalRepondreLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="formRepondre">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                <input type="hidden" name="action" value="repondre">
                <input type="hidden" name="demande_id" id="repondreDemandeId">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalRepondreLabel">
                        <i class="bi bi-reply me-2"></i>Répondre à la demande
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Résumé de la demande -->
                    <div class="alert alert-light border mb-3 p-3">
                        <div class="row g-2 small">
                            <div class="col-sm-3 fw-semibold text-muted">Référence</div>
                            <div class="col-sm-9 font-monospace" id="resumeReference"></div>
                            <div class="col-sm-3 fw-semibold text-muted">Locataire</div>
                            <div class="col-sm-9" id="resumeLocataire"></div>
                            <div class="col-sm-3 fw-semibold text-muted">Destinataire</div>
                            <div class="col-sm-9"><span id="resumeEmail" class="text-primary"></span></div>
                            <div class="col-sm-3 fw-semibold text-muted">Objet demande</div>
                            <div class="col-sm-9" id="resumeObjet"></div>
                        </div>
                        <div id="resumeMessageBlock" class="mt-2 small d-none">
                            <div class="fw-semibold text-muted mb-1">Message du locataire :</div>
                            <div id="resumeMessage" class="bg-white border rounded p-2" style="white-space:pre-wrap;max-height:100px;overflow-y:auto;font-size:12px;"></div>
                        </div>
                        <div id="resumeFichierBlock" class="mt-2 small d-none">
                            <i class="bi bi-paperclip me-1"></i>Pièce jointe : <a id="resumeFichierLien" href="#" target="_blank"></a>
                        </div>
                    </div>

                    <div class="alert alert-info small py-2 mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        L'email sera envoyé depuis <strong><?php echo htmlspecialchars($config['MAIL_FROM'] ?? 'contact@domaine.com'); ?></strong>
                        vers l'adresse du locataire. Si le locataire répond à cet email, sa réponse parviendra
                        à l'adresse générique configurée.
                        <br><small class="text-muted">Note : les notifications automatiques envoyées aux admins lors d'une nouvelle demande ont automatiquement un Reply-To défini sur l'adresse du locataire.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="reponseObjet">
                            Objet de la réponse <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="reponseObjet" name="reponse_objet" required
                               placeholder="Re : …">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="reponseMessage">
                            Message <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="reponseMessage" name="reponse_message"
                                  rows="8" required
                                  placeholder="Saisissez votre réponse ici…"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Envoyer la réponse
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const modal = document.getElementById('modalRepondre');
    if (!modal) return;

    modal.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;

        const id         = btn.dataset.id;
        const reference  = btn.dataset.reference;
        const objet      = btn.dataset.objet;
        const email      = btn.dataset.email;
        const locataire  = btn.dataset.locataire;
        const message    = btn.dataset.message;
        const fichier    = btn.dataset.fichier;
        const fichierNom = btn.dataset.fichierNom;

        document.getElementById('repondreDemandeId').value = id;
        document.getElementById('resumeReference').textContent  = reference;
        document.getElementById('resumeLocataire').textContent  = locataire;
        document.getElementById('resumeEmail').textContent      = email;
        document.getElementById('resumeObjet').textContent      = objet;

        // Pré-remplir l'objet de la réponse
        document.getElementById('reponseObjet').value = 'Réponse : ' + objet;

        // Message locataire
        const msgBlock = document.getElementById('resumeMessageBlock');
        if (message && message.trim()) {
            document.getElementById('resumeMessage').textContent = message;
            msgBlock.classList.remove('d-none');
        } else {
            msgBlock.classList.add('d-none');
        }

        // Fichier joint
        const fichierBlock = document.getElementById('resumeFichierBlock');
        if (fichier) {
            const lien = document.getElementById('resumeFichierLien');
            lien.href = '/' + fichier;
            lien.textContent = fichierNom || 'Voir le fichier';
            fichierBlock.classList.remove('d-none');
        } else {
            fichierBlock.classList.add('d-none');
        }
    });

    // Réinitialiser le champ message à chaque ouverture
    modal.addEventListener('hidden.bs.modal', function () {
        document.getElementById('reponseMessage').value = '';
    });
})();
</script>
</body>
</html>
