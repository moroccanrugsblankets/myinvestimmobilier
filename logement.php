<?php
/**
 * Page publique d'un logement — Front Office
 * My Invest Immobilier
 *
 * Affiche les informations d'un logement : description, équipements, photos, prix, adresse.
 * Intègre le lien de candidature.
 *
 * URL: /logement.php?ref=MD5_REFERENCE
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/header-frontoffice.php';

$ref = isset($_GET['ref']) ? trim($_GET['ref']) : '';

// Accept both plain reference (legacy) and MD5 hash (new format)
if (empty($ref) || strlen($ref) > 100) {
    http_response_code(404);
    die('Logement introuvable.');
}

// Récupérer le logement — lookup by MD5(reference) for the encrypted URL format
try {
    $stmt = $pdo->prepare("
        SELECT l.*
        FROM logements l
        WHERE MD5(l.reference) = ? AND l.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$ref]);
    $logement = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('logement.php DB error: ' . $e->getMessage());
    http_response_code(500);
    die('Erreur interne. Veuillez réessayer plus tard.');
}

if (!$logement) {
    http_response_code(404);
    die('Logement introuvable.');
}

// Récupérer les équipements inventoriés
try {
    $stmtEq = $pdo->prepare("
        SELECT e.nom, e.quantite, ic.nom AS categorie_nom
        FROM inventaire_equipements e
        LEFT JOIN inventaire_categories ic ON e.categorie_id = ic.id
        WHERE e.logement_id = ? AND e.deleted_at IS NULL
        ORDER BY ic.ordre ASC, e.ordre ASC, e.nom ASC
    ");
    $stmtEq->execute([$logement['id']]);
    $equipements = $stmtEq->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $equipements = [];
}

// Grouper par catégorie
$equipByCategory = [];
foreach ($equipements as $eq) {
    $cat = $eq['categorie_nom'] ?: 'Équipements';
    if (!isset($equipByCategory[$cat])) {
        $equipByCategory[$cat] = [];
    }
    $equipByCategory[$cat][] = $eq;
}

// Récupérer les photos/vidéos du logement
try {
    $stmtPhotos = $pdo->prepare("
        SELECT id, filename, original_name, mime_type
        FROM logements_photos
        WHERE logement_id = ?
        ORDER BY ordre ASC, id ASC
    ");
    $stmtPhotos->execute([$logement['id']]);
    $photos = $stmtPhotos->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $photos = [];
}

// Construire les liens
$siteUrl          = rtrim($config['SITE_URL'], '/');
$lienCandidature  = $siteUrl . '/candidature/?ref=' . md5($logement['reference']);
$companyName      = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';

// Labels de statut
$statutLabels = [
    'disponible'   => ['Disponible',          'success'],
    'reserve'      => ['Réservé',             'info'],
    'en_location'  => ['Déjà loué',           'secondary'],
    'maintenance'  => ['Indisponible',         'danger'],
    'indisponible' => ['Indisponible',         'secondary'],
];
$statutLabel = $statutLabels[$logement['statut']] ?? [$logement['statut'], 'secondary'];
$isDisponible = ($logement['statut'] === 'disponible');

$totalMensuel = (float)$logement['loyer'] + (float)$logement['charges'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($logement['reference']); ?> — <?php echo htmlspecialchars($logement['adresse']); ?> | <?php echo htmlspecialchars($companyName); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars(mb_substr(strip_tags($logement['description'] ?? ''), 0, 155)); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(rtrim($siteUrl, '/') . '/assets/css/frontoffice.css'); ?>">
    <style>
        /* Section cards — logement detail specific */
        .section-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,.06);
            padding: 1.75rem;
            margin-bottom: 1.25rem;
        }
        .section-title {
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #1243a3;
            margin-bottom: 1rem;
        }
        /* Equipment badges */
        .equip-badge {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            background: var(--fo-primary-light);
            color: var(--fo-primary);
            border-radius: 6px;
            padding: .35em .75em;
            font-size: .825rem;
            font-weight: 500;
            margin: .2rem;
        }
        /* CTA */
        .cta-card {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            border-radius: 14px;
            padding: 2rem;
            color: white;
            text-align: center;
        }
        .btn-cta {
            background: #fff;
            color: #047857;
            font-weight: 700;
            font-size: 1rem;
            padding: .85rem 2rem;
            border-radius: 10px;
            border: none;
            text-decoration: none;
            display: inline-block;
            transition: transform .15s, box-shadow .15s;
        }
        .btn-cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,.2);
            color: #047857;
        }
        /* Info grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: .75rem;
        }
        .info-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: .9rem;
            text-align: center;
        }
        .info-item .info-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--fo-primary);
        }
        .info-item .info-label {
            font-size: .75rem;
            color: #6b7280;
            margin-top: .2rem;
        }
        /* Photo slider */
        .photo-slider {
            position: relative;
            background: #111;
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 1.25rem;
        }
        .slider-main {
            position: relative;
            width: 100%;
            padding-top: 56.25%;
            overflow: hidden;
        }
        .slider-main img,
        .slider-main video {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            object-fit: cover;
        }
        .slider-main .slide-item {
            display: none;
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
        }
        .slider-main .slide-item.active { display: block; }
        .slider-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0,0,0,.5);
            color: #fff;
            border: none;
            width: 40px; height: 40px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            z-index: 10;
            transition: background .2s;
        }
        .slider-nav:hover { background: rgba(0,0,0,.8); }
        .slider-nav.prev { left: 12px; }
        .slider-nav.next { right: 12px; }
        .slider-counter {
            position: absolute;
            bottom: 12px; right: 16px;
            background: rgba(0,0,0,.55);
            color: #fff;
            font-size: .75rem;
            padding: 3px 10px;
            border-radius: 20px;
        }
        .slider-thumbs {
            display: flex;
            gap: 6px;
            padding: 8px;
            overflow-x: auto;
            background: #1a1a1a;
            scrollbar-width: thin;
            scrollbar-color: #555 #1a1a1a;
        }
        .slider-thumbs::-webkit-scrollbar { height: 4px; }
        .slider-thumbs::-webkit-scrollbar-thumb { background: #555; border-radius: 2px; }
        .thumb-item {
            flex-shrink: 0;
            width: 70px; height: 52px;
            border-radius: 4px;
            overflow: hidden;
            cursor: pointer;
            border: 2px solid transparent;
            transition: border-color .2s;
            position: relative;
        }
        .thumb-item.active { border-color: var(--fo-primary); }
        .thumb-item img { width: 100%; height: 100%; object-fit: cover; }
        .thumb-video-icon {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            background: rgba(0,0,0,.5);
            color: #fff; font-size: 1.2rem;
        }
        .video-embed-wrapper {
            position: relative;
            padding-top: 56.25%;
            border-radius: 14px;
            overflow: hidden;
            background: #000;
            margin-bottom: 1.25rem;
        }
        .video-embed-wrapper iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0; }
        @media (max-width: 768px) {
            .hero-card { padding: 1.5rem; }
            .price-display { font-size: 1.8rem; }
        }
        /* TinyMCE HTML content */
        .logement-html-content { line-height: 1.7; }
        .logement-html-content p { margin-bottom: .75rem; }
        .logement-html-content ul,
        .logement-html-content ol { padding-left: 1.4rem; margin-bottom: .75rem; }
        .logement-html-content h1,.logement-html-content h2,.logement-html-content h3 { font-size: 1.1rem; font-weight: 700; margin-top: 1rem; }
    </style>
