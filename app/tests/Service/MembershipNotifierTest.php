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
     * Le plafond reel des confirmations, tel que rate_limiter.yaml le pose.
     * Repris ici parce que l'environnement de test le releve a 100 000 pour
     * que les compteurs, qui survivent d'une execution a l'autre, ne fassent
     * pas echouer les suites — ce qui ne prouverait alors plus rien.
     */
    private const CONFIGURED_CONFIRMATIONS_A_DAY = 12;

    /**
     * @return array{MembershipNotifier, \ArrayObject<int, RawMessage>}
     */
    private function notifier(int $confirmations = 3): array
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

        $limiter = static fn (int $limit, string $interval) => new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => $interval],
            new InMemoryStorage(),
        );

        $notifier = new MembershipNotifier(
            $mailer,
            static::getContainer()->get('twig'),
            $limiter($confirmations, '1 day'),
            $limiter(12, '1 hour'),
            'asso@example.test',
            'noreply@example.test',
            '',
        );

        return [$notifier, $sent];
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

    public function testARecipientIsCappedPerDay(): void
    {
        [$notifier, $sent] = $this->notifier();

        for ($i = 0; $i < 5; ++$i) {
            $notifier->confirmToApplicant($this->application('cible@example.test'));
        }

        self::assertCount(3, $sent, 'Le plafond du limiteur fourni ici est de trois.');
    }

    /**
     * Une meme adresse sert legitimement a plusieurs bulletins : une adhesion
     * = un bulletin, et un foyer partage souvent une seule boite — les parents
     * saisissent la leur pour chacun de leurs enfants. Le plafond reel
     * (rate_limiter.yaml) doit donc laisser passer un grand foyer entier ; a
     * trois par jour, le quatrieme enfant ne recevait rien, en silence.
     */
    public function testAHouseholdSharingOneAddressAllGetTheirConfirmation(): void
    {
        [$notifier, $sent] = $this->notifier(confirmations: self::CONFIGURED_CONFIRMATIONS_A_DAY);

        for ($i = 0; $i < 6; ++$i) {
            $notifier->confirmToApplicant($this->application('famille@example.test'));
        }

        self::assertCount(6, $sent);
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
