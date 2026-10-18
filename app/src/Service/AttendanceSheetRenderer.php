<?php

namespace App\Service;

use App\Entity\MembershipApplication;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Feuille d'emargement d'une assemblee generale : une ligne par adherent a jour,
 * une case de signature, une case de pouvoir.
 *
 * Meme moteur et memes precautions que la carte d'adherent : aucun acces reseau, et
 * un dossier de travail pour les polices qui n'est pas vendor/.
 */
final class AttendanceSheetRenderer
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
        #[Autowire('%kernel.cache_dir%/dompdf')]
        private readonly string $fontCacheDir,
    ) {
    }

    /**
     * @param list<MembershipApplication> $members
     */
    public function render(string $title, \DateTimeImmutable $date, string $place, int $season, array $members): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setChroot($this->publicDir);
        $options->setDefaultFont('DejaVu Sans');
        if (!is_dir($this->fontCacheDir)) {
            mkdir($this->fontCacheDir, 0o775, true);
        }
        $options->setFontDir($this->fontCacheDir);
        $options->setFontCache($this->fontCacheDir);

        $dompdf = new Dompdf($options);
        $dompdf->setBasePath($this->publicDir);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($this->twig->render('pdf/attendance_sheet.html.twig', [
            'title' => $title,
            'date' => $date,
            'place' => $place,
            'season' => $season,
            'members' => $members,
            // Quelques lignes vierges : une personne adhere parfois le jour meme.
            'blankRows' => 5,
        ]), 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
