<?php

namespace App\Tests\Entity;

use App\Entity\EventAttendance;
use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ce que doit reellement une personne.
 *
 * Deux regles se superposent : le tarif de la formule, et la gratuite des
 * moins de huit ans. Le montant se calcule donc sur le bulletin et non sur la
 * formule — il depend de l'adherent autant que de ce qu'il a choisi.
 */
final class AmountDueTest extends TestCase
{
    private function application(MembershipType $type, int $age): MembershipApplication
    {
        $submittedAt = new \DateTimeImmutable('2026-10-15');

        return (new MembershipApplication())
            ->setMembershipType($type)
            // Un jour apres l'anniversaire : l'age au depot est bien celui-la,
            // sans dependre de l'heure a laquelle le test tourne.
            ->setBirthDate($submittedAt->modify(sprintf('-%d years -1 day', $age)))
            ->setSubmittedAt($submittedAt);
    }

    /**
     * @return iterable<string, array{MembershipType, int, int}>
     */
    public static function amounts(): iterable
    {
        yield 'adulte, individuelle' => [MembershipType::Individual, 40, 10];
        yield 'adulte, famille' => [MembershipType::Family, 40, 5];
        yield 'adulte, consommateur' => [MembershipType::Consumer, 40, 0];
        yield 'enfant de 8 ans, famille' => [MembershipType::Family, 8, 5];
        yield 'enfant de 7 ans, famille' => [MembershipType::Family, 7, 0];
        yield 'nourrisson, famille' => [MembershipType::Family, 0, 0];
    }

    #[DataProvider('amounts')]
    public function testTheAmountDependsOnTheFormulaAndTheAge(MembershipType $type, int $age, int $expected): void
    {
        self::assertSame($expected, $this->application($type, $age)->amountDue());
    }

    public function testTheLabelSaysWhyAChildOwesNothing(): void
    {
        self::assertSame('0 € (moins de 8 ans)', $this->application(MembershipType::Family, 6)->amountDueLabel());
    }

    /**
     * Le montant est fige au depot : un enfant qui adhere a sept ans ne
     * devient pas redevable le jour de ses huit ans.
     */
    public function testTheAmountIsFrozenAtSubmission(): void
    {
        $application = $this->application(MembershipType::Family, 7)
            ->setSubmittedAt(new \DateTimeImmutable('-5 years'));

        self::assertSame(0, $application->amountDue());
    }

    public function testAFreeChildIsValidatedWithoutAPaymentMethod(): void
    {
        $application = $this->application(MembershipType::Family, 6)->validate(null, 7);

        self::assertTrue($application->isValidated());
        self::assertNull($application->getPaymentMethod());
    }

    public function testAPayingMemberStillNeedsAPaymentMethod(): void
    {
        $this->expectException(\LogicException::class);

        $this->application(MembershipType::Family, 9)->validate(null, 7);
    }

    /**
     * Venu deux fois en consommateur, une fois gratuitement et une fois pour
     * 1 €, puis passe a l'annuel : l'euro paye se deduit.
     */
    public function testThePaidConsumerSessionsAreDeductedFromTheAnnualFee(): void
    {
        $application = $this->upgraded(MembershipType::Individual, ['2026-10-20' => false, '2026-10-27' => true]);

        self::assertSame(1, $application->consumerCredit());
        self::assertSame(9, $application->amountDue());
        self::assertSame('9 € (10 € − 1 € déjà payés en consommateur)', $application->amountDueLabel());
    }

    public function testASessionPaidAfterTheUpgradeOrInAnotherSeasonIsNotDeducted(): void
    {
        $application = $this->upgraded(MembershipType::Family, ['2026-09-29' => true, '2026-11-03' => true, '2026-11-10' => true]);

        // Seul le passage du jour du changement (3 novembre) compte.
        self::assertSame(4, $application->amountDue());
    }

    public function testTheDeductionNeverExceedsTheFee(): void
    {
        $days = [];
        for ($day = 16; $day <= 22; ++$day) {
            $days['2026-10-'.$day] = true;
        }
        $application = $this->upgraded(MembershipType::Family, $days);

        self::assertSame(0, $application->amountDue());
        self::assertTrue($application->validate(null, 7)->isValidated());
    }

    public function testSomeoneWhoWasNeverAConsumerHasNothingDeducted(): void
    {
        $application = $this->application(MembershipType::Individual, 40);
        (new EventAttendance(new \DateTimeImmutable('2026-10-20'), $application))->markPaid();

        self::assertSame(10, $application->amountDue());
    }

    /**
     * Un consommateur pointe aux jours donnes (vrai : paye), devenu annuel le
     * 3 novembre 2026.
     *
     * @param array<string, bool> $days
     */
    private function upgraded(MembershipType $to, array $days): MembershipApplication
    {
        $application = $this->application(MembershipType::Consumer, 40);
        foreach ($days as $day => $paid) {
            $attendance = new EventAttendance(new \DateTimeImmutable($day), $application);
            if ($paid) {
                $attendance->markPaid();
            }
        }

        return $application->setMembershipType($to)
            ->recordFormulaChange(MembershipType::Consumer, 7, new \DateTimeImmutable('2026-11-03 19:00'));
    }

    public function testAPayingMemberIsValidatedWithOne(): void
    {
        $application = $this->application(MembershipType::Family, 9)->validate(PaymentMethod::Cash, 7);

        self::assertSame(PaymentMethod::Cash, $application->getPaymentMethod());
    }
}
