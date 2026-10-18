<?php

namespace App\Tests\Service;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Entity\SeasonStamp;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Enum\StampColor;
use App\Enum\StampSymbol;
use App\Service\MembershipSeasons;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Saison d'un bulletin, et tampon appose sur la carte.
 *
 * L'annee associative court du 1er octobre au 30 septembre et porte le
 * millesime de l'annee qu'elle **couvre** : une adhesion prise le 1er octobre
 * 2026 vaut pour la saison 2027. C'est contre-intuitif — on adhere en 2026 et
 * la carte dit 2027 — donc teste.
 */
final class SeasonStampTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function seasons(): iterable
    {
        yield '1er octobre : la saison suivante' => ['2026-10-01', 2027];
        yield 'decembre : la saison suivante' => ['2026-12-20', 2027];
        yield '30 septembre : la saison en cours' => ['2026-09-30', 2026];
        yield 'mars : la saison en cours' => ['2027-03-15', 2027];
    }

    #[DataProvider('seasons')]
    public function testTheSeasonIsTheYearItCovers(string $submittedAt, int $expected): void
    {
        self::assertSame($expected, MembershipSeasons::of(new \DateTimeImmutable($submittedAt)));
    }

    public function testTheCardStartsAtTheSeasonOfTheFirstApplication(): void
    {
        $seasons = $this->seasonsOf('2026-10-05');

        self::assertCount(MembershipSeasons::COUNT, $seasons);
        self::assertSame(2027, $seasons[0]['year']);
        self::assertSame(2034, $seasons[7]['year']);
    }

    /**
     * Le tampon de la saison est appose sur l'annee reglee : c'est lui qui
     * distingue, a l'entree d'un evenement, une carte a jour d'une carte de
     * l'an dernier.
     */
    public function testThePaidSeasonCarriesItsStamp(): void
    {
        $manager = $this->manager();
        $year = 2041;
        $manager->persist((new SeasonStamp())->setYear($year)->setSymbol(StampSymbol::Heart)->setColor(StampColor::Orange));
        $manager->flush();

        try {
            $seasons = $this->seasonsOf(($year - 1).'-10-05');

            self::assertSame($year, $seasons[0]['year']);
            self::assertTrue($seasons[0]['valid']);
            self::assertSame(StampSymbol::Heart, $seasons[0]['stamp']?->getSymbol());
            self::assertSame(StampColor::Orange, $seasons[0]['stamp']?->getColor());
        } finally {
            $manager->createQuery('DELETE FROM '.SeasonStamp::class.' s WHERE s.year = :y')
                ->setParameter('y', $year)
                ->execute();
        }
    }

    /**
     * Une saison sans tampon enregistre reste cochee : mieux vaut une case
     * sans ornement qu'une adhesion qui n'apparait pas.
     */
    public function testASeasonWithoutAStampIsStillTicked(): void
    {
        $seasons = $this->seasonsOf('2038-11-02');

        self::assertTrue($seasons[0]['valid']);
        self::assertNull($seasons[0]['stamp']);
    }

    public function testAPendingApplicationTicksNothing(): void
    {
        $seasons = $this->seasonsOf('2026-10-05', validated: false);

        self::assertSame([], array_filter(array_column($seasons, 'valid')));
    }

    /**
     * @return list<array{year: int, valid: bool, stamp: SeasonStamp|null}>
     */
    private function seasonsOf(string $submittedAt, bool $validated = true): array
    {
        self::bootKernel();

        $application = (new MembershipApplication())
            ->setLastName('Durand')
            ->setFirstName('Camille')
            ->setBirthDate(new \DateTimeImmutable('1990-04-02'))
            ->setPostalCode('89300')
            ->setCity('Joigny')
            ->setEmail('camille@example.test')
            ->setMembershipType(MembershipType::Individual)
            ->setSignaturePlace('Joigny')
            ->setSignatureName('Camille Durand')
            ->setMember(new Member('EDJ-0001'))
            ->setSubmittedAt(new \DateTimeImmutable($submittedAt));

        if ($validated) {
            $application->validate(PaymentMethod::Cheque, null);
        }

        return static::getContainer()->get(MembershipSeasons::class)->forApplication($application);
    }

    private function manager(): EntityManagerInterface
    {
        self::bootKernel();

        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
