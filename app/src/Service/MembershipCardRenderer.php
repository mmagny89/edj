<?php

namespace App\Service;

use App\Entity\MembershipApplication;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Carte d'adherent dematerialisee, au format d'une carte bancaire, en deux
 * pages : le recto a la marque de l'association, le verso le nom, le numero et
 * les saisons.
 *
 * Dompdf plutot qu'une page a imprimer : le rendu est le meme partout, et le
 * fichier se garde dans un telephone. Le moteur tourne sans acces reseau
 * (isRemoteEnabled a faux) et ne lit que le dossier public : un gabarit ne
 * peut donc pas lui faire ouvrir un fichier de configuration ni appeler une
 * adresse exterieure.
 */
final class MembershipCardRenderer
{
    /** Format d'une carte bancaire (ISO/IEC 7810 ID-1), en points PostScript. */
    private const SIZE = [0.0, 0.0, 242.65, 153.0];

    public function __construct(
        private readonly Environment $twig,
        private readonly MembershipSeasons $seasons,
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
        // Dompdf ecrit un fichier de travail par police declaree en
        // @font-face. Il le ferait par defaut dans vendor/, que l'application
        // n'a pas a modifier en production : le cache de Symfony est le seul
        // dossier qui lui appartienne et survive au demarrage.
        #[Autowire('%kernel.cache_dir%/dompdf')]
        private readonly string $fontCacheDir,
    ) {
    }

    public function render(MembershipApplication $application): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setChroot($this->publicDir);
        $options->setDefaultFont('DejaVu Sans');
        // Les polices livrees avec Dompdf viennent de rootDir et ne dependent
        // pas de ce reglage : deplacer fontDir n'enleve donc pas DejaVu.
        if (!is_dir($this->fontCacheDir)) {
            mkdir($this->fontCacheDir, 0o775, true);
        }
        $options->setFontDir($this->fontCacheDir);
        $options->setFontCache($this->fontCacheDir);

        $dompdf = new Dompdf($options);
        $dompdf->setBasePath($this->publicDir);
        $dompdf->setPaper(self::SIZE);
        $dompdf->loadHtml($this->twig->render('pdf/membership_card.html.twig', [
            'application' => $application,
            'seasons' => $this->seasons->forApplication($application),
            'logo' => $this->publicDir.'/img/edj-mascotte-transparent.png',
            'wordmark' => $this->publicDir.'/img/edj-mot-carte.png',
            // Chemin relatif au « base path » : une URL absolue de systeme de
            // fichiers dans un @font-face n'est pas resolue par Dompdf, qui
            // retombe alors en silence sur la police par defaut.
            'displayFont' => 'fonts/akaya-kanadaka.ttf',
        ]), 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Nom du fichier telecharge : le numero suffit a le distinguer, et aucun
     * nom de personne ne se retrouve dans un dossier de telechargements.
     */
    public function filename(MembershipApplication $application): string
    {
        return sprintf('carte-adherent-%s.pdf', $application->getMemberNumber() ?? 'edj');
    }

}
