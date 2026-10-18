<?php

namespace App\Tests\Entity;

use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Les deux regles de formule que le bulletin papier enonce en francais et que
 * personne ne verifiait : qui porte l'adhesion famille, et ce qu'un mineur a
 * le droit de choisir.
 */
final class MembershipRulesTest extends KernelTestCase
{
    private function application(MembershipType $type, int $age = 40): MembershipApplication
    {
        return (new MembershipApplication())
            ->setLastName('Durand')
            ->setFirstName('Camille')
            ->setBirthDate(new \DateTimeImmutable(sprintf('-%d years', $age)))
            ->setPostalCode('89300')
            ->setCity('Joigny')
            ->setEmail('camille@example.test')
            ->setMembershipType($type)
            ->setStatutesAccepted(true)
            ->setRulesAccepted(true)
            ->setSignaturePlace('Joigny')
            ->setSignatureName('Camille Durand');
    }

    /**
     * @return list<string>
     */
    private function errorsOn(MembershipApplication $application, string $path): array
    {
        self::bootKernel();
        $violations = static::getContainer()->get(ValidatorInterface::class)->validate($application);

        $messages = [];
        foreach ($violations as $violation) {
            if ($path === $violation->getPropertyPath()) {
                $messages[] = (string) $violation->getMessage();
            }
        }

        return $messages;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function selfDesignations(): iterable
    {
        yield 'prenom puis nom' => ['Camille Durand'];
        yield 'nom puis prenom' => ['Durand Camille'];
        yield 'casse et accents' => ['CAMÏLLE durand'];
        yield 'espaces en trop' => ['  Camille   Durand '];
    }

    /**
     * L'adhesion famille a 5 € se greffe sur une adhesion individuelle a 10 €
     * prise par quelqu'un d'autre du foyer : se designer soi-meme ferait
     * encaisser 5 € la ou l'association doit en encaisser 10.
     */
    #[DataProvider('selfDesignations')]
    public function testAFamilyMemberCannotDesignateThemselfAsPrincipal(string $principal): void
    {
        $application = $this->application(MembershipType::Family)->setPrincipalMemberName($principal);

        self::assertNotEmpty($this->errorsOn($application, 'principalMemberName'));
    }

    public function testAnotherPersonIsAcceptedAsPrincipal(): void
    {
        $application = $this->application(MembershipType::Family)->setPrincipalMemberName('Louis Durand');

        self::assertSame([], $this->errorsOn($application, 'principalMemberName'));
    }

    public function testAFamilyMembershipStillNeedsAPrincipal(): void
    {
        self::assertNotEmpty($this->errorsOn($this->application(MembershipType::Family), 'principalMemberName'));
    }

    /**
     * Un mineur ne s'engage pas seul aupres de l'association : il se rattache
     * a l'adhesion individuelle d'un adulte de son foyer.
     */
    public function testAMinorCannotTakeTheIndividualMembership(): void
    {
        $application = $this->application(MembershipType::Individual, age: 12)
            ->setGuardianFirstName('Responsable')
            ->setGuardianLastName('Durand')
            ->setGuardianEmail('tuteur@example.test');

        self::assertNotEmpty($this->errorsOn($application, 'membershipType'));
    }

    public function testAMinorMayTakeTheFamilyMembership(): void
    {
        $application = $this->application(MembershipType::Family, age: 12)
            ->setPrincipalMemberName('Louis Durand')
            ->setGuardianFirstName('Responsable')
            ->setGuardianLastName('Durand')
            ->setGuardianEmail('tuteur@example.test');

        self::assertSame([], $this->errorsOn($application, 'membershipType'));
    }

    public function testAnAdultMayTakeTheIndividualMembership(): void
    {
        self::assertSame([], $this->errorsOn($this->application(MembershipType::Individual), 'membershipType'));
    }
}
