<?php

namespace App\Tests\Entity;

use App\Entity\MembershipApplication;
use PHPUnit\Framework\TestCase;

final class GuardianClearingTest extends TestCase
{
    private function application(string $birth): MembershipApplication
    {
        return (new MembershipApplication())
            ->setBirthDate(new \DateTimeImmutable($birth))
            ->setGuardianLastName('Martin')
            ->setGuardianFirstName('Paul')
            ->setGuardianEmail('paul@example.test');
    }

    public function testAnAdultKeepsNoGuardian(): void
    {
        $application = $this->application('-30 years')->clearGuardianUnlessMinor();

        self::assertNull($application->getGuardianLastName());
        self::assertNull($application->getGuardianFirstName());
        self::assertNull($application->getGuardianEmail());
    }

    public function testAMinorKeepsTheGuardian(): void
    {
        $application = $this->application('-10 years')->clearGuardianUnlessMinor();

        self::assertSame('Martin', $application->getGuardianLastName());
    }

    public function testTheDayOfTheEighteenthBirthdayTheGuardianGoes(): void
    {
        $application = $this->application('-18 years')->clearGuardianUnlessMinor();

        self::assertNull($application->getGuardianEmail());
    }
}
