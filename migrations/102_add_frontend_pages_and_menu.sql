-- Migration 102: Pages frontoffice et menu configurable
-- Date: 2026-03-10
-- Description:
--   1. Crée la table `frontend_pages` pour les pages publiques gérables via l'admin
--   2. Crée la table `frontend_menu_items` pour le menu de navigation du site public
--   3. Insère des pages et éléments de menu par défaut

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Table des pages frontoffice
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS frontend_pages (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    slug         VARCHAR(100) NOT NULL UNIQUE COMMENT 'Identifiant URL (ex: a-propos)',
    titre        VARCHAR(255) NOT NULL         COMMENT 'Titre de la page',
    contenu_html LONGTEXT                      COMMENT 'Contenu HTML de la page',
    meta_description VARCHAR(320) DEFAULT ''   COMMENT 'Meta description SEO',
    actif        TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = visible publiquement',
    ordre        INT NOT NULL DEFAULT 0        COMMENT 'Ordre d\'affichage',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug  (slug),
    INDEX idx_actif (actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Table des éléments du menu frontoffice
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS frontend_menu_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    label       VARCHAR(150) NOT NULL          COMMENT 'Libellé affiché dans le menu',
    url         VARCHAR(500) NOT NULL          COMMENT 'URL (relative ou absolue)',
    target      VARCHAR(20)  DEFAULT '_self'   COMMENT '_self ou _blank',
    icone       VARCHAR(50)  DEFAULT ''        COMMENT 'Classe Bootstrap Icons (ex: bi-house)',
    ordre       INT NOT NULL DEFAULT 0         COMMENT 'Ordre d\'affichage',
    actif       TINYINT(1) NOT NULL DEFAULT 1  COMMENT '1 = visible dans le menu',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_actif (actif),
    INDEX idx_ordre (ordre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Pages par défaut
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO frontend_pages (slug, titre, contenu_html, meta_description, actif, ordre)
VALUES
(
    'a-propos',
    'À propos de My Invest Immobilier',
    '<section class="py-5">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <h1 class="display-5 fw-bold mb-4">Votre gestionnaire immobilier de confiance</h1>
        <p class="lead mb-4">My Invest Immobilier est une agence spécialisée dans la gestion locative de logements meublés. Nous accompagnons propriétaires et locataires avec expertise et transparence.</p>
        <ul class="list-unstyled mb-4">
          <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>Gestion locative complète</li>
          <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>Suivi des paiements en ligne</li>
          <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>États des lieux numériques</li>
          <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>Réactivité et disponibilité</li>
        </ul>
        <a href="/logements.php" class="btn btn-primary btn-lg">Voir nos logements</a>
      </div>
      <div class="col-lg-6 text-center">
        <i class="bi bi-building" style="font-size: 8rem; color: #3498db; opacity: 0.15;"></i>
      </div>
    </div>
  </div>
</section>
<section class="py-5 bg-light">
  <div class="container">
    <h2 class="text-center mb-5">Nos valeurs</h2>
    <div class="row g-4 text-center">
      <div class="col-md-4">
        <div class="p-4 bg-white rounded-3 shadow-sm h-100">
          <i class="bi bi-shield-check display-4 text-primary mb-3"></i>
          <h4>Confiance</h4>
          <p class="text-muted">Nous construisons des relations durables basées sur la transparence et le respect.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="p-4 bg-white rounded-3 shadow-sm h-100">
          <i class="bi bi-lightning-charge display-4 text-primary mb-3"></i>
          <h4>Réactivité</h4>
          <p class="text-muted">Chaque demande est traitée rapidement pour garantir votre satisfaction.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="p-4 bg-white rounded-3 shadow-sm h-100">
          <i class="bi bi-star display-4 text-primary mb-3"></i>
          <h4>Excellence</h4>
          <p class="text-muted">Un service de qualité supérieure, du premier contact jusqu''à la fin du bail.</p>
        </div>
      </div>
    </div>
  </div>
</section>',
    'Découvrez My Invest Immobilier, votre partenaire de confiance pour la gestion locative de logements meublés.',
    1,
    10
),
(
    'services',
    'Nos Services',
    '<section class="py-5">
  <div class="container">
    <h1 class="display-5 fw-bold text-center mb-2">Nos Services</h1>
    <p class="lead text-center text-muted mb-5">Des solutions complètes pour propriétaires et locataires</p>
    <div class="row g-4">
      <div class="col-md-6 col-lg-4">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body p-4 text-center">
            <i class="bi bi-house-add display-4 text-primary mb-3"></i>
            <h4 class="card-title">Mise en location</h4>
            <p class="card-text text-muted">Nous publions votre bien, sélectionnons les candidats et rédigeons les contrats de bail.</p>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-lg-4">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body p-4 text-center">
            <i class="bi bi-cash-coin display-4 text-primary mb-3"></i>
            <h4 class="card-title">Gestion des loyers</h4>
            <p class="card-text text-muted">Encaissement, quittances, rappels automatiques et suivi des paiements en temps réel.</p>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-lg-4">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body p-4 text-center">
            <i class="bi bi-clipboard2-check display-4 text-primary mb-3"></i>
            <h4 class="card-title">États des lieux</h4>
            <p class="card-text text-muted">États des lieux numériques avec photos, comparaison entrée/sortie et signature électronique.</p>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-lg-4">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body p-4 text-center">
            <i class="bi bi-tools display-4 text-primary mb-3"></i>
            <h4 class="card-title">Gestion des interventions</h4>
            <p class="card-text text-muted">Suivi des signalements de pannes, coordination des interventions et facturation des décomptes.</p>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-lg-4">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body p-4 text-center">
            <i class="bi bi-file-earmark-text display-4 text-primary mb-3"></i>
            <h4 class="card-title">Contrats numériques</h4>
            <p class="card-text text-muted">Génération et signature électronique de baux de location conformes à la législation.</p>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-lg-4">
        <div class="card h-100 border-0 shadow-sm">
          <div class="card-body p-4 text-center">
            <i class="bi bi-headset display-4 text-primary mb-3"></i>
            <h4 class="card-title">Espace locataire</h4>
            <p class="card-text text-muted">Accès en ligne pour déclarer une anomalie, consulter ses documents ou déclencher une procédure de départ.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>',
    'Découvrez tous les services de gestion locative proposés par My Invest Immobilier.',
    1,
    20
),
(
    'contact',
    'Nous Contacter',
    '<section class="py-5">
  <div class="container">
    <h1 class="display-5 fw-bold text-center mb-2">Nous Contacter</h1>
    <p class="lead text-center text-muted mb-5">Notre équipe est à votre disposition</p>
    <div class="row g-5 justify-content-center">
      <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body p-4">
            <h4 class="mb-4">Coordonnées</h4>
            <ul class="list-unstyled">
              <li class="mb-3 d-flex align-items-start gap-3">
                <i class="bi bi-envelope-at fs-4 text-primary flex-shrink-0 mt-1"></i>
                <div>
                  <strong>Email</strong><br>
                  <a href="mailto:contact@myinvest-immobilier.com">contact@myinvest-immobilier.com</a>
                </div>
              </li>
              <li class="mb-3 d-flex align-items-start gap-3">
                <i class="bi bi-telephone fs-4 text-primary flex-shrink-0 mt-1"></i>
                <div>
                  <strong>Téléphone</strong><br>
                  À renseigner
                </div>
              </li>
              <li class="mb-3 d-flex align-items-start gap-3">
                <i class="bi bi-clock fs-4 text-primary flex-shrink-0 mt-1"></i>
                <div>
                  <strong>Horaires</strong><br>
                  Lundi – Vendredi : 9h00 – 18h00
                </div>
              </li>
            </ul>
            <hr class="my-4">
            <h5 class="mb-3">Vous êtes locataire ?</h5>
            <p class="text-muted">Accédez directement à votre espace pour déclarer une anomalie ou initier une procédure de départ.</p>
            <a href="/index.php" class="btn btn-outline-primary">Accéder à l''espace locataire</a>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body p-4">
            <h4 class="mb-4">Déposer une candidature</h4>
            <p class="text-muted">Vous souhaitez louer un logement ? Consultez nos annonces disponibles et déposez votre dossier en ligne.</p>
            <a href="/logements.php" class="btn btn-primary mb-3 w-100">
              <i class="bi bi-house me-2"></i>Voir les logements disponibles
            </a>
            <hr class="my-4">
            <h5 class="mb-3">Propriétaire ?</h5>
            <p class="text-muted">Vous souhaitez confier la gestion de votre bien ? Contactez-nous par email pour un devis personnalisé.</p>
            <a href="mailto:contact@myinvest-immobilier.com" class="btn btn-outline-secondary w-100">
              <i class="bi bi-envelope me-2"></i>Nous écrire
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>',
    'Contactez My Invest Immobilier pour toute question sur nos logements ou services de gestion locative.',
    1,
    30
),
(
    'faq',
    'Questions fréquentes (FAQ)',
    '<section class="py-5">
  <div class="container">
    <h1 class="display-5 fw-bold text-center mb-2">Questions fréquentes</h1>
    <p class="lead text-center text-muted mb-5">Retrouvez les réponses aux questions les plus courantes</p>
    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="accordion" id="faqAccordion">
          <div class="accordion-item border-0 shadow-sm mb-3 rounded-3">
            <h2 class="accordion-header">
              <button class="accordion-button rounded-3" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                Comment déposer une candidature pour un logement ?
              </button>
            </h2>
            <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
              <div class="accordion-body text-muted">
                Consultez nos annonces sur la page <a href="/logements.php">Logements</a>, choisissez le bien qui vous correspond et cliquez sur « Déposer une candidature ». Complétez le formulaire en ligne avec vos justificatifs.
              </div>
            </div>
          </div>
          <div class="accordion-item border-0 shadow-sm mb-3 rounded-3">
            <h2 class="accordion-header">
              <button class="accordion-button collapsed rounded-3" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                Comment régler mon loyer en ligne ?
              </button>
            </h2>
            <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
              <div class="accordion-body text-muted">
                Vous recevez chaque mois un lien de paiement sécurisé par email. Vous pouvez également accéder à votre <a href="/index.php">espace locataire</a> pour suivre vos paiements.
              </div>
            </div>
          </div>
          <div class="accordion-item border-0 shadow-sm mb-3 rounded-3">
            <h2 class="accordion-header">
              <button class="accordion-button collapsed rounded-3" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                Comment signaler un problème dans mon logement ?
              </button>
            </h2>
            <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
              <div class="accordion-body text-muted">
                Connectez-vous à votre <a href="/index.php">espace locataire</a> avec votre adresse email, puis sélectionnez « Déclarer une anomalie ». Décrivez le problème et joignez des photos si nécessaire.
              </div>
            </div>
          </div>
          <div class="accordion-item border-0 shadow-sm mb-3 rounded-3">
            <h2 class="accordion-header">
              <button class="accordion-button collapsed rounded-3" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                Que faire en cas de fin de bail ?
              </button>
            </h2>
            <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
              <div class="accordion-body text-muted">
                Connectez-vous à votre <a href="/index.php">espace locataire</a> et sélectionnez « Procédure de départ ». Vous serez guidé étape par étape pour planifier votre état des lieux de sortie.
              </div>
            </div>
          </div>
          <div class="accordion-item border-0 shadow-sm mb-3 rounded-3">
            <h2 class="accordion-header">
              <button class="accordion-button collapsed rounded-3" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                Où trouver mes quittances de loyer ?
              </button>
            </h2>
            <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
              <div class="accordion-body text-muted">
                Vos quittances sont envoyées automatiquement par email chaque mois. Pour tout besoin spécifique, contactez-nous à <a href="mailto:contact@myinvest-immobilier.com">contact@myinvest-immobilier.com</a>.
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>',
    'Réponses aux questions fréquentes des locataires et propriétaires de My Invest Immobilier.',
    1,
    40
)
ON DUPLICATE KEY UPDATE slug = slug;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. Éléments de menu par défaut
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO frontend_menu_items (label, url, target, icone, ordre, actif)
VALUES
    ('Accueil',            '/logements.php',        '_self', 'bi-house',           10, 1),
    ('Logements',          '/logements.php',        '_self', 'bi-building',        20, 1),
    ('Nos Services',       '/page.php?slug=services', '_self', 'bi-briefcase',     30, 1),
    ('À propos',           '/page.php?slug=a-propos', '_self', 'bi-info-circle',   40, 1),
    ('FAQ',                '/page.php?slug=faq',    '_self', 'bi-question-circle', 50, 1),
    ('Contact',            '/page.php?slug=contact','_self', 'bi-envelope',        60, 1),
    ('Espace locataire',   '/index.php',            '_self', 'bi-person-circle',   70, 1)
ON DUPLICATE KEY UPDATE label = label;