</head>
<body>

<?php
$menuNav = renderFrontOfficeMenuHtml('/logement.php?ref=' . urlencode($ref));
$extraNav = $menuNav;
renderFrontOfficeHeader($siteUrl, $companyName, $extraNav ?: null);
?>

<main class="container py-4">
    <div class="row g-4">

        <!-- Left / Main column -->
        <div class="col-lg-8">

            <!-- Hero card -->
            <div class="hero-card mb-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <span class="badge bg-<?php echo $statutLabel[1]; ?> mb-2"><?php echo $statutLabel[0]; ?></span>
                        <div class="mb-1">
                            <span style="display:inline-block;background:rgba(255,255,255,.18);color:#fff;font-family:monospace;font-size:.9rem;font-weight:700;letter-spacing:.05em;padding:.2em .6em;border-radius:6px;border:1px solid rgba(255,255,255,.3);">
                                <i class="bi bi-hash me-1"></i><?php echo htmlspecialchars($logement['reference']); ?>
                            </span>
                        </div>
                        <h1 class="h3 fw-bold mb-1"><?php echo htmlspecialchars($logement['adresse']); ?></h1>
                        <p class="mb-0 opacity-75">
                            <?php if ($logement['type']): ?>
                            <i class="bi bi-house me-1"></i><?php echo htmlspecialchars($logement['type']); ?>
                            <?php endif; ?>
                            <?php if ($logement['surface']): ?>
                            &nbsp;·&nbsp;<i class="bi bi-rulers me-1"></i><?php echo htmlspecialchars($logement['surface']); ?> m²
                            <?php endif; ?>
                            <?php if ($logement['parking'] !== 'Aucun'): ?>
                            &nbsp;·&nbsp;<i class="bi bi-p-square me-1"></i><?php echo htmlspecialchars($logement['parking']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="text-end">
                        <div class="hero-price"><?php echo number_format((float)$logement['loyer'], 0, ',', ' '); ?> €<span style="font-size:1rem;font-weight:400;">/mois</span></div>
                        <?php if ((float)$logement['charges'] > 0): ?>
                        <div class="hero-charges">+ <?php echo number_format((float)$logement['charges'], 0, ',', ' '); ?> € charges</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Photo slider -->
            <?php if (!empty($photos)): ?>
            <div class="photo-slider" id="photoSlider">
                <div class="slider-main" id="sliderMain">
                    <?php foreach ($photos as $idx => $photo): ?>
                    <?php $isVideo = strpos($photo['mime_type'], 'video/') === 0; ?>
                    <div class="slide-item <?php echo $idx === 0 ? 'active' : ''; ?>" data-index="<?php echo $idx; ?>">
                        <?php if ($isVideo): ?>
                        <video src="<?php echo htmlspecialchars(rtrim($config['SITE_URL'], '/') . '/uploads/logements/' . $photo['filename']); ?>"
                               controls preload="metadata" style="width:100%;height:100%;object-fit:cover;position:absolute;top:0;left:0;"></video>
                        <?php else: ?>
                        <img src="<?php echo htmlspecialchars(rtrim($config['SITE_URL'], '/') . '/uploads/logements/' . $photo['filename']); ?>"
                             alt="<?php echo htmlspecialchars($photo['original_name']); ?>"
                             loading="<?php echo $idx === 0 ? 'eager' : 'lazy'; ?>">
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <?php if (count($photos) > 1): ?>
                    <button class="slider-nav prev" id="sliderPrev" aria-label="Photo précédente">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <button class="slider-nav next" id="sliderNext" aria-label="Photo suivante">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                    <div class="slider-counter"><span id="sliderCurrent">1</span> / <?php echo count($photos); ?></div>
                    <?php endif; ?>
                </div>

                <?php if (count($photos) > 1): ?>
                <div class="slider-thumbs" id="sliderThumbs">
                    <?php foreach ($photos as $idx => $photo): ?>
                    <?php $isVideo = strpos($photo['mime_type'], 'video/') === 0; ?>
                    <div class="thumb-item <?php echo $idx === 0 ? 'active' : ''; ?>" data-index="<?php echo $idx; ?>" role="button" aria-label="Miniature <?php echo $idx + 1; ?>">
                        <?php if ($isVideo): ?>
                        <div style="width:100%;height:100%;background:#222;display:flex;align-items:center;justify-content:center;position:relative;">
                            <video src="<?php echo htmlspecialchars(rtrim($config['SITE_URL'], '/') . '/uploads/logements/' . $photo['filename']); ?>"
                                   preload="metadata" muted
                                   style="width:100%;height:100%;object-fit:cover;position:absolute;top:0;left:0;"></video>
                            <div class="thumb-video-icon" style="z-index:1;"><i class="bi bi-play-circle text-white"></i></div>
                        </div>
                        <?php else: ?>
                        <img src="<?php echo htmlspecialchars(rtrim($config['SITE_URL'], '/') . '/uploads/logements/' . $photo['filename']); ?>"
                             alt="" loading="lazy">
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- YouTube video -->
            <?php if (!empty($logement['video_youtube'])): ?>
            <?php
            $ytUrl = $logement['video_youtube'];
            $ytId  = '';
            if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/', $ytUrl, $ytMatch)) {
                $ytId = $ytMatch[1];
            }
            ?>
            <?php if ($ytId): ?>
            <div class="video-embed-wrapper mb-4">
                <iframe src="https://www.youtube-nocookie.com/embed/<?php echo htmlspecialchars($ytId); ?>?rel=0"
                        title="Vidéo du logement"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen
                        loading="lazy"></iframe>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Infos clés -->
            <div class="section-card">
                <div class="section-title">Informations clés</div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-value"><?php echo number_format((float)$logement['loyer'], 0, ',', ' '); ?> €</div>
                        <div class="info-label">Loyer/mois</div>
                    </div>
                    <?php if ((float)$logement['charges'] > 0): ?>
                    <div class="info-item">
                        <div class="info-value"><?php echo number_format((float)$logement['charges'], 0, ',', ' '); ?> €</div>
                        <div class="info-label">Charges/mois</div>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <div class="info-value"><?php echo number_format($totalMensuel, 0, ',', ' '); ?> €</div>
                        <div class="info-label">Total mensuel</div>
                    </div>
                    <?php if ((float)$logement['depot_garantie'] > 0): ?>
                    <div class="info-item">
                        <div class="info-value"><?php echo number_format((float)$logement['depot_garantie'], 0, ',', ' '); ?> €</div>
                        <div class="info-label">Dépôt garantie</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($logement['surface']): ?>
                    <div class="info-item">
                        <div class="info-value"><?php echo htmlspecialchars($logement['surface']); ?> m²</div>
                        <div class="info-label">Surface</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($logement['date_disponibilite'] && $isDisponible): ?>
                    <div class="info-item">
                        <div class="info-value" style="font-size:.95rem;"><?php echo date('d/m/Y', strtotime($logement['date_disponibilite'])); ?></div>
                        <div class="info-label">Disponible dès</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Description -->
            <?php if (!empty($logement['description'])): ?>
            <div class="section-card">
                <div class="section-title">Description</div>
                <div class="logement-html-content"><?php echo $logement['description']; ?></div>
            </div>
            <?php endif; ?>

            <!-- Équipements inclus (texte libre) -->
            <?php if (!empty($logement['equipements'])): ?>
            <div class="section-card">
                <div class="section-title">Équipements inclus</div>
                <div class="logement-html-content"><?php echo $logement['equipements']; ?></div>
            </div>
            <?php endif; ?>

            <!-- Commodités à proximité -->
            <?php if (!empty($logement['commodites'])): ?>
            <div class="section-card">
                <div class="section-title">Commodités à proximité</div>
                <div class="logement-html-content"><?php echo $logement['commodites']; ?></div>
            </div>
            <?php endif; ?>

            <!-- Conditions de visite et de candidature -->
            <?php if (!empty($logement['conditions_visite'])): ?>
            <div class="section-card">
                <div class="section-title">Conditions de visite et de candidature</div>
                <div class="logement-html-content"><?php echo $logement['conditions_visite']; ?></div>
            </div>
            <?php endif; ?>

        </div><!-- /col-lg-8 -->

        <!-- Right Column: CTA & Infos -->
        <div class="col-lg-4">

            <!-- Résumé prix -->
            <div class="section-card">
                <div class="section-title">Récapitulatif financier</div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Loyer</span>
                    <strong><?php echo number_format((float)$logement['loyer'], 2, ',', ' '); ?> €/mois</strong>
                </div>
                <?php if ((float)$logement['charges'] > 0): ?>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Charges</span>
                    <strong><?php echo number_format((float)$logement['charges'], 2, ',', ' '); ?> €/mois</strong>
                </div>
                <?php endif; ?>
                <div class="d-flex justify-content-between py-2 border-bottom fw-bold">
                    <span>Total mensuel</span>
                    <span class="text-primary"><?php echo number_format($totalMensuel, 2, ',', ' '); ?> €/mois</span>
                </div>
                <?php if ((float)$logement['depot_garantie'] > 0): ?>
                <div class="d-flex justify-content-between py-2">
                    <span class="text-muted">Dépôt de garantie</span>
                    <strong><?php echo number_format((float)$logement['depot_garantie'], 2, ',', ' '); ?> €</strong>
                </div>
                <?php endif; ?>
                <p class="text-muted small mt-2 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Revenus recommandés : <strong><?php echo number_format($totalMensuel * 3, 0, ',', ' '); ?> €/mois</strong> (3× le loyer).
                </p>
            </div>

            <!-- Informations logement -->
            <div class="section-card">
                <div class="section-title">Caractéristiques</div>
                <ul class="list-unstyled mb-0 small">
                    <?php if ($logement['type']): ?>
                    <li class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted"><i class="bi bi-house me-1"></i>Type</span>
                        <strong><?php echo htmlspecialchars($logement['type']); ?></strong>
                    </li>
                    <?php endif; ?>
                    <?php if ($logement['surface']): ?>
                    <li class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted"><i class="bi bi-rulers me-1"></i>Surface</span>
                        <strong><?php echo htmlspecialchars($logement['surface']); ?> m²</strong>
                    </li>
                    <?php endif; ?>
                    <li class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted"><i class="bi bi-p-square me-1"></i>Parking</span>
                        <strong><?php echo htmlspecialchars($logement['parking']); ?></strong>
                    </li>
                    <li class="d-flex justify-content-between py-1">
                        <span class="text-muted"><i class="bi bi-circle-fill me-1" style="font-size:.5rem;"></i>Statut</span>
                        <span class="badge bg-<?php echo $statutLabel[1]; ?>"><?php echo $statutLabel[0]; ?></span>
                    </li>
                </ul>
            </div>

        </div><!-- /col-lg-4 -->
    </div><!-- /row -->
