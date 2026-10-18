<?php

namespace App\Service;

use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Repository\MembershipApplicationRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

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
final class MembershipStatistics implements ResetInterface
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
        $ageBands = array_fill_keys(array_keys(self::AGE_BANDS), 0);
        $payments = ['cheque' => 0, 'especes' => 0, 'virement' => 0, 'gratuit' => 0];
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
            // Hors consommateurs : la formule n'ouvre pas droit a l'avantage, et
            // l'export Philibert ne les compte pas non plus.
            $counters['philibertConsent'] += $application->isPhilibertConsent() && MembershipType::Consumer !== $type ? 1 : 0;
            $counters['renewals'] += isset($earlierSeasons[$application->getMember()?->getId()]) ? 1 : 0;

            ++$geography[$this->territoryOf($application)];

            if (null !== $age = $this->ageAt($application, $season)) {
                $ages[] = $age;
                ++$ageBands[$this->ageBand($age)];
            }

            // « Gratuit » : rien n'a ete encaisse parce que rien n'etait du — un
            // enfant de moins de huit ans, ou la formule consommateur.
            ++$payments[$application->getPaymentMethod()?->value ?? 'gratuit'];
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
            'ageBands' => $ageBands,
            'payments' => $payments,
        ];
    }

    /**
     * Tranches d'age du tableau de bord : borne haute incluse, libelle affiche.
     * Les moins de huit ans ont leur tranche — ce sont ceux dont l'adhesion est
     * gratuite — puis les mineurs, puis les adultes par pas de quinze ans.
     */
    public const AGE_BANDS = [
        'under8' => ['label' => '0–7 ans', 'max' => 7],
        'minor' => ['label' => '8–17 ans', 'max' => 17],
        'young' => ['label' => '18–29 ans', 'max' => 29],
        'adult' => ['label' => '30–44 ans', 'max' => 44],
        'mature' => ['label' => '45–59 ans', 'max' => 59],
        'senior' => ['label' => '60 ans et +', 'max' => \PHP_INT_MAX],
    ];

    /**
     * Une ligne par saison, de la premiere a la saison en cours : combien
     * d'adherents a l'annee, nouveaux ou renouvelant, et combien de
     * consommateurs. C'est l'histoire que la saison choisie vient s'inscrire
     * dedans.
     *
     * Un renouvellement est un adherent qui avait deja une adhesion validee
     * lors d'une saison anterieure — le numero, stable, les relie. Une seule
     * lecture de la base : tout se compte en memoire.
     *
     * @return list<array{season: int, newcomers: int, renewals: int, consumers: int, active: int}>
     */
    public function trend(): array
    {
        $all = $this->validatedApplications();
        if ([] === $all) {
            return [];
        }

        $firstSeasonOf = [];
        foreach ($all as $a) {
            $key = $a->getMember()?->getId();
            if (null !== $key) {
                $firstSeasonOf[$key] = min($firstSeasonOf[$key] ?? \PHP_INT_MAX, MembershipSeasons::of($a->getSubmittedAt()));
            }
        }

        $rows = [];
        foreach ($all as $a) {
            $season = MembershipSeasons::of($a->getSubmittedAt());
            $rows[$season] ??= ['season' => $season, 'newcomers' => 0, 'renewals' => 0, 'consumers' => 0, 'active' => 0];

            if (MembershipType::Consumer === $a->getMembershipType()) {
                ++$rows[$season]['consumers'];

                continue;
            }

            ++$rows[$season]['active'];
            $renewal = null !== ($key = $a->getMember()?->getId()) && $firstSeasonOf[$key] < $season;
            ++$rows[$season][$renewal ? 'renewals' : 'newcomers'];
        }

        // Les saisons sans aucune adhesion figurent aussi, a zero : un trou dans
        // l'axe ferait croire a deux saisons voisines qui ne le sont pas.
        $current = $this->currentSeason();
        $filled = [];
        for ($season = min(array_keys($rows)); $season <= max($current, max(array_keys($rows))); ++$season) {
            $filled[] = $rows[$season] ?? ['season' => $season, 'newcomers' => 0, 'renewals' => 0, 'consumers' => 0, 'active' => 0];
        }

        return $filled;
    }

    /**
     * Adherents a l'annee cumules mois par mois sur une saison, d'octobre a
     * septembre : la courbe qui dit si la saison demarre plus fort ou plus
     * doucement que la precedente.
     *
     * Pour la saison en cours, les mois qui ne sont pas encore venus valent
     * null : une courbe qui retombe a plat ferait croire a une saison arretee.
     *
     * @return list<?int> douze valeurs, octobre en premier
     */
    public function cumulative(int $season): array
    {
        $perMonth = array_fill(0, 12, 0);
        foreach ($this->validatedApplications() as $a) {
            if (MembershipType::Consumer === $a->getMembershipType() || MembershipSeasons::of($a->getSubmittedAt()) !== $season) {
                continue;
            }
            ++$perMonth[self::monthIndex($a->getSubmittedAt())];
        }

        $lastKnown = $season === $this->currentSeason() ? self::monthIndex(new \DateTimeImmutable()) : 11;
        $running = 0;
        $values = [];
        foreach ($perMonth as $index => $count) {
            $running += $count;
            $values[] = $index <= $lastKnown ? $running : null;
        }

        return $values;
    }

    /**
     * Rang du mois dans la saison : octobre 0 ... septembre 11.
     */
    public static function monthIndex(\DateTimeImmutable $date): int
    {
        return ((int) $date->format('n') + 2) % 12;
    }

    /**
     * @var list<MembershipApplication>|null
     */
    private ?array $validatedCache = null;

    /**
     * @return list<MembershipApplication>
     */
    private function validatedApplications(): array
    {
        return $this->validatedCache ??= $this->applications->findAllValidated();
    }

    /**
     * Vide la lecture gardee en memoire. En mode worker le conteneur vit autant
     * que le worker : sans cela, le tableau de bord d'une requete montrerait les
     * adhesions de la precedente, ou celles d'avant la derniere validation.
     */
    public function reset(): void
    {
        $this->validatedCache = null;
    }

    private function ageBand(int $age): string
    {
        foreach (self::AGE_BANDS as $key => $band) {
            if ($age <= $band['max']) {
                return $key;
            }
        }

        return array_key_last(self::AGE_BANDS);
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
