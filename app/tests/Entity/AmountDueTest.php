<?php

namespace App\Tests\Entity;

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

    public function testAPayingMemberIsValidatedWithOne(): void
    {
        $application = $this->application(MembershipType::Family, 9)->validate(PaymentMethod::Cash, 7);

        self::assertSame(PaymentMethod::Cash, $application->getPaymentMethod());
    }
}
