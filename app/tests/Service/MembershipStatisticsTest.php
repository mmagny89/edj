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
}
