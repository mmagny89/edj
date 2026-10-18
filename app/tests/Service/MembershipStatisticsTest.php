<?php

namespace App\Tests\Service;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Service\MembershipStatistics;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Chiffres d'une saison pour le tableau de bord.
 *
 * Ces nombres sont lus en assemblee generale : ce qui est teste ici, c'est
 * surtout ce qui ne doit PAS etre compte — un bulletin non encaisse, un
 * bulletin d'une autre saison, la commune d'un bulletin anonymise.
 */
final class MembershipStatisticsTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private const SEASON = 2031;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    private function purge(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.MembershipApplication::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.Member::class)->execute();
    }

    private function create(
        string $city,
        int $age,
        MembershipType $type = MembershipType::Individual,
        bool $validated = true,
        string $submittedAt = '2030-10-15',
        ?Member $member = null,
    ): MembershipApplication {
        $member ??= new Member('EDJ-'.random_int(100000, 999999));
        $submitted = new \DateTimeImmutable($submittedAt);

        $application = (new MembershipApplication())
            ->setLastName('Durand')
            ->setFirstName('Camille')
            ->setBirthDate($submitted->modify(sprintf('-%d years -1 day', $age)))
            ->setPostalCode('89300')
            ->setCity($city)
            ->setEmail('c-'.bin2hex(random_bytes(4)).'@example.test')
            ->setMembershipType($type)
            ->setPrincipalMemberName(MembershipType::Family === $type ? 'Louis Durand' : null)
            ->setSignaturePlace('Joigny')
            ->setSignatureName('Camille Durand')
            ->setSubmittedAt($submitted)
            ->setMember($member);

        if ($validated) {
            $application->validate(($application->amountDue() ?? 0) > 0 ? PaymentMethod::Cheque : null, null);
        }

        $this->entityManager->persist($member);
        $this->entityManager->persist($application);
        $this->entityManager->flush();

        return $application;
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        return static::getContainer()->get(MembershipStatistics::class)->forSeason(self::SEASON);
    }

    public function testOnlyValidatedApplicationsCount(): void
    {
        $this->create('Joigny', 40);
        $this->create('Joigny', 40, validated: false);

        $stats = $this->stats();

        self::assertSame(1, $stats['active']);
        self::assertSame(1, $stats['pending']);
    }

    public function testConsumersAreCountedApartFromAnnualMembers(): void
    {
        $this->create('Joigny', 40);
        $this->create('Joigny', 40, type: MembershipType::Consumer);

        $stats = $this->stats();

        self::assertSame(1, $stats['active']);
        self::assertSame(1, $stats['consumers']);
    }

    public function testAnotherSeasonIsNotCounted(): void
    {
        $this->create('Joigny', 40);
        $this->create('Joigny', 40, submittedAt: '2029-11-02');

        self::assertSame(1, $this->stats()['active']);
    }

    public function testTheGeographySplitsJoignyFromItsIntercommunality(): void
    {
        $this->create('Joigny', 40);
        $this->create('Cézy', 40);
        $this->create('CHAMVRES', 40);
        $this->create('Paris', 40);

        $geography = $this->stats()['geography'];

        self::assertSame(1, $geography['joigny']);
        self::assertSame(2, $geography['intercommunality'], 'La casse et les accents ne font pas une autre commune.');
        self::assertSame(1, $geography['outside']);
    }

    /**
     * Un bulletin anonymise n'a plus de commune : le compter « hors
     * territoire » ferait croire a un public venu de loin qui n'existe pas.
     */
    public function testAnAnonymizedBulletinHasNoCommune(): void
    {
        $this->create('Joigny', 40)->anonymize();
        $this->entityManager->flush();

        $geography = $this->stats()['geography'];

        self::assertSame(0, $geography['outside']);
        self::assertSame(1, $geography['unknown']);
    }

    public function testTheAverageAgeIsComputedAtTheStartOfTheSeason(): void
    {
        $this->create('Joigny', 30);
        $this->create('Joigny', 50);

        self::assertEqualsWithDelta(40.0, $this->stats()['averageAge'], 1.0);
    }

    public function testMinorsAreCounted(): void
    {
        $this->create('Joigny', 40);
        $this->create('Joigny', 12, type: MembershipType::Family);

        self::assertSame(1, $this->stats()['minors']);
    }

    /**
     * Une readhesion se reconnait au numero d'adherent, stable d'une saison a
     * l'autre : c'est tout ce qui distingue un nouveau d'un fidele.
     */
    public function testRenewalsAreToldApartFromNewcomers(): void
    {
        $member = new Member('EDJ-'.random_int(100000, 999999));
        $this->create('Joigny', 40, submittedAt: '2029-10-15', member: $member);
        $this->create('Joigny', 41, submittedAt: '2030-10-15', member: $member);
        $this->create('Joigny', 40);

        $stats = $this->stats();

        self::assertSame(1, $stats['renewals']);
        self::assertSame(1, $stats['newcomers']);
    }

    public function testAnEmptySeasonReportsNoAverageAge(): void
    {
        self::assertNull($this->stats()['averageAge']);
        self::assertSame(0, $this->stats()['active']);
    }

    // ------------------------------------------------------------------
    // Courbes et repartitions du tableau de bord
    // ------------------------------------------------------------------

    /**
     * Une ligne par saison, avec nouveaux, renouvellements et consommateurs. Le
     * renouvellement se reconnait au numero d'adherent, stable d'une saison a
     * l'autre.
     */
    public function testTheTrendCountsNewcomersRenewalsAndConsumersPerSeason(): void
    {
        $member = new Member('EDJ-'.random_int(100000, 999999));
        $this->create('Joigny', 40, submittedAt: '2029-10-15', member: $member);
        $this->create('Joigny', 41, submittedAt: '2030-10-15', member: $member);
        $this->create('Joigny', 30, type: MembershipType::Consumer, submittedAt: '2030-10-20');

        $trend = array_column(static::getContainer()->get(MembershipStatistics::class)->trend(), null, 'season');

        self::assertSame(['newcomers' => 1, 'renewals' => 0, 'consumers' => 0, 'active' => 1], array_intersect_key($trend[2030], array_flip(['newcomers', 'renewals', 'consumers', 'active'])));
        self::assertSame(['newcomers' => 0, 'renewals' => 1, 'consumers' => 1, 'active' => 1], array_intersect_key($trend[2031], array_flip(['newcomers', 'renewals', 'consumers', 'active'])));
    }

    /**
     * Une saison sans adhesion figure a zero : un trou dans l'axe ferait croire a
     * deux saisons voisines qui ne le sont pas.
     */
    public function testTheTrendKeepsEmptySeasonsInTheAxis(): void
    {
        $this->create('Joigny', 40, submittedAt: '2028-10-15');
        $this->create('Joigny', 40, submittedAt: '2031-10-15');

        $seasons = array_column(static::getContainer()->get(MembershipStatistics::class)->trend(), 'season');

        self::assertContains(2030, $seasons);
        self::assertContains(2031, $seasons);
        self::assertSame(range(2029, max($seasons)), $seasons);
    }

    public function testTheCumulativeCurveAddsUpMonthByMonth(): void
    {
        // Saison 2031 : octobre 2030 (2), decembre 2030 (1), mars 2031 (1).
        $this->create('Joigny', 40, submittedAt: '2030-10-02');
        $this->create('Joigny', 41, submittedAt: '2030-10-20');
        $this->create('Joigny', 42, submittedAt: '2030-12-05');
        $this->create('Joigny', 43, submittedAt: '2031-03-09');

        $curve = static::getContainer()->get(MembershipStatistics::class)->cumulative(self::SEASON);

        // Octobre, novembre, decembre, janvier, fevrier, mars, puis le reste.
        self::assertSame([2, 2, 3, 3, 3, 4, 4, 4, 4, 4, 4, 4], $curve);
    }

    /**
     * Consommateurs, bulletins en attente et saisons voisines ne gonflent pas la
     * courbe : elle compte des adherents a l'annee, valides, de la saison.
     */
    public function testTheCumulativeCurveCountsOnlyValidatedAnnualMembersOfTheSeason(): void
    {
        $this->create('Joigny', 40, submittedAt: '2030-10-02');
        $this->create('Joigny', 40, type: MembershipType::Consumer, submittedAt: '2030-10-03');
        $this->create('Joigny', 40, validated: false, submittedAt: '2030-10-04');
        $this->create('Joigny', 40, submittedAt: '2029-10-05');

        self::assertSame(1, static::getContainer()->get(MembershipStatistics::class)->cumulative(self::SEASON)[0]);
    }

    /**
     * Pour la saison en cours, les mois pas encore venus valent null : une courbe
     * qui retombe a plat ferait croire a une saison arretee.
     */
    public function testTheCurrentSeasonCurveStopsAtTheCurrentMonth(): void
    {
        $statistics = static::getContainer()->get(MembershipStatistics::class);
        $curve = $statistics->cumulative($statistics->currentSeason());

        $currentIndex = MembershipStatistics::monthIndex(new \DateTimeImmutable());
        self::assertNotNull($curve[$currentIndex]);
        if ($currentIndex < 11) {
            self::assertNull($curve[$currentIndex + 1]);
        }
    }

    public function testMonthsRunFromOctoberToSeptember(): void
    {
        self::assertSame(0, MembershipStatistics::monthIndex(new \DateTimeImmutable('2030-10-01')));
        self::assertSame(3, MembershipStatistics::monthIndex(new \DateTimeImmutable('2031-01-15')));
        self::assertSame(11, MembershipStatistics::monthIndex(new \DateTimeImmutable('2031-09-30')));
    }

    public function testAgesFallIntoTheirBands(): void
    {
        // L'age se compte au 1er octobre, quinze jours avant le depot : on evite les
        // ages pile sur une borne de tranche.
        $this->create('Joigny', 5, type: MembershipType::Family);
        $this->create('Joigny', 7, type: MembershipType::Family);
        $this->create('Joigny', 10, type: MembershipType::Family);
        $this->create('Joigny', 17, type: MembershipType::Family);
        $this->create('Joigny', 46);
        $this->create('Joigny', 80);

        $bands = $this->stats()['ageBands'];

        self::assertSame(2, $bands['under8']);
        self::assertSame(2, $bands['minor']);
        self::assertSame(1, $bands['mature']);
        self::assertSame(1, $bands['senior']);
    }

    /**
     * « Gratuit » : rien n'etait du — un enfant de moins de huit ans, ou la formule
     * consommateur. Ce n'est pas un moyen de reglement, c'est l'absence de
     * reglement, et il se compte a part.
     */
    public function testPaymentsAreSplitByMethodAndFree(): void
    {
        $this->create('Joigny', 40);
        $this->create('Joigny', 40, type: MembershipType::Consumer);
        $this->create('Joigny', 5, type: MembershipType::Family);

        $payments = $this->stats()['payments'];

        self::assertSame(1, $payments['cheque']);
        self::assertSame(2, $payments['gratuit']);
    }

    public function testTheCachedReadIsDroppedOnReset(): void
    {
        $statistics = static::getContainer()->get(MembershipStatistics::class);
        $this->create('Joigny', 40);
        self::assertSame(1, $statistics->cumulative(self::SEASON)[0]);

        $this->create('Joigny', 41);
        self::assertSame(1, $statistics->cumulative(self::SEASON)[0], 'La lecture est gardee en memoire tant qu\'on ne la vide pas.');

        $statistics->reset();
        self::assertSame(2, $statistics->cumulative(self::SEASON)[0]);
    }
}
