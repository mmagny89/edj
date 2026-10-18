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

    /** Nombre de saisons imprimees au verso, comme sur la carte papier. */
    private const SEASONS = 8;

    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
    ) {
    }

    public function render(MembershipApplication $application): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setChroot($this->publicDir);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setBasePath($this->publicDir);
        $dompdf->setPaper(self::SIZE);
        $dompdf->loadHtml($this->twig->render('pdf/membership_card.html.twig', [
            'application' => $application,
            'seasons' => $this->seasons($application),
            'logo' => $this->publicDir.'/img/edj-mascotte-transparent.png',
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

    /**
     * Les huit saisons du verso, a partir de la premiere adhesion connue de la
     * personne. Une saison est cochee quand un bulletin de cette annee-la est
     * valide : une adhesion en attente ou annulee ne coche rien.
     *
     * La saison associative court du 1er octobre au 30 septembre suivant : un
     * bulletin depose en novembre 2026 ouvre la saison 2026, un bulletin de
     * mars 2026 appartient encore a la saison 2025. Compter en annees civiles
     * ferait changer de case tous les bulletins de janvier a septembre.
     *
     * @return list<array{year: int, valid: bool}>
     */
    private function seasons(MembershipApplication $application): array
    {
        $validated = [];
        $first = self::season($application->getSubmittedAt());

        foreach ($application->getMember()?->getApplications() ?? [] as $other) {
            $year = self::season($other->getSubmittedAt());
            $first = min($first, $year);
            if ($other->isValidated()) {
                $validated[$year] = true;
            }
        }

        $seasons = [];
        for ($i = 0; $i < self::SEASONS; ++$i) {
            $year = $first + $i;
            $seasons[] = ['year' => $year, 'valid' => isset($validated[$year])];
        }

        return $seasons;
    }

    private static function season(\DateTimeImmutable $date): int
    {
        $year = (int) $date->format('Y');

        return (int) $date->format('n') >= 10 ? $year : $year - 1;
    }
}
