<?php
/**
 * Homepage router
 * My Invest Immobilier
 *
 * Redirects to the page flagged as is_homepage = 1 in the `frontend_pages` table.
 * Falls back to the logements listing if no homepage is configured.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$siteUrl = rtrim($config['SITE_URL'] ?? '', '/');

$homepage = null;
try {
    $stmt = $pdo->query("SELECT slug FROM frontend_pages WHERE is_homepage = 1 AND actif = 1 LIMIT 1");
    $homepage = $stmt->fetchColumn();
} catch (Exception $e) {
    // Table may not exist yet or column not added — fall through to default
}

if ($homepage) {
    // Redirect to SEO-friendly URL (handled by .htaccess → page.php)
    header('Location: ' . $siteUrl . '/' . rawurlencode($homepage), true, 302);
} else {
    // Default fallback: show property listings
    header('Location: ' . $siteUrl . '/logements.php', true, 302);
}
exit;
