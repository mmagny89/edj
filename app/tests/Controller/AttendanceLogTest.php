<?php

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\EventAttendance;
use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\EventType;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Repository\EventAttendanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le pointage d'une seance, de bout en bout : la page, les trois gestes, et
 * ce qu'ils laissent en base.
 */
final class AttendanceLogTest extends WebTestCase
{
    private const MAIL_SUFFIX = '@pointage.example.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        // Le noyau peut rester debout d'un test a l'autre : createClient le
        // refuse, et l'echec ne parle pas du test qui l'a laisse ouvert.
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testAnonymousIsSentToTheLogin(): void
    {
        $this->client->request('GET', '/admin/pointage');

        self::assertResponseRedirects();
    }

    public function testTheSessionListsItsConsumers(): void
    {
        [$event, $consumer] = $this->fixtures();
        $this->logIn();

        $this->client->request('GET', '/admin/pointage?seance='.$event->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Alix Consommatrice');
    }

    public function testAnnualMembersAreNotListed(): void
    {
        [$event] = $this->fixtures();
        $this->annual('Berthe', 'Annuelle');
        $this->logIn();

        $this->client->request('GET', '/admin/pointage?seance='.$event->getId());

        self::assertSelectorTextNotContains('body', 'Berthe Annuelle');
    }

    public function testOneClickRecordsThePresenceAndTheEuro(): void
    {
        [$event, $consumer] = $this->fixtures();
        $this->logIn();

        $this->press($event, $consumer, 'paye');

        $attendance = $this->attendance($event, $consumer);
        self::assertNotNull($attendance);
        self::assertTrue($attendance->isPaid());
        self::assertSame(EventAttendance::PRICE_CENTS, $attendance->collectedCents());
        self::assertSame(PaymentMethod::Cash, $attendance->getPaymentMethod());
    }

    public function testAPresenceWithoutPaymentCollectsNothing(): void
    {
        [$event, $consumer] = $this->fixtures();
        $this->logIn();

        $this->press($event, $consumer, 'present');

        $attendance = $this->attendance($event, $consumer);
        self::assertNotNull($attendance);
        self::assertFalse($attendance->isPaid());
        self::assertSame(0, $attendance->collectedCents());
    }

    public function testPressingTwiceDoesNotCreateASecondLine(): void
    {
        [$event, $consumer] = $this->fixtures();
        $this->logIn();

        $this->press($event, $consumer, 'present');
        $this->press($event, $consumer, 'paye');

        self::assertCount(1, $this->repository()->findBy(['event' => $event]));
        self::assertTrue($this->attendance($event, $consumer)?->isPaid());
    }

    public function testAMistakeIsRemoved(): void
    {
        [$event, $consumer] = $this->fixtures();
        $this->logIn();

        $this->press($event, $consumer, 'paye');
        $this->press($event, $consumer, 'retirer');

        self::assertNull($this->attendance($event, $consumer));
    }

    public function testAnInvalidTokenChangesNothing(): void
    {
        [$event, $consumer] = $this->fixtures();
        $this->logIn();

        $this->client->request('POST', sprintf('/admin/pointage/%d/%d', $event->getId(), $consumer->getId()), [
            'action' => 'paye',
            '_token' => 'faux',
        ]);

        self::assertResponseRedirects();
        self::assertNull($this->attendance($event, $consumer));
    }

    /**
     * Le geste tel qu'il se fait : on ouvre la page, on appuie sur le bouton.
     * Le jeton se lit sur le formulaire plutot que de se fabriquer a cote —
     * hors requete, il n'y a pas de session ou le poser.
     */
    private function press(Event $event, MembershipApplication $consumer, string $action): void
    {
        $crawler = $this->client->request('GET', '/admin/pointage?seance='.$event->getId());
        $selector = sprintf(
            'form[action$="/admin/pointage/%d/%d"] input[name="action"][value="%s"]',
            $event->getId(),
            $consumer->getId(),
            $action,
        );
        $field = $crawler->filter($selector);
        self::assertCount(1, $field, sprintf('Le bouton « %s » devrait etre propose.', $action));

        $form = $field->closest('form');
        self::assertNotNull($form);

        $this->client->submit($form->form());

        $this->em->clear();
    }

    private function attendance(Event $event, MembershipApplication $consumer): ?EventAttendance
    {
        return $this->repository()->findOneBy(['event' => $event, 'application' => $consumer]);
    }

    private function repository(): EventAttendanceRepository
    {
        return static::getContainer()->get(EventAttendanceRepository::class);
    }

    private function logIn(): void
    {
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $this->client->loginUser($provider->loadUserByIdentifier('contributeur@example.test'));
    }

    /**
     * @return array{Event, MembershipApplication}
     */
    private function fixtures(): array
    {
        $event = (new Event())
            ->setTitle('Soirée de test')
            ->setType(EventType::Weekly)
            ->setStartsAt(new \DateTimeImmutable('today'))
            ->setTimeLabel('18h30 - 00h');
        $this->em->persist($event);

        $consumer = $this->application('Alix', 'Consommatrice', MembershipType::Consumer);
        $this->em->flush();

        return [$event, $consumer];
    }

    private function annual(string $firstName, string $lastName): MembershipApplication
    {
        $application = $this->application($firstName, $lastName, MembershipType::Individual, PaymentMethod::Cheque);
        $this->em->flush();

        return $application;
    }

    private function application(string $firstName, string $lastName, MembershipType $type, ?PaymentMethod $method = null): MembershipApplication
    {
        $member = new Member('EDJ-'.random_int(1000, 9999));
        $this->em->persist($member);

        $application = (new MembershipApplication())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setBirthDate(new \DateTimeImmutable('1990-01-01'))
            ->setPostalCode('89300')
            ->setCity('Joigny')
            ->setEmail(mb_strtolower($lastName).self::MAIL_SUFFIX)
            ->setMembershipType($type)
            ->setSignaturePlace('Joigny')
            ->setSignatureName($firstName.' '.$lastName)
            ->setMember($member);
        $application->validate($method, null);
        $this->em->persist($application);

        return $application;
    }

    private function purge(): void
    {
        $this->em->createQuery(
            'DELETE FROM '.EventAttendance::class.' p WHERE p.application IN ('
            .'SELECT a.id FROM '.MembershipApplication::class.' a WHERE a.email LIKE :d)'
        )->setParameter('d', '%'.self::MAIL_SUFFIX)->execute();
        $this->em->createQuery('DELETE FROM '.MembershipApplication::class.' a WHERE a.email LIKE :d')
            ->setParameter('d', '%'.self::MAIL_SUFFIX)->execute();
        $this->em->createQuery('DELETE FROM '.Event::class.' e WHERE e.title = :t')
            ->setParameter('t', 'Soirée de test')->execute();
        $this->em->createQuery('DELETE FROM '.Member::class.' m WHERE NOT EXISTS (SELECT 1 FROM '.MembershipApplication::class.' a WHERE a.member = m)')->execute();
        $this->em->clear();
    }
}
