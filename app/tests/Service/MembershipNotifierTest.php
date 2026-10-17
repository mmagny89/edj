<?php

namespace App\Tests\Service;

use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Service\MembershipNotifier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class MembershipNotifierTest extends KernelTestCase
{
    /**
     * @return array{MembershipNotifier, \ArrayObject<int, RawMessage>}
     */
    private function notifier(): array
    {
        self::bootKernel();
        $sent = new \ArrayObject();
        $mailer = new class($sent) implements MailerInterface {
            /** @param \ArrayObject<int, RawMessage> $sent */
            public function __construct(private \ArrayObject $sent)
            {
            }

            public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                $this->sent->append($message);
            }
        };

        $limiter = new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 day'],
            new InMemoryStorage(),
        );

        return [new MembershipNotifier($mailer, static::getContainer()->get('twig'), $limiter, 'asso@example.test', ''), $sent];
    }

    private function application(string $email): MembershipApplication
    {
        return (new MembershipApplication())
            ->setFirstName('Camille')
            ->setLastName('Martin')
            ->setBirthDate(new \DateTimeImmutable('1990-01-01'))
            ->setEmail($email)
            ->setMembershipType(MembershipType::Individual);
    }

    public function testARecipientReceivesAtMostThreeConfirmationsADay(): void
    {
        [$notifier, $sent] = $this->notifier();

        for ($i = 0; $i < 5; ++$i) {
            $notifier->confirmToApplicant($this->application('cible@example.test'));
        }

        self::assertCount(3, $sent);
    }

    public function testTheCeilingIsPerAddressNotGlobal(): void
    {
        [$notifier, $sent] = $this->notifier();

        for ($i = 0; $i < 4; ++$i) {
            $notifier->confirmToApplicant($this->application('un@example.test'));
        }
        $notifier->confirmToApplicant($this->application('deux@example.test'));

        self::assertCount(4, $sent, 'Trois pour la première adresse, un pour la seconde.');
    }

    public function testTheCeilingIgnoresCase(): void
    {
        [$notifier, $sent] = $this->notifier();

        foreach (['Cible@Example.test', 'cible@example.TEST', 'CIBLE@example.test', 'cible@example.test'] as $address) {
            $notifier->confirmToApplicant($this->application($address));
        }

        self::assertCount(3, $sent);
    }
}
