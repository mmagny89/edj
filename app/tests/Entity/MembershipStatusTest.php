<?php

namespace App\Tests\Entity;

use App\Entity\MembershipApplication;
use App\Enum\MembershipStatus;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use PHPUnit\Framework\TestCase;

/**
 * Cycle de vie d'un bulletin : en attente, valide, annule. Les regles tiennent
 * dans l'entite plutot que dans le controleur, pour qu'une saisie par la
 * commande de demonstration ou par un futur import les respecte aussi.
 */
final class MembershipStatusTest extends TestCase
{
    private function application(MembershipType $type = MembershipType::Individual): MembershipApplication
    {
        return (new MembershipApplication())->setMembershipType($type);
    }

    public function testANewBulletinIsPending(): void
    {
        $application = $this->application();

        self::assertTrue($application->isPending());
        self::assertSame(MembershipStatus::Pending, $application->getStatus());
        self::assertNull($application->getStatusChangedAt());
    }

    public function testValidationKeepsThePaymentMethodAndItsAuthor(): void
    {
        $application = $this->application()->validate(PaymentMethod::Cheque, 7);

        self::assertTrue($application->isValidated());
        self::assertSame(PaymentMethod::Cheque, $application->getPaymentMethod());
        self::assertSame(7, $application->getStatusChangedById());
        self::assertNotNull($application->getStatusChangedAt());
    }

    /**
     * Le moyen de reglement est la seule trace de l'encaissement cote site :
     * valider sans lui laisserait une adhesion payante reputee reglee sans
     * qu'on sache comment.
     */
    public function testAPaidMembershipCannotBeValidatedWithoutAPaymentMethod(): void
    {
        $this->expectException(\LogicException::class);

        $this->application()->validate(null, 7);
    }

    public function testTheConsumerMembershipIsValidatedWithoutPayment(): void
    {
        $application = $this->application(MembershipType::Consumer)->validate(null, 7);

        self::assertTrue($application->isValidated());
        self::assertNull($application->getPaymentMethod());
    }

    /**
     * Un encaissement annule ne doit plus figurer sur le bulletin : il
     * designerait un reglement qui n'a pas lieu d'etre, ou qui a ete rembourse.
     */
    public function testCancellationClearsThePaymentMethod(): void
    {
        $application = $this->application()->validate(PaymentMethod::Transfer, 7)->cancel(9);

        self::assertTrue($application->isCancelled());
        self::assertNull($application->getPaymentMethod());
        self::assertSame(9, $application->getStatusChangedById());
    }

    public function testReopeningPutsTheBulletinBackInTheQueue(): void
    {
        $application = $this->application()->validate(PaymentMethod::Cash, 7)->reopen(9);

        self::assertTrue($application->isPending());
        self::assertNull($application->getPaymentMethod());
    }
}
