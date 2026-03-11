-- Migration 107: Page d'accueil par défaut avec slider Bootstrap
-- Date: 2026-03-11
-- Description:
--   Insère une page d'accueil par défaut (is_homepage = 1) si aucune page d'accueil
--   n'est encore définie. La page inclut un slider Bootstrap Carousel et un bloc
--   de contenu introductif.

INSERT INTO frontend_pages (slug, titre, contenu_html, meta_description, actif, ordre, is_homepage)
SELECT
    'accueil',
    'Accueil',
    '<div id="heroCarousel" class="carousel slide" data-bs-ride="carousel">
  <div class="carousel-indicators">
    <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="0" class="active"></button>
    <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="1"></button>
    <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="2"></button>
  </div>
  <div class="carousel-inner">
    <div class="carousel-item active">
      <img src="https://placehold.co/1920x600/2c3e50/ffffff?text=Bienvenue" class="d-block w-100" alt="Slide 1" style="object-fit:cover;height:500px;">
      <div class="carousel-caption d-none d-md-block">
        <h2 class="fw-bold display-5">Bienvenue</h2>
        <p class="lead">Votre gestionnaire immobilier de confiance</p>
      </div>
    </div>
    <div class="carousel-item">
      <img src="https://placehold.co/1920x600/3498db/ffffff?text=Nos+Services" class="d-block w-100" alt="Slide 2" style="object-fit:cover;height:500px;">
      <div class="carousel-caption d-none d-md-block">
        <h2 class="fw-bold display-5">Nos Services</h2>
        <p class="lead">Gestion locative complète et transparente</p>
      </div>
    </div>
    <div class="carousel-item">
      <img src="https://placehold.co/1920x600/27ae60/ffffff?text=Nous+Contacter" class="d-block w-100" alt="Slide 3" style="object-fit:cover;height:500px;">
      <div class="carousel-caption d-none d-md-block">
        <h2 class="fw-bold display-5">Contactez-nous</h2>
        <p class="lead">Une équipe disponible pour vous accompagner</p>
      </div>
    </div>
  </div>
  <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev">
    <span class="carousel-control-prev-icon"></span>
  </button>
  <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next">
    <span class="carousel-control-next-icon"></span>
  </button>
</div>

<section class="py-5 bg-white">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <h2 class="display-6 fw-bold mb-4">Votre gestionnaire immobilier de confiance</h2>
        <p class="lead mb-4">Nous accompagnons propriétaires et locataires avec expertise et transparence pour une gestion locative sereine.</p>
        <ul class="list-unstyled mb-4">
          <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>Gestion locative complète</li>
          <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>Suivi des paiements en ligne</li>
          <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>États des lieux numériques</li>
          <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>Réactivité et disponibilité</li>
        </ul>
        <a href="/logements.php" class="btn btn-primary btn-lg px-4">Voir nos logements</a>
      </div>
      <div class="col-lg-6 text-center">
        <img src="https://placehold.co/540x400/eef4fb/3498db?text=Gestion+Immobilière" class="img-fluid rounded-4 shadow" alt="Gestion immobilière">
      </div>
    </div>
  </div>
</section>

<section class="py-5" style="background:#f0f4f8;">
  <div class="container">
    <h2 class="text-center fw-bold mb-5">Nos points forts</h2>
    <div class="row g-4">
      <div class="col-md-4 text-center">
        <div class="bg-white rounded-4 p-4 shadow-sm h-100">
          <i class="bi bi-shield-check fs-1 text-primary mb-3 d-block"></i>
          <h4>Fiabilité</h4>
          <p class="text-muted">Gestion rigoureuse de vos biens immobiliers avec un suivi complet.</p>
        </div>
      </div>
      <div class="col-md-4 text-center">
        <div class="bg-white rounded-4 p-4 shadow-sm h-100">
          <i class="bi bi-clock-history fs-1 text-primary mb-3 d-block"></i>
          <h4>Réactivité</h4>
          <p class="text-muted">Une équipe disponible pour répondre rapidement à vos besoins.</p>
        </div>
      </div>
      <div class="col-md-4 text-center">
        <div class="bg-white rounded-4 p-4 shadow-sm h-100">
          <i class="bi bi-graph-up-arrow fs-1 text-primary mb-3 d-block"></i>
          <h4>Performance</h4>
          <p class="text-muted">Optimisation de la rentabilité de vos investissements immobiliers.</p>
        </div>
      </div>
    </div>
  </div>
</section>',
    'Bienvenue sur notre site de gestion locative — logements meublés, suivi des paiements, états des lieux.',
    1,
    0,
    1
WHERE NOT EXISTS (
    SELECT 1 FROM frontend_pages WHERE is_homepage = 1
);
