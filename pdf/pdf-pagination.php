<?php
/**
 * Classe MIIPdf - Extension TCPDF avec pied de page paginé
 * My Invest Immobilier
 *
 * Utilisée par tous les générateurs de PDF du projet.
 */

class MIIPdf extends TCPDF {

    /**
     * Pied de page : numéro de page + coordonnées société
     */
    public function Footer() {
        // Position à 15 mm du bas de la page
        $this->SetY(-15);

        // Police : Helvetica Italic 8pt, couleur grise
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(128, 128, 128);

        // Ligne 1 : numérotation des pages
        $this->Cell(0, 5, 'Page ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 1, 'C');

        // Ligne 2 : coordonnées société
        $this->Cell(0, 5, 'MY INVEST IMMOBILIER - contact@myinvest-immobilier.com', 0, 0, 'C');
    }
}
