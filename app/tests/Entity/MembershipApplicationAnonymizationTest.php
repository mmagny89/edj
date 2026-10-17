<?php

namespace App\Tests\Entity;

use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use PHPUnit\Framework\TestCase;

final class MembershipApplicationAnonymizationTest extends TestCase
{
    private function application(): MembershipApplication
    {
        return (new MembershipApplication())
            ->setLastName('Durand')
            ->setFirstName('Camille')
            ->setBirthDate(new \DateTimeImmutable('2014-04-02'))
            ->setAddress('1 rue des Jeux')
            ->setPostalCode('89300')
            ->setCity('Joigny')
            ->setPhone('06 00 00 00 00')
            ->setEmail('camille@example.test')
            ->setMembershipType(MembershipType::Family)
            ->setPrincipalMemberName('Sam Durand')
            ->setGuardianFirstName('Sam')
            ->setGuardianLastName('Durand')
            ->setGuardianEmail('sam@example.test')
            ->setPhilibertConsent(true)
            ->setImageRightsRefused(true)
            ->setSignaturePlace('Joigny')
            ->setSignatureName('Sam Durand');
    }

    public function testEveryPersonalFieldIsErased(): void
    {
        $application = $this->application()->anonymize();

        self::assertTrue($application->isAnonymized());
        self::assertSame('ANONYMISÉ', $application->getLastName());
        self::assertSame('', $application->getFirstName());
        self::assertNull($application->getAddress());
        self::assertNull($application->getPhone());
        self::assertNull($application->getPrincipalMemberName());
        self::assertNull($application->getGuardianLastName());
        self::assertNull($application->getGuardianFirstName());
        self::assertNull($application->getGuardianEmail());
        self::assertSame('—', $application->getSignatureName());
        self::assertSame('00000', $application->getPostalCode());
    }

    public function testNothingOfTheOriginalDataSurvivesAnywhere(): void
    {
        $application = $this->application()->anonymize();

        $dump = json_encode((array) $application, \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_UNESCAPED_UNICODE) ?: '';
        foreach (['Durand', 'Camille', 'Sam', 'rue des Jeux', 'Joigny', '06 00', 'example.test'] as $needle) {
            self::assertStringNotContainsString($needle, $dump, $needle);
        }
    }

    public function testEmailBecomesAnUnreachableUniqueAddress(): void
    {
        $application = $this->application();
        (new \ReflectionProperty($application, 'id'))->setValue($application, 42);
        $application->anonymize();

        self::assertSame('anonyme-42@anonyme.invalid', $application->getEmail());
    }

    public function testBirthDateIsReducedToTheYear(): void
    {
        $application = $this->application()->anonymize();

        self::assertSame('2014-01-01', $application->getBirthDate()?->format('Y-m-d'));
        self::assertTrue($application->isMinor(new \DateTimeImmutable('2026-10-17')));
    }

    public function testChoicesAndDatesNeededForStatisticsAreKept(): void
    {
        $submitted = $this->application()->getSubmittedAt();
        $application = $this->application()->anonymize();

        self::assertSame(MembershipType::Family, $application->getMembershipType());
        self::assertTrue($application->isPhilibertConsent());
        self::assertTrue($application->isImageRightsRefused());
        self::assertEqualsWithDelta($submitted->getTimestamp(), $application->getSubmittedAt()->getTimestamp(), 5);
    }

    public function testAnonymizingTwiceKeepsTheFirstDate(): void
    {
        $first = new \DateTimeImmutable('2026-01-01 10:00');
        $application = $this->application()->anonymize($first);
        $application->anonymize(new \DateTimeImmutable('2026-06-01 10:00'));

        self::assertSame($first, $application->getAnonymizedAt());
    }
}
