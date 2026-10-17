<?php

namespace App\Tests\Command;

use App\Command\GenerateDemoMembershipsCommand;
use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Repository\MembershipApplicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateDemoMembershipsCommandTest extends TestCase
{
    /**
     * @return array{CommandTester, \ArrayObject<int, MembershipApplication>}
     */
    private function tester(string $environment): array
    {
        // ArrayObject : un tableau renvoye par valeur serait une copie vide,
        // prise avant que la commande ne s'execute.
        $persisted = new \ArrayObject();
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use ($persisted): void {
                $persisted->append($entity);
            },
        );

        $command = new GenerateDemoMembershipsCommand(
            $entityManager,
            $this->createStub(MembershipApplicationRepository::class),
            $environment,
        );

        return [new CommandTester($command), $persisted];
    }

    public function testItRefusesToRunInProduction(): void
    {
        [$tester, $persisted] = $this->tester('prod');

        self::assertSame(1, $tester->execute([]));
        self::assertCount(0, $persisted);
    }

    public function testItCreatesTwentyApplicationsOfEveryType(): void
    {
        [$tester, $persisted] = $this->tester('dev');

        self::assertSame(0, $tester->execute([]));
        self::assertCount(20, $persisted);

        $types = array_unique(array_map(
            static fn (MembershipApplication $a) => $a->getMembershipType(),
            $persisted->getArrayCopy(),
        ), \SORT_REGULAR);
        self::assertCount(\count(MembershipType::cases()), $types);
    }

    /**
     * Le depot est public : aucun bulletin fictif ne doit porter une adresse
     * qui puisse recevoir du courrier, et c'est ce suffixe qui borne la purge.
     */
    public function testEveryDemoAddressIsInTheReservedDomain(): void
    {
        [$tester, $persisted] = $this->tester('dev');
        $tester->execute(['--count' => '8']);

        foreach ($persisted as $application) {
            self::assertStringEndsWith('@example.test', (string) $application->getEmail());
            if ($application->isMinor()) {
                self::assertStringEndsWith('@example.test', (string) $application->getGuardianEmail());
            }
        }
    }

    public function testMinorsAlwaysHaveAGuardian(): void
    {
        [$tester, $persisted] = $this->tester('dev');
        $tester->execute(['--count' => '20']);

        $minors = array_filter($persisted->getArrayCopy(), static fn (MembershipApplication $a) => $a->isMinor());
        self::assertNotSame([], $minors);

        foreach ($minors as $minor) {
            self::assertNotEmpty($minor->getGuardianEmail());
        }
    }

    public function testItRejectsAnAbsurdCount(): void
    {
        [$tester] = $this->tester('dev');

        self::assertSame(2, $tester->execute(['--count' => '9999']));
    }
}
