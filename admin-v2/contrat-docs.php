<?php
/**
 * Documents d'un contrat — page dédiée
 * My Invest Immobilier
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

$contratId = isset($_GET['contrat_id']) ? (int)$_GET['contrat_id'] : 0;

if ($contratId <= 0) {
    header('Location: contrats.php');
    exit;
}

// Vérifier que le contrat existe
$stmt = $pdo->prepare("SELECT id, reference_unique, statut FROM contrats WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$stmt->execute([$contratId]);
$contrat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contrat) {
    $_SESSION['error'] = "Contrat introuvable.";
    header('Location: contrats.php');
    exit;
}

$siteUrl = rtrim($config['SITE_URL'] ?? '', '/');

// ── Construire les sections (même logique que get-contrat-docs.php) ────────────
$sections = [
    'contrat'    => ['rubrique' => 'Contrat (bail)',           'key' => 'contrat',    'pdfs' => [], 'photos' => []],
    'edl_entree' => ['rubrique' => 'État des lieux (Entrée)',  'key' => 'edl_entree', 'pdfs' => [], 'photos' => []],
    'edl_sortie' => ['rubrique' => 'État des lieux (Sortie)',  'key' => 'edl_sortie', 'pdfs' => [], 'photos' => []],
    'inv_entree' => ['rubrique' => 'Inventaire (Entrée)',      'key' => 'inv_entree', 'pdfs' => [], 'photos' => []],
    'inv_sortie' => ['rubrique' => 'Inventaire (Sortie)',      'key' => 'inv_sortie', 'pdfs' => [], 'photos' => []],
    'bilan'      => ['rubrique' => 'Bilan de logement',        'key' => 'bilan',      'pdfs' => [], 'photos' => []],
    'quittances' => ['rubrique' => 'Quittances',               'key' => 'quittances', 'pdfs' => [], 'photos' => []],
    'signalement'=> ['rubrique' => 'Signalement',              'key' => 'signalement','pdfs' => [], 'photos' => []],
    'demandes'   => ['rubrique' => 'Demandes & Documents',     'key' => 'demandes',   'pdfs' => [], 'photos' => []],
];

// 1. Contrat (bail) PDF
if (in_array($contrat['statut'], ['signe', 'valide', 'actif', 'expire', 'termine'])) {
    $sections['contrat']['pdfs'][] = [
        'label' => 'Contrat (bail)',
        'url'   => $siteUrl . '/pdf/download.php?contrat_id=' . $contratId . '&view=1',
    ];
}

// 2. États des lieux
$stmtEdl = $pdo->prepare("SELECT id, type FROM etats_lieux WHERE contrat_id = ? ORDER BY type, created_at");
$stmtEdl->execute([$contratId]);

foreach ($stmtEdl->fetchAll(PDO::FETCH_ASSOC) as $edl) {
    $sKey      = $edl['type'] === 'entree' ? 'edl_entree' : 'edl_sortie';
    $typeLabel = $edl['type'] === 'entree' ? 'Entrée' : 'Sortie';

    $sections[$sKey]['pdfs'][] = [
        'label' => 'État des lieux (' . $typeLabel . ')',
        'url'   => $siteUrl . '/admin-v2/download-etat-lieux.php?id=' . $edl['id'],
    ];

    $stmtPhotos = $pdo->prepare("
        SELECT chemin_fichier, categorie
        FROM etat_lieux_photos
        WHERE etat_lieux_id = ? AND deleted_at IS NULL
        ORDER BY categorie, ordre ASC
    ");
    $stmtPhotos->execute([$edl['id']]);
    foreach ($stmtPhotos->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $sections[$sKey]['photos'][] = [
            'url'   => $siteUrl . '/' . ltrim($p['chemin_fichier'], '/'),
            'label' => $p['categorie'] ?? '',
        ];
    }
}

// 3. Inventaires
$stmtInv = $pdo->prepare("SELECT id, type FROM inventaires WHERE contrat_id = ? ORDER BY type, created_at");
$stmtInv->execute([$contratId]);

foreach ($stmtInv->fetchAll(PDO::FETCH_ASSOC) as $inv) {
    $sKey      = $inv['type'] === 'entree' ? 'inv_entree' : 'inv_sortie';
    $typeLabel = $inv['type'] === 'entree' ? 'Entrée' : 'Sortie';

    $sections[$sKey]['pdfs'][] = [
        'label' => 'Inventaire (' . $typeLabel . ')',
        'url'   => $siteUrl . '/admin-v2/download-inventaire.php?id=' . $inv['id'],
    ];

    $stmtIp = $pdo->prepare("
        SELECT fichier, categorie
        FROM inventaire_photos
        WHERE inventaire_id = ?
        ORDER BY categorie, ordre ASC
    ");
    $stmtIp->execute([$inv['id']]);
    foreach ($stmtIp->fetchAll(PDO::FETCH_ASSOC) as $ip) {
        $sections[$sKey]['photos'][] = [
            'url'   => $siteUrl . '/' . ltrim($ip['fichier'], '/'),
            'label' => $ip['categorie'] ?? '',
        ];
    }
}

// 4. Bilan de logement PDF
$stmtBilan = $pdo->prepare("SELECT id FROM etats_lieux WHERE contrat_id = ? AND type = 'sortie' LIMIT 1");
$stmtBilan->execute([$contratId]);
if ($stmtBilan->fetch()) {
    $sections['bilan']['pdfs'][] = [
        'label' => 'Bilan de logement',
        'url'   => $siteUrl . '/admin-v2/download-bilan-logement.php?contrat_id=' . $contratId,
    ];
}

// 5. Quittances PDF
$stmtQ = $pdo->prepare("
    SELECT mois, annee, fichier_pdf
    FROM quittances
    WHERE contrat_id = ? AND fichier_pdf IS NOT NULL
    ORDER BY annee, mois
");
$stmtQ->execute([$contratId]);
foreach ($stmtQ->fetchAll(PDO::FETCH_ASSOC) as $q) {
    $pdfBasename = basename($q['fichier_pdf']);
    $pdfFsPath   = dirname(__DIR__) . '/pdf/quittances/' . $pdfBasename;
    if (file_exists($pdfFsPath)) {
        $sections['quittances']['pdfs'][] = [
            'label' => 'Quittance ' . sprintf('%02d', $q['mois']) . '/' . $q['annee'],
            'url'   => $siteUrl . '/pdf/quittances/' . rawurlencode($pdfBasename),
        ];
    }
}

// 6. Signalements (photos)
$stmtSig = $pdo->prepare("SELECT id, reference, titre FROM signalements WHERE contrat_id = ? ORDER BY date_signalement");
$stmtSig->execute([$contratId]);

foreach ($stmtSig->fetchAll(PDO::FETCH_ASSOC) as $sig) {
    $stmtSp = $pdo->prepare("SELECT filename FROM signalements_photos WHERE signalement_id = ? ORDER BY uploaded_at");
    $stmtSp->execute([$sig['id']]);
    foreach ($stmtSp->fetchAll(PDO::FETCH_ASSOC) as $sp) {
        $sections['signalement']['photos'][] = [
            'url'   => $siteUrl . '/uploads/signalements/' . rawurlencode($sp['filename']),
            'label' => $sig['reference'] . ' — ' . $sig['titre'],
        ];
    }
}

// 7. Demandes & Documents (fichiers joints)
$stmtDem = $pdo->prepare("
    SELECT objet, fichier_path
    FROM demandes_documents
    WHERE contrat_id = ? AND fichier_path IS NOT NULL
    ORDER BY created_at
");
$stmtDem->execute([$contratId]);
foreach ($stmtDem->fetchAll(PDO::FETCH_ASSOC) as $dem) {
    $ficPath = ltrim($dem['fichier_path'], '/');
    $ficExt  = strtolower(pathinfo($ficPath, PATHINFO_EXTENSION));
    $item = [
        'label' => $dem['objet'],
        'url'   => $siteUrl . '/' . $ficPath,
    ];
    if (in_array($ficExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $sections['demandes']['photos'][] = $item;
    } else {
        $sections['demandes']['pdfs'][] = $item;
    }
}

// Ne conserver que les sections non vides
$sections = array_values(array_filter($sections, function ($s) {
    return !empty($s['pdfs']) || !empty($s['photos']);
}));

// Icône selon la clé de rubrique
function docsSectionIconClass(string $key): string {
    $icons = [
        'contrat'    => 'bi-file-earmark-text text-primary',
        'edl_entree' => 'bi-door-open text-success',
        'edl_sortie' => 'bi-door-closed text-warning',
        'inv_entree' => 'bi-clipboard-check text-success',
        'inv_sortie' => 'bi-clipboard-x text-warning',
        'bilan'      => 'bi-house-check text-info',
        'quittances' => 'bi-receipt text-dark',
        'signalement'=> 'bi-exclamation-triangle text-danger',
        'demandes'   => 'bi-folder2 text-secondary',
    ];
    return $icons[$key] ?? 'bi-folder2';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents — <?php echo htmlspecialchars($contrat['reference_unique']); ?> - Admin MyInvest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .docs-gallery-main {
            background: #000;
            border-radius: 8px;
            overflow: hidden;
            min-height: 200px;
            max-height: 420px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .docs-gallery-main img {
            max-width: 100%;
            max-height: 420px;
            object-fit: contain;
            display: block;
            cursor: zoom-in;
        }
        .docs-thumb {
            width: 70px;
            height: 70px;
            object-fit: cover;
            border-radius: 6px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: border-color .2s, opacity .2s;
            opacity: 0.6;
        }
        .docs-thumb.active {
            border-color: #0d6efd;
            opacity: 1;
        }
        /* Lightbox overlay */
        #docsLightbox {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 2000;
            background: rgba(0,0,0,.92);
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }
        #docsLightbox.show { display: flex; }
        #docsLightbox img {
            max-width: 94vw;
            max-height: 85vh;
            object-fit: contain;
            border-radius: 4px;
        }
        #docsLightboxClose {
            position: fixed;
            top: 16px;
            right: 20px;
            font-size: 2rem;
            color: #fff;
            cursor: pointer;
            background: none;
            border: none;
            line-height: 1;
        }
        #docsLightboxPrev,
        #docsLightboxNext {
            position: fixed;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255,255,255,.15);
            border: none;
            color: #fff;
            font-size: 1.8rem;
            padding: 8px 14px;
            border-radius: 4px;
            cursor: pointer;
        }
        #docsLightboxPrev { left: 12px; }
        #docsLightboxNext { right: 12px; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <!-- Lightbox plein écran -->
    <div id="docsLightbox" role="dialog" aria-modal="true" aria-label="Agrandissement photo">
        <button id="docsLightboxClose" aria-label="Fermer">&times;</button>
        <button id="docsLightboxPrev" aria-label="Photo précédente"><i class="bi bi-chevron-left"></i></button>
        <img id="docsLightboxImg" src="" alt="">
        <button id="docsLightboxNext" aria-label="Photo suivante"><i class="bi bi-chevron-right"></i></button>
        <p id="docsLightboxLabel" class="text-white small mt-3 mb-0"></p>
    </div>

    <div class="main-content">
        <!-- Entête -->
        <div class="header" style="background:white; padding:20px; margin-bottom:20px; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,.1);">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <a href="contrats.php" class="btn btn-outline-secondary btn-sm me-2">
                        <i class="bi bi-arrow-left"></i> Retour aux contrats
                    </a>
                    <span class="h5 mb-0">
                        <i class="bi bi-folder2-open me-2 text-primary"></i>
                        Documents — <strong><?php echo htmlspecialchars($contrat['reference_unique']); ?></strong>
                    </span>
                </div>
            </div>
        </div>

        <?php if (empty($sections)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox" style="font-size:48px;"></i>
                <p class="mt-3 fs-5">Aucun document trouvé pour ce contrat.</p>
                <a href="contrats.php" class="btn btn-primary mt-2">Retour aux contrats</a>
            </div>
        <?php else: ?>

        <?php foreach ($sections as $section): ?>
        <?php
            $key      = $section['key'];
            $hasPdfs  = !empty($section['pdfs']);
            $hasPhotos= !empty($section['photos']);
            $iconCls  = docsSectionIconClass($key);
            $jsonPhotos = htmlspecialchars(json_encode($section['photos'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
        ?>
            <div class="mb-4 p-4" style="background:white; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,.1);">
                <h6 class="fw-bold mb-3 d-flex align-items-center">
                    <i class="bi <?php echo $iconCls; ?> me-2"></i>
                    <?php echo htmlspecialchars($section['rubrique']); ?>
                </h6>

                <?php if ($hasPdfs): ?>
                <div class="list-group mb-3">
                    <?php foreach ($section['pdfs'] as $doc): ?>
                    <a href="<?php echo htmlspecialchars($doc['url']); ?>"
                       target="_blank" rel="noopener noreferrer"
                       class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                        <i class="bi bi-file-earmark-pdf text-danger fs-5"></i>
                        <span class="flex-grow-1"><?php echo htmlspecialchars($doc['label']); ?></span>
                        <i class="bi bi-box-arrow-up-right text-muted"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasPhotos): ?>
                <div class="docs-gallery"
                     data-key="<?php echo htmlspecialchars($key); ?>"
                     data-photos="<?php echo $jsonPhotos; ?>">

                    <div class="docs-gallery-main mb-2">
                        <button class="btn btn-light btn-sm position-absolute start-0 ms-2 docs-prev" style="z-index:10;" aria-label="Précédent">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <img class="docs-main-img" src="" alt="" title="Agrandir">
                        <button class="btn btn-light btn-sm position-absolute end-0 me-2 docs-next" style="z-index:10;" aria-label="Suivant">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>

                    <p class="text-center text-muted small mb-2 docs-label"></p>

                    <div class="d-flex flex-wrap gap-2 justify-content-center docs-thumbs">
                        <?php foreach ($section['photos'] as $i => $ph): ?>
                        <img src="<?php echo htmlspecialchars($ph['url']); ?>"
                             alt="<?php echo htmlspecialchars($ph['label']); ?>"
                             title="<?php echo htmlspecialchars($ph['label']); ?>"
                             class="docs-thumb<?php echo $i === 0 ? ' active' : ''; ?>"
                             data-idx="<?php echo $i; ?>">
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div><!-- /.main-content -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function () {
        // ── Galeries ──────────────────────────────────────────────────────────
        var galleries = {}; // key → { photos, idx, els }

        document.querySelectorAll('.docs-gallery').forEach(function (galEl) {
            var key    = galEl.dataset.key;
            var photos = JSON.parse(galEl.dataset.photos);
            var els = {
                mainImg : galEl.querySelector('.docs-main-img'),
                label   : galEl.querySelector('.docs-label'),
                prevBtn : galEl.querySelector('.docs-prev'),
                nextBtn : galEl.querySelector('.docs-next'),
                thumbs  : galEl.querySelectorAll('.docs-thumb'),
            };

            galleries[key] = { photos: photos, idx: 0, els: els };

            var count = photos.length;
            els.prevBtn.style.display = count > 1 ? '' : 'none';
            els.nextBtn.style.display = count > 1 ? '' : 'none';

            els.prevBtn.addEventListener('click', function () { showPhoto(key, galleries[key].idx - 1); });
            els.nextBtn.addEventListener('click', function () { showPhoto(key, galleries[key].idx + 1); });

            els.thumbs.forEach(function (th) {
                th.addEventListener('click', function () { showPhoto(key, parseInt(th.dataset.idx)); });
            });

            els.mainImg.addEventListener('click', function () { openLightbox(key); });

            showPhoto(key, 0);
        });

        function showPhoto(key, idx) {
            var g   = galleries[key];
            if (!g) return;
            var n   = g.photos.length;
            idx     = ((idx % n) + n) % n;
            g.idx   = idx;
            var ph  = g.photos[idx];
            g.els.mainImg.src = ph.url;
            g.els.mainImg.alt = ph.label;
            g.els.label.textContent = (ph.label ? ph.label + ' — ' : '') + (idx + 1) + ' / ' + n;
            g.els.thumbs.forEach(function (th) {
                var active = parseInt(th.dataset.idx) === idx;
                th.classList.toggle('active', active);
            });
            // Synchroniser le lightbox si ouvert sur ce groupe
            if (_lbKey === key) { updateLightboxDisplay(); }
        }

        // ── Lightbox ──────────────────────────────────────────────────────────
        var _lbKey  = null;
        var lbEl    = document.getElementById('docsLightbox');
        var lbImg   = document.getElementById('docsLightboxImg');
        var lbLabel = document.getElementById('docsLightboxLabel');

        function openLightbox(key) {
            _lbKey = key;
            updateLightboxDisplay();
            lbEl.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeLightbox() {
            lbEl.classList.remove('show');
            document.body.style.overflow = '';
            _lbKey = null;
        }

        function updateLightboxDisplay() {
            if (!_lbKey) return;
            var g   = galleries[_lbKey];
            var ph  = g.photos[g.idx];
            lbImg.src = ph.url;
            lbImg.alt = ph.label;
            lbLabel.textContent = (ph.label ? ph.label + ' — ' : '') + (g.idx + 1) + ' / ' + g.photos.length;
        }

        document.getElementById('docsLightboxClose').addEventListener('click', closeLightbox);
        document.getElementById('docsLightboxPrev').addEventListener('click', function () {
            if (_lbKey) showPhoto(_lbKey, galleries[_lbKey].idx - 1);
        });
        document.getElementById('docsLightboxNext').addEventListener('click', function () {
            if (_lbKey) showPhoto(_lbKey, galleries[_lbKey].idx + 1);
        });

        // Fermer avec Échap ou clic sur le fond
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeLightbox();
            if (e.key === 'ArrowLeft'  && _lbKey) showPhoto(_lbKey, galleries[_lbKey].idx - 1);
            if (e.key === 'ArrowRight' && _lbKey) showPhoto(_lbKey, galleries[_lbKey].idx + 1);
        });
        lbEl.addEventListener('click', function (e) {
            if (e.target === lbEl) closeLightbox();
        });
    })();
    </script>
</body>
</html>
