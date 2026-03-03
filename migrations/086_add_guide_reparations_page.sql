-- Migration 086: Paramètres de la page "Guide des réparations locatives"
-- Date: 2026-03-02
-- Description:
--   Ajoute les paramètres nécessaires pour la page Guide des réparations locatives :
--   1. guide_reparations_slug    : slug URL configurable (ex: guide-reparations-locatives)
--   2. guide_reparations_contenu : contenu HTML de la page (éditable via l'interface admin)

INSERT INTO parametres (cle, valeur, type, description, groupe) VALUES
('guide_reparations_slug', 'guide-reparations-locatives', 'string', 'Slug URL de la page Guide des réparations locatives', 'signalement')
ON DUPLICATE KEY UPDATE cle = cle;

SET @guide_contenu = '<h1>Guide des réparations locatives</h1>
<p><em>(Logements meublés T1 bis &amp; T2)</em></p>
<p>Ce guide a pour objectif de clarifier la répartition des responsabilités entre locataire et propriétaire conformément à la réglementation en vigueur.</p>
<hr>
<h2>1. Entretien courant à la charge du locataire</h2>
<p>Relèvent de l&#39;entretien courant et de l&#39;usage normal du logement :</p>
<h3>Électricité</h3>
<ul>
<li>Remplacement d&#39;ampoules</li>
<li>Remplacement de piles (télécommande, thermostat&hellip;)</li>
<li>Réarmement d&#39;un disjoncteur</li>
<li>Serrage d&#39;une prise desserrée</li>
<li>Nettoyage de luminaires</li>
</ul>
<p><strong>Attention :</strong> Une panne électrique générale ou une installation défectueuse relève du propriétaire.</p>
<hr>
<h3>Plomberie</h3>
<ul>
<li>Débouchage simple (évier, lavabo, douche)</li>
<li>Nettoyage de siphon</li>
<li>Remplacement de joint d&#39;usage</li>
<li>Nettoyage mousseur de robinet</li>
<li>Entretien courant de la robinetterie</li>
</ul>
<p><strong>Attention :</strong> Une fuite sur canalisation encastrée ou défaut structurel relève du propriétaire.</p>
<hr>
<h3>Serrurerie / Menuiserie</h3>
<ul>
<li>Graissage de serrure</li>
<li>Réglage d&#39;une poignée</li>
<li>Remplacement d&#39;une clé perdue</li>
<li>Ajustement léger d&#39;une porte</li>
</ul>
<p><strong>Attention :</strong> Serrure défectueuse hors usure normale &rarr; propriétaire.</p>
<hr>
<h3>Machine à laver séchante</h3>
<ul>
<li>Nettoyage filtre</li>
<li>Nettoyage bac lessive</li>
<li>Nettoyage joint tambour</li>
<li>Vérification surcharge</li>
<li>Nettoyage condenseur (fonction séchage)</li>
</ul>
<p><strong>Attention :</strong> Panne électronique ou moteur &rarr; propriétaire.</p>
<hr>
<h3>Mobilier fourni (logement meublé)</h3>
<ul>
<li>Serrage de vis</li>
<li>Réglage d&#39;éléments desserrés</li>
<li>Entretien courant des surfaces</li>
<li>Nettoyage canapé / matelas</li>
</ul>
<p><strong>Attention :</strong> Casse, dégradation ou tache importante non liée à l&#39;usure normale &rarr; locataire.</p>
<hr>
<h3>Chauffage</h3>
<ul>
<li>Purge radiateur</li>
<li>Réglage thermostat</li>
<li>Remplacement piles thermostat</li>
</ul>
<p><strong>Attention :</strong> Panne chaudière ou radiateur hors usage normal &rarr; propriétaire.</p>
<hr>
<h2>2. Entretien général obligatoire</h2>
<p>Le locataire doit assurer :</p>
<ul>
<li>Aération quotidienne</li>
<li>Prévention humidité et moisissures</li>
<li>Nettoyage régulier</li>
<li>Détartrage équipements</li>
<li>Maintien en bon état des joints</li>
</ul>
<p>Un défaut d&#39;entretien pouvant entraîner une dégradation engage la responsabilité du locataire.</p>
<hr>
<h2>3. Relèvent du propriétaire</h2>
<ul>
<li>Problème structurel</li>
<li>Canalisation encastrée</li>
<li>Défaut électrique général</li>
<li>Panne d&#39;équipement hors mauvaise utilisation</li>
<li>Usure normale avérée</li>
</ul>
<hr>
<h2>4. Intervention technique</h2>
<p>Si l&#39;intervention ne relève pas de la responsabilité du propriétaire, une facturation distincte pourra être établie :</p>
<ul>
<li>80 &euro; TTC déplacement + diagnostic (1h incluse)</li>
<li>60 &euro; TTC heure supplémentaire entamée</li>
<li>Pièces facturées au coût réel</li>
</ul>
<hr>
<h2>En cas de doute</h2>
<p>Vous pouvez effectuer une déclaration avec photos et description détaillée.</p>
<p>Une analyse sera effectuée avant toute intervention.</p>';

INSERT INTO parametres (cle, valeur, type, description, groupe) VALUES
('guide_reparations_contenu', @guide_contenu, 'string', 'Contenu HTML de la page Guide des réparations locatives', 'signalement')
ON DUPLICATE KEY UPDATE cle = cle;
