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
 * Affiche l'en-tête front office standard.
 *
 * @param string      $siteUrl     URL de base (ex : https://example.com)
 * @param string      $companyName Nom affiché dans le header
 * @param string|null $extraNav    HTML optionnel inséré côté droit du header (ex : bouton candidature)
 */
function renderFrontOfficeHeader(string $siteUrl, string $companyName, ?string $extraNav = null): void {
    $logoSrc = getFrontOfficeLogo();
?>
<header class="site-header">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between">
            <a href="<?php echo htmlspecialchars(rtrim($siteUrl, '/') . '/logements.php'); ?>" class="brand d-flex align-items-center gap-2 text-decoration-none">
                <?php if ($logoSrc): ?>
                    <img src="<?php echo htmlspecialchars($logoSrc); ?>"
                         alt="<?php echo htmlspecialchars($companyName); ?>"
                         class="header-logo js-logo-img">
                    <span class="js-logo-fallback" style="display:none;"><?php echo htmlspecialchars($companyName); ?></span>
                <?php else: ?>
                    <i class="bi bi-building me-1"></i><?php echo htmlspecialchars($companyName); ?>
                <?php endif; ?>
            </a>
            <?php if ($extraNav): ?>
            <div class="header-nav">
                <?php echo $extraNav; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</header>
<?php if ($logoSrc): ?>
<script>
(function () {
    var img = document.querySelector('.site-header .js-logo-img');
    if (img) {
        img.addEventListener('error', function () {
            img.style.display = 'none';
            var fallback = document.querySelector('.site-header .js-logo-fallback');
            if (fallback) { fallback.style.display = 'inline'; }
        });
    }
}());
</script>
<?php endif; ?>
<?php
}
