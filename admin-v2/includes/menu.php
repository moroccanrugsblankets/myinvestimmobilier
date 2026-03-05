<?php
/**
 * Unified menu for all admin-v2 pages
 * My Invest Immobilier
 */

// Get the current page to highlight active menu item
$current_page = basename($_SERVER['PHP_SELF']);

// Get logo from parameters
$logo_societe = null;
try {
    $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'logo_societe'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $logo_societe = $result['valeur'];
    }
} catch (Exception $e) {
    // If parameter doesn't exist yet, use default
    $logo_societe = null;
}

// Map detail pages to their parent menu items
$page_to_menu_map = [
    'candidature-detail.php' => 'candidatures.php',
    'candidature-actions.php' => 'candidatures.php',
    'add-note-candidature.php' => 'candidatures.php',
    'send-email-candidature.php' => 'candidatures.php',
    'change-status.php' => 'candidatures.php',
    'generer-contrat.php' => 'contrats.php',
    'envoyer-signature.php' => 'contrats.php',
    'supprimer-contrat.php' => 'contrats.php',
    'contrat-configuration.php' => 'contrats.php',
    'contrat-detail.php' => 'contrats.php',
    'generer-quittances.php' => 'contrats.php',
    'quittance-configuration.php' => 'contrats.php',
    'quittances.php' => 'contrats.php',
    'edit-quittance.php' => 'contrats.php',
    'contrats-clotures.php' => 'contrats.php',
    'contrats-supprimes.php' => 'contrats.php',
    'restaurer-contrat.php' => 'contrats.php',
    'create-etat-lieux.php' => 'etats-lieux.php',
    'etat-lieux-configuration.php' => 'etats-lieux.php',
    'edit-etat-lieux.php' => 'etats-lieux.php',
    'view-etat-lieux.php' => 'etats-lieux.php',
    'finalize-etat-lieux.php' => 'etats-lieux.php',
    'manage-inventory-equipements.php' => 'logements.php',
    'create-inventaire.php' => 'inventaires.php',
    'inventaire-configuration.php' => 'inventaires.php',
    'edit-inventaire.php' => 'inventaires.php',
    'view-inventaire.php' => 'inventaires.php',
    'finalize-inventaire.php' => 'inventaires.php',
    'compare-inventaire.php' => 'inventaires.php',
    'administrateurs-actions.php' => 'administrateurs.php',
    'edit-bilan-logement.php' => 'contrats.php',
    'bilan-logement-configuration.php' => 'contrats.php',
    'configuration-rappels-loyers.php' => 'gestion-loyers.php',
    'stripe-configuration.php' => 'gestion-loyers.php',
    'signalement-detail.php'  => 'signalements.php',
    'collaborateurs.php'      => 'signalements.php',
    'guide-reparations.php'   => 'signalements.php',
];

// Check if current page is a detail page, if so use parent menu
$active_menu = $page_to_menu_map[$current_page] ?? $current_page;
?>
<!-- Mobile menu toggle button -->
<button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
    <i class="bi bi-list" style="font-size: 1.5rem;"></i>
</button>