</main>

<!-- Footer -->
<footer class="site-footer">
    <div class="container text-center">
        <p class="mb-0">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($companyName); ?> — Tous droits réservés</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($photos) && count($photos) > 1): ?>
<script>
(function () {
    'use strict';
    var slides      = document.querySelectorAll('#sliderMain .slide-item');
    var thumbs      = document.querySelectorAll('#sliderThumbs .thumb-item');
    var btnPrev     = document.getElementById('sliderPrev');
    var btnNext     = document.getElementById('sliderNext');
    var counterEl   = document.getElementById('sliderCurrent');
    var total       = slides.length;
    var current     = 0;

    function goTo(idx) {
        slides[current].classList.remove('active');
        if (thumbs[current]) thumbs[current].classList.remove('active');
        current = (idx + total) % total;
        slides[current].classList.add('active');
        if (thumbs[current]) {
            thumbs[current].classList.add('active');
            thumbs[current].scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
        if (counterEl) counterEl.textContent = current + 1;
    }

    if (btnPrev) btnPrev.addEventListener('click', function () { goTo(current - 1); });
    if (btnNext) btnNext.addEventListener('click', function () { goTo(current + 1); });

    thumbs.forEach(function (thumb) {
        thumb.addEventListener('click', function () {
            goTo(parseInt(this.dataset.index, 10));
        });
    });

    // Keyboard navigation
    document.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowLeft')  goTo(current - 1);
        if (e.key === 'ArrowRight') goTo(current + 1);
    });
}());
</script>
<?php endif; ?>
</body>
</html>
