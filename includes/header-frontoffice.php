<?php
/**
 * En-tête commun du front office
 * My Invest Immobilier
 *
 * Usage :
 *   require_once __DIR__ . '/includes/header-frontoffice.php';
 *   renderFrontOfficeHeader($siteUrl, $companyName, $extraNav);
 *
 * Le logo est chargé depuis le paramètre `logo_societe` (table `parametres`).
 * Si aucun logo n'est configuré, le nom de la société est affiché à la place.
 *
 * Le menu de navigation est automatiquement chargé depuis la table
 * `frontend_menu_items`. Vous pouvez passer $extraNav pour ajouter du HTML
 * supplémentaire à droite du menu (ex : bouton candidature) ou pour remplacer
 * entièrement le menu en passant false.
 *
 * Variables attendues dans la portée appelante :
 *   - $pdo          (connexion PDO, disponible via includes/db.php)
 *   - $siteUrl      (URL de base du site, sans slash final)
 *   - $companyName  (nom de la société)
 */

/**
 * Retourne l'URL relative du logo configuré dans les paramètres, ou null.
 */
function getFrontOfficeLogo(): ?string {
    global $pdo;
    if (!isset($pdo)) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'logo_societe' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && !empty($row['valeur'])) ? $row['valeur'] : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Charge les éléments du menu frontoffice depuis la base de données.
 *
 * @return array[] Tableau associatif : label, url, target, icone
 */
function getFrontOfficeMenuItems(): array {
    global $pdo;
    if (!isset($pdo)) {
        return [];
    }
    try {
        $stmt = $pdo->query(
            "SELECT label, url, target, icone
             FROM frontend_menu_items
             WHERE actif = 1
             ORDER BY ordre ASC"
        );
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        // Table not yet created (migration pending) — degrade gracefully
        return [];
    }
}

/**
 * Génère le HTML du menu de navigation à partir des éléments de menu en base.
 *
 * @param string $currentUri URI courante (ex: /page.php?slug=a-propos)
 * @return string HTML de la navigation
 */
function renderFrontOfficeMenuHtml(string $currentUri = ''): string {
    $items = getFrontOfficeMenuItems();
    if (empty($items)) {
        return '';
    }

    $html = '<nav class="fo-nav d-none d-lg-flex align-items-center gap-1">';
    foreach ($items as $item) {
        $isActive   = ($item['url'] === $currentUri) ? ' active' : '';
        $targetAttr = ($item['target'] === '_blank') ? ' target="_blank" rel="noopener"' : '';
        $iconHtml   = !empty($item['icone'])
            ? '<i class="bi ' . htmlspecialchars($item['icone']) . ' me-1"></i>'
            : '';
        $html .= '<a class="fo-nav-link' . $isActive . '" href="' . htmlspecialchars($item['url']) . '"' . $targetAttr . '>'
              . $iconHtml . htmlspecialchars($item['label']) . '</a>';
    }
    $html .= '</nav>';

    // Mobile off-canvas nav
    $html .= '<button class="btn btn-sm btn-outline-secondary d-lg-none" '
          . 'type="button" data-bs-toggle="offcanvas" data-bs-target="#foNavOffcanvas" aria-controls="foNavOffcanvas">'
          . '<i class="bi bi-list fs-5"></i></button>';
    $html .= '<div class="offcanvas offcanvas-end" tabindex="-1" id="foNavOffcanvas">'
          . '<div class="offcanvas-header"><h5 class="offcanvas-title">Menu</h5>'
          . '<button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button></div>'
          . '<div class="offcanvas-body"><nav class="d-flex flex-column gap-1">';
    foreach ($items as $item) {
        $isActive   = ($item['url'] === $currentUri) ? ' active' : '';
        $targetAttr = ($item['target'] === '_blank') ? ' target="_blank" rel="noopener"' : '';
        $iconHtml   = !empty($item['icone'])
            ? '<i class="bi ' . htmlspecialchars($item['icone']) . ' me-2"></i>'
            : '';
        $html .= '<a class="fo-nav-link fo-nav-link-mobile' . $isActive . '" href="' . htmlspecialchars($item['url']) . '"' . $targetAttr . '>'
              . $iconHtml . htmlspecialchars($item['label']) . '</a>';
    }
    $html .= '</nav></div></div>';

    return $html;
}

/**
 * Affiche l'en-tête front office standard.
 *
 * @param string           $siteUrl     URL de base (ex : https://example.com)
 * @param string           $companyName Nom affiché dans le header
 * @param string|null|false $extraNav   HTML optionnel inséré côté droit du header.
 *                                      Null → le menu DB est chargé automatiquement.
 *                                      False → aucun menu.
 * @param string           $currentUri  URI courante pour surligner l'item actif.
 */
function renderFrontOfficeHeader(string $siteUrl, string $companyName, $extraNav = null, string $currentUri = ''): void {
    $logoSrc = getFrontOfficeLogo();

    // Auto-load menu from DB when caller passes null
    if ($extraNav === null) {
        $extraNav = renderFrontOfficeMenuHtml($currentUri);
    }
?>
<header class="site-header">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between gap-3">
            <a href="<?php echo htmlspecialchars(rtrim($siteUrl, '/') . '/logements.php'); ?>" class="brand d-flex align-items-center gap-2 text-decoration-none flex-shrink-0">
                <?php if ($logoSrc): ?>
                    <img src="<?php echo htmlspecialchars($logoSrc); ?>"
                         alt="<?php echo htmlspecialchars($companyName); ?>"
                         class="header-logo js-logo-img">
                    <span class="js-logo-fallback" style="display:none;"><?php echo htmlspecialchars($companyName); ?></span>
                    <span class="company-name"><?php echo htmlspecialchars($companyName); ?></span>
                <?php else: ?>
                    <i class="bi bi-building me-1"></i><?php echo htmlspecialchars($companyName); ?>
                <?php endif; ?>
            </a>
            <?php if ($extraNav): ?>
            <div class="header-nav d-flex align-items-center gap-2">
                <?php echo $extraNav; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</header>
<style>
.fo-nav-link {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 6px;
    font-weight: 500;
    font-size: .92rem;
    color: #444;
    text-decoration: none;
    transition: background .15s, color .15s;
    white-space: nowrap;
}
.fo-nav-link:hover,
.fo-nav-link.active {
    background: #eef4fb;
    color: #3498db;
    text-decoration: none;
}
.fo-nav-link-mobile {
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 1rem;
}
.site-header .company-name {
    font-size: 1rem;
    font-weight: 700;
    color: #2c3e50;
    white-space: nowrap;
}
</style>
<?php if ($logoSrc): ?>
<script>
(function () {
    var img = document.querySelector('.site-header .js-logo-img');
    if (img) {
        img.addEventListener('error', function () {
            img.style.display = 'none';
            var fallback = document.querySelector('.site-header .js-logo-fallback');
            if (fallback) { fallback.style.display = 'inline'; }
            var companyName = document.querySelector('.site-header .company-name');
            if (companyName) { companyName.style.display = 'none'; }
        });
    }
}());
</script>
<?php endif; ?>
<?php
}