<!-- Sidebar overlay for mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="logo">
        <?php if ($logo_societe && file_exists($_SERVER['DOCUMENT_ROOT'] . $logo_societe)): ?>
            <img src="<?php echo htmlspecialchars($logo_societe); ?>" 
                 alt="Logo société" 
                 style="max-width: 100%; max-height: 80px; margin-bottom: 10px;">
        <?php else: ?>
            <i class="bi bi-building" style="font-size: 2rem;"></i>
            <h4>MY Invest</h4>
            <small>Immobilier</small>
        <?php endif; ?>
    </div>
    <ul class="nav flex-column mt-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_menu === 'index.php' ? 'active' : ''; ?>" href="index.php">
                <i class="bi bi-speedometer2"></i> Tableau de bord
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_menu === 'logements.php' ? 'active' : ''; ?>" href="logements.php">
                <i class="bi bi-house-door"></i> Logements
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_menu === 'candidatures.php' ? 'active' : ''; ?>" href="candidatures.php">
                <i class="bi bi-file-earmark-text"></i> Candidatures
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_menu === 'contrats.php' ? 'active' : ''; ?>" href="contrats.php">
                <i class="bi bi-file-earmark-check"></i> Contrats
            </a>
            <?php if ($active_menu === 'contrats.php'): ?>
            <ul class="nav flex-column ms-3">
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'contrats-clotures.php' ? 'active' : ''; ?>" href="contrats-clotures.php" style="padding: 8px 20px; font-size: 0.9rem;">
                        <i class="bi bi-archive"></i> Contrats clôturés
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'contrats-supprimes.php' ? 'active' : ''; ?>" href="contrats-supprimes.php" style="padding: 8px 20px; font-size: 0.9rem;">
                        <i class="bi bi-trash"></i> Contrats supprimés
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'contrat-configuration.php' ? 'active' : ''; ?>" href="contrat-configuration.php" style="padding: 8px 20px; font-size: 0.9rem;">
                        <i class="bi bi-gear"></i> Configuration Contrats
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'bilan-logement-configuration.php' ? 'active' : ''; ?>" href="bilan-logement-configuration.php" style="padding: 8px 20px; font-size: 0.9rem;">
                        <i class="bi bi-file-earmark-bar-graph"></i> Configuration Bilan
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'quittance-configuration.php' ? 'active' : ''; ?>" href="quittance-configuration.php" style="padding: 8px 20px; font-size: 0.9rem;">
                        <i class="bi bi-receipt"></i> Configuration Quittances
                    </a>
                </li>
            </ul>
            <?php endif; ?>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_menu === 'etats-lieux.php' ? 'active' : ''; ?>" href="etats-lieux.php">
                <i class="bi bi-clipboard-check"></i> États des lieux
            </a>
            <?php if ($active_menu === 'etats-lieux.php' && $current_page !== 'finalize-etat-lieux.php'): ?>
            <ul class="nav flex-column ms-3">
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'etat-lieux-configuration.php' ? 'active' : ''; ?>" href="etat-lieux-configuration.php" style="padding: 8px 20px; font-size: 0.9rem;">
                        <i class="bi bi-gear"></i> Configuration
                    </a>
                </li>
            </ul>
            <?php endif; ?>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_menu === 'inventaires.php' ? 'active' : ''; ?>" href="inventaires.php">
                <i class="bi bi-box-seam"></i> Inventaire
            </a>
            <?php if ($active_menu === 'inventaires.php' && $current_page !== 'finalize-inventaire.php'): ?>
            <ul class="nav flex-column ms-3">
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'inventaire-configuration.php' ? 'active' : ''; ?>" href="inventaire-configuration.php" style="padding: 8px 20px; font-size: 0.9rem;">
                        <i class="bi bi-gear"></i> Configuration
                    </a>
                </li>
            </ul>
            <?php endif; ?>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_menu === 'gestion-loyers.php' ? 'active' : ''; ?>" href="gestion-loyers.php">
                <i class="bi bi-cash-stack"></i> Gestion des Loyers
            </a>
            <?php if ($active_menu === 'gestion-loyers.php'): ?>
            <ul class="nav flex-column ms-3">
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'configuration-rappels-loyers.php' ? 'active' : ''; ?>" href="configuration-rappels-loyers.php" style="padding: 8px 20px; font-size: 0.9rem;">
                        <i class="bi bi-gear"></i> Configuration Rappels
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'stripe-configuration.php' ? 'active' : ''; ?>" href="stripe-configuration.php" style="padding: 8px 20px; font-size: 0.9rem;">
                        <i class="bi bi-credit-card"></i> Paiement Stripe
                    </a>
                </li>
            </ul>
            <?php endif; ?>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_menu === 'signalements.php' ? 'active' : ''; ?>" href="signalements.php">
                <i class="bi bi-exclamation-triangle"></i> Signalements
            </a>
            <?php if ($active_menu === 'signalements.php'): ?>
            <ul class="nav flex-column ms-3">
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'collaborateurs.php' ? 'active' : ''; ?>" href="collaborateurs.php" style="padding: 8px 20px; font-size: 0.9rem;">
                        <i class="bi bi-people"></i> Collaborateurs
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page === 'guide-reparations.php' ? 'active' : ''; ?>" href="guide-reparations.php" style="padding: 8px 20px; font-size: 0.9rem;">
                        <i class="bi bi-book"></i> Guide des réparations
                    </a>
                </li>
            </ul>
            <?php endif; ?>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_menu === 'email-templates.php' ? 'active' : ''; ?>" href="email-templates.php">
                <i class="bi bi-envelope"></i> Templates d'Email
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_menu === 'email-tracker.php' ? 'active' : ''; ?>" href="email-tracker.php">
                <i class="bi bi-envelope-check"></i> Suivi des Emails
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_menu === 'parametres.php' ? 'active' : ''; ?>" href="parametres.php">
                <i class="bi bi-gear"></i> Paramètres
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_menu === 'cron-jobs.php' ? 'active' : ''; ?>" href="cron-jobs.php">
                <i class="bi bi-clock-history"></i> Tâches Automatisées
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_menu === 'sauvegardes.php' ? 'active' : ''; ?>" href="sauvegardes.php">
                <i class="bi bi-archive"></i> Sauvegardes
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_menu === 'administrateurs.php' ? 'active' : ''; ?>" href="administrateurs.php">
                <i class="bi bi-shield-lock"></i> Comptes Administrateurs
            </a>
        </li>
    </ul>
    <a href="logout.php" class="btn btn-outline-light logout-btn">
        <i class="bi bi-box-arrow-right"></i> Déconnexion
    </a>
</div>

<!-- JavaScript for mobile menu toggle -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    
    // Toggle sidebar on button click
    menuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('active');
        sidebarOverlay.classList.toggle('active');
    });
    
    // Close sidebar when clicking on overlay
    sidebarOverlay.addEventListener('click', function() {
        sidebar.classList.remove('active');
        sidebarOverlay.classList.remove('active');
    });
    
    // Close sidebar when clicking on a menu link
    // This is harmless on desktop and ensures proper behavior on all screen sizes
    const menuLinks = sidebar.querySelectorAll('.nav-link');
    menuLinks.forEach(function(link) {
        link.addEventListener('click', function() {
            // Only close if sidebar is in mobile mode (has active class)
            if (sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
            }
        });
    });

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert-success, .alert-danger, .alert-warning, .alert-info').forEach(function(alert) {
            // Use Bootstrap's dismiss method if available, otherwise fade out manually
            if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                bsAlert.close();
            } else {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() { alert.remove(); }, 500);
            }
        });
    }, 5000);
});
</script>
