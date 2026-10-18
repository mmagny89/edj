<?php

namespace App\Tests\Enum;

use App\Enum\MembershipType;
use PHPUnit\Framework\TestCase;

/**
 * Montant du par formule.
 *
 * Le tarif de la formule et ce que doit la personne ne sont pas la meme
 * chose : « 10 € par an, puis 5 € par personne supplementaire » decrit un
 * foyer entier, et se lisait comme 10 € sur la fiche d'un adherent famille,
 * qui n'en doit que 5. C'est amountDue() qui fait foi a l'encaissement.
 */
final class MembershipTypeTest extends TestCase
{
    public function testTheFamilyMembershipOwesFiveEuros(): void
    {
        self::assertSame(5, MembershipType::Family->amountDue());
        self::assertSame('5 €', MembershipType::Family->amountDueLabel());
    }

    public function testTheIndividualMembershipOwesTenEuros(): void
    {
        self::assertSame(10, MembershipType::Individual->amountDue());
        self::assertSame('10 €', MembershipType::Individual->amountDueLabel());
    }

    public function testTheConsumerMembershipOwesNothingForTheSeason(): void
    {
        self::assertSame(0, MembershipType::Consumer->amountDue());
        self::assertStringContainsString('1 €', MembershipType::Consumer->amountDueLabel());
    }

    /**
     * Le montant du ne doit jamais reprendre le tarif du foyer : c'est
     * exactement la confusion que cette methode existe pour lever.
     */
    public function testTheAmountDueIsNotTheFormulaTariff(): void
    {
        self::assertNotSame(
            MembershipType::Family->priceLabel(),
            MembershipType::Family->amountDueLabel(),
        );
    }
}
