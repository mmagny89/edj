<?php

namespace App\Controller\app;

use App\Entity\EventAttendance;
use App\Entity\MembershipApplication;
use App\Entity\User;
use App\Service\Accounting;
use App\Service\Day;
use App\Service\MembershipStatistics;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La section comptable : ce qui est entre en caisse sur une saison, d'ou ca
 * vient, et ce qui reste a encaisser.
 *
 * Elle ne tient pas la comptabilite de l'association — elle lui donne la
 * matiere : le detail des cotisations et des euros de seance, deja totalise
 * par moyen de reglement, et le meme detail en CSV pour le tableur ou se
 * fait le reste.
 */
#[Route('/admin/comptabilite')]
#[IsGranted('ROLE_CONTRIBUTOR')]
final class AccountingController extends AbstractController
{
    public function __construct(
        private readonly Accounting $accounting,
        private readonly MembershipStatistics $statistics,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[Route('', name: 'app_accounting', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $season = $this->season($request);
        $entries = $this->accounting->entries($season);

        return $this->render('app/accounting/index.html.twig', [
            'seasons' => $this->statistics->availableSeasons(),
            'season' => $season,
            'entries' => $entries,
            'summary' => $this->accounting->summary($season, $entries),
            'unpaid' => $this->accounting->unpaid($season, $entries),
        ]);
    }

    /**
     * Le meme journal, en CSV.
     *
     * Separateur point-virgule et BOM UTF-8 : c'est ce qu'attend un tableur
     * francophone, qui sans eux ouvre tout sur une colonne et mange les
     * accents. Montants en euros avec une virgule decimale, pour la meme
     * raison.
     */
    /**
     * Pointe la date de depot (cheque, especes) ou de paiement (virement) d'une
     * recette, depuis le journal ou depuis la fiche. Un champ vide l'efface.
     */
    #[Route('/encaissement/{kind}/{id}', name: 'app_accounting_cashing', methods: ['POST'], requirements: ['kind' => 'cotisation|passage', 'id' => '\d+'])]
    public function cashing(Request $request, string $kind, int $id): Response
    {
        $line = 'passage' === $kind
            ? $this->entityManager->find(EventAttendance::class, $id)
            : $this->entityManager->find(MembershipApplication::class, $id);
        if (null === $line) {
            throw $this->createNotFoundException('Recette introuvable.');
        }

        // Vide quand le journal est affiche sans parametre : getInt() le refuserait.
        $season = (int) $request->request->get('saison');
        $back = 'fiche' === $request->request->get('retour') && $line instanceof MembershipApplication
            ? $this->redirectToRoute('app_membership_show', ['id' => $id], Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('app_accounting', $season > 0 ? ['saison' => $season] : [], Response::HTTP_SEE_OTHER);

        if (!$this->isCsrfTokenValid('cashing-'.$kind.'-'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, recommencez.');

            return $back;
        }

        $value = trim((string) $request->request->get('date'));
        $on = Day::parse($value);
        if ('' !== $value && (null === $on || $on > Day::today())) {
            $this->addFlash('error', 'La date de dépôt ou de paiement n\'est pas valide, ou se situe dans l\'avenir.');

            return $back;
        }

        $cashable = $line instanceof EventAttendance ? $line->isPaid() : null !== $line->getPaymentMethod();
        if (!$cashable) {
            $this->addFlash('error', 'Aucun règlement n\'est enregistré sur cette ligne.');

            return $back;
        }

        if ($line instanceof EventAttendance) {
            $line->recordCashing($on);
        } else {
            $user = $this->getUser();
            $line->recordCashing($on, $user instanceof User ? $user->getId() : null);
        }
        $this->entityManager->flush();

        $this->securityLogger->info('Encaissement pointe', [
            'event' => 'accounting_cashing',
            'user' => $this->getUser()?->getUserIdentifier(),
            'kind' => $kind,
            'id' => $id,
            'date' => $on?->format('Y-m-d'),
        ]);

        $this->addFlash('success', null === $on
            ? 'Date de dépôt / de paiement effacée.'
            : sprintf('Date de dépôt / de paiement enregistrée : %s.', $on->format('d/m/Y')));

        return $back;
    }

    #[Route('/export.csv', name: 'app_accounting_export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse
    {
        $season = $this->season($request);
        $entries = $this->accounting->entries($season);

        $this->securityLogger->info('Export comptable', [
            'event' => 'accounting_export',
            'user' => $this->getUser()?->getUserIdentifier(),
            'season' => $season,
            'lines' => \count($entries),
        ]);

        $response = new StreamedResponse(static function () use ($entries): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Date', 'Nature', 'Libellé', 'Personne', 'Numéro', 'Règlement', 'Montant (€)', 'Déduit (€)', 'Encaissé', 'Dépôt / paiement'], ';', '"', '');

            foreach ($entries as $entry) {
                fputcsv($out, [
                    Day::local($entry['date'])->format('d/m/Y'),
                    'passage' === $entry['kind'] ? 'Passage' : 'Cotisation',
                    $entry['label'],
                    $entry['person'],
                    $entry['number'] ?? '',
                    $entry['method']?->label() ?? '',
                    number_format($entry['cents'] / 100, 2, ',', ''),
                    number_format($entry['creditCents'] / 100, 2, ',', ''),
                    $entry['paid'] ? 'oui' : 'non',
                    $entry['cashedOn']?->format('d/m/Y') ?? '',
                ], ';', '"', '');
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('comptabilite-%d.csv', $season),
        ));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function season(Request $request): int
    {
        $seasons = $this->statistics->availableSeasons();
        $season = $request->query->getInt('saison') ?: $this->statistics->currentSeason();

        return \in_array($season, $seasons, true) ? $season : $this->statistics->currentSeason();
    }
}
