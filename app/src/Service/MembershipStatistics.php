<?php

namespace App\Service;

use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Repository\MembershipApplicationRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffres d'une saison, pour le tableau de bord : qui adhere, d'ou vient le
 * public, et quel age il a.
 *
 * Tout se compte sur les bulletins **valides** de la saison : un bulletin
 * depose mais non encaisse n'est pas une adhesion, et le dire reviendrait a
 * annoncer au conseil d'administration des effectifs qu'il n'a pas.
 *
 * Deux limites a connaitre, dites a l'ecran plutot que tues :
 *  - un bulletin anonymise ne porte plus ni ville ni commune : il compte dans
 *    les effectifs, jamais dans la repartition geographique ;
 *  - l'age se calcule sur une date de naissance ramenee au 1er janvier pour
 *    les bulletins anonymises, donc a un an pres pour ceux-la.
 */
final class MembershipStatistics
{
    /**
     * @param list<string> $intercommunality
     */
    public function __construct(
        private readonly MembershipApplicationRepository $applications,
        private readonly MembershipSeasons $seasons,
        #[Autowire('%app.territory.joigny%')]
        private readonly string $joigny,
        #[Autowire('%app.territory.intercommunality%')]
        private readonly array $intercommunality,
    ) {
    }

    /**
     * @return array{
     *     season: int,
     *     active: int,
     *     consumers: int,
     *     pending: int,
     *     cancelled: int,
     *     renewals: int,
     *     newcomers: int,
     *     minors: int,
     *     averageAge: ?float,
     *     imageRightsRefused: int,
     *     philibertConsent: int,
     *     geography: array{joigny: int, intercommunality: int, outside: int, unknown: int},
     *     byType: array<string, int>
     * }
     */
    public function forSeason(int $season): array
    {
        $all = $this->applications->findFiltered(['season' => $season], limit: 10000);

        $validated = array_values(array_filter($all, static fn (MembershipApplication $a) => $a->isValidated()));
        $earlierSeasons = $this->earlierValidatedSeasons($validated, $season);
        $ages = [];
        $geography = ['joigny' => 0, 'intercommunality' => 0, 'outside' => 0, 'unknown' => 0];
        $byType = [];
        $counters = ['consumers' => 0, 'renewals' => 0, 'minors' => 0, 'imageRightsRefused' => 0, 'philibertConsent' => 0];

        foreach ($validated as $application) {
            $type = $application->getMembershipType();
            if (null !== $type) {
                $byType[$type->value] = ($byType[$type->value] ?? 0) + 1;
            }
            if (MembershipType::Consumer === $type) {
                ++$counters['consumers'];
            }

            $counters['minors'] += $application->isMinor($this->seasonStart($season)) ? 1 : 0;
            $counters['imageRightsRefused'] += $application->isImageRightsRefused() ? 1 : 0;
            $counters['philibertConsent'] += $application->isPhilibertConsent() ? 1 : 0;
            $counters['renewals'] += isset($earlierSeasons[$application->getMember()?->getId()]) ? 1 : 0;

            ++$geography[$this->territoryOf($application)];

            if (null !== $age = $this->ageAt($application, $season)) {
                $ages[] = $age;
            }
        }

        return [
            'season' => $season,
            // « Actif » au sens de l'association : une adhesion annuelle
            // reglee. Le consommateur paie a l'evenement, il est compte a part.
            'active' => \count($validated) - $counters['consumers'],
            'consumers' => $counters['consumers'],
            'pending' => \count(array_filter($all, static fn (MembershipApplication $a) => $a->isPending())),
            'cancelled' => \count(array_filter($all, static fn (MembershipApplication $a) => $a->isCancelled())),
            'renewals' => $counters['renewals'],
            'newcomers' => \count($validated) - $counters['renewals'],
            'minors' => $counters['minors'],
            'averageAge' => [] !== $ages ? round(array_sum($ages) / \count($ages), 1) : null,
            'imageRightsRefused' => $counters['imageRightsRefused'],
            'philibertConsent' => $counters['philibertConsent'],
            'geography' => $geography,
            'byType' => $byType,
        ];
    }

    /**
     * La saison en cours, celle que le tableau de bord ouvre par defaut.
     */
    public function currentSeason(): int
    {
        return MembershipSeasons::of(new \DateTimeImmutable());
    }

    /**
     * @return list<int>
     */
    public function availableSeasons(): array
    {
        $seasons = $this->applications->seasonsWithApplications();
        $current = $this->currentSeason();

        if (!\in_array($current, $seasons, true)) {
            array_unshift($seasons, $current);
        }

        return $seasons;
    }

    /**
     * Joigny, le reste de la communaute de communes, ou ailleurs. Un bulletin
     * anonymise n'a plus de ville : il va dans « non renseigne » plutot que
     * de grossir a tort le « hors territoire ».
     */
    private function territoryOf(MembershipApplication $application): string
    {
        if ($application->isAnonymized()) {
            return 'unknown';
        }

        $city = MemberDirectory::normalize((string) $application->getCity());
        if ('' === $city) {
            return 'unknown';
        }

        if ($city === MemberDirectory::normalize($this->joigny)) {
            return 'joigny';
        }

        foreach ($this->intercommunality as $commune) {
            if ($city === MemberDirectory::normalize((string) $commune)) {
                return 'intercommunality';
            }
        }

        return 'outside';
    }

    /**
     * L'age au 1er octobre, debut de la saison : un age « aujourd'hui »
     * changerait d'un jour a l'autre, et les chiffres d'une saison close ne
     * doivent plus bouger.
     */
    private function ageAt(MembershipApplication $application, int $season): ?int
    {
        $birth = $application->getBirthDate();

        return null !== $birth ? $birth->diff($this->seasonStart($season))->y : null;
    }

    private function seasonStart(int $season): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%d-10-01', $season - 1));
    }

    /**
     * Les adherents qui avaient deja une adhesion validee avant cette saison :
     * ce sont les renouvellements, les autres sont des nouveaux. C'est le
     * numero d'adherent, stable d'une saison a l'autre, qui les relie.
     *
     * La question se pose en une requete plutot qu'en parcourant la collection
     * de chaque adherent : celle-ci reste vide pour un adherent cree dans la
     * meme unite de travail, et tous les renouvellements passaient alors pour
     * des nouveaux.
     *
     * @param list<MembershipApplication> $applications
     *
     * @return array<int, true>
     */
    private function earlierValidatedSeasons(array $applications, int $season): array
    {
        $members = [];
        foreach ($applications as $application) {
            if (null !== $id = $application->getMember()?->getId()) {
                $members[$id] = $application->getMember();
            }
        }

        if ([] === $members) {
            return [];
        }

        $earlier = [];
        foreach ($this->applications->findValidatedForMembers(array_values($members)) as $other) {
            $id = $other->getMember()?->getId();
            if (null !== $id && MembershipSeasons::of($other->getSubmittedAt()) < $season) {
                $earlier[$id] = true;
            }
        }

        return $earlier;
    }
}
