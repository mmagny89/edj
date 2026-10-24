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
 * Le pointage d'un jour, de bout en bout : la page, les trois gestes, la date
 * passee, et ce qu'ils laissent en base.
 *
 * On pointe pour aujourd'hui ou pour une date passee, sans choisir d'evenement.
 * Les jours de test (2020-12-05) sont passes — on ne pointe pas dans l'avenir — et
 * tombent dans la saison 2021, qui n'appartient a aucun autre test.
 */
final class AttendanceLogTest extends WebTestCase
{
    private const MAIL_SUFFIX = '@pointage.example.test';
    private const PAST = '2020-12-05';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
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

    public function testThePageOpensOnTodayWithItsConsumers(): void
    {
        $this->consumer('Alix', 'Consommatrice', 'now');
        $this->logIn();

        $this->client->request('GET', '/admin/pointage');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#titre-pointage', 'Aujourd\'hui');
        self::assertSelectorTextContains('body', 'Alix Consommatrice');
        self::assertInputValueSame('date', (new \DateTimeImmutable('today'))->format('Y-m-d'));
    }

    /**
     * Plus de choix d'evenement ni de saison : le jour suffit.
     */
    public function testThereIsNoEventNorSeasonSelector(): void
    {
        $this->logIn();

        $this->client->request('GET', '/admin/pointage');

        self::assertSelectorNotExists('select[name="seance"]');
        self::assertSelectorNotExists('select[name="saison"]');
        self::assertSelectorExists('input[type="date"][name="date"]');
    }

    public function testAPastDateListsTheConsumersOfItsSeasonAndSaysSo(): void
    {
        $this->consumer('Alix', 'Consommatrice', self::PAST);
        $this->consumer('Berthe', 'Autresaison', '2019-12-05');
        $this->logIn();

        $this->client->request('GET', '/admin/pointage?date='.self::PAST);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#titre-pointage', 'Séance passée');
        self::assertSelectorTextContains('body', 'Alix Consommatrice');
        self::assertSelectorTextNotContains('body', 'Berthe Autresaison');
        self::assertSelectorTextContains('body', '05/12/2020');
    }

    public function testAFutureDateIsRefusedAndTheDayBecomesToday(): void
    {
        $this->logIn();

        $this->client->request('GET', '/admin/pointage?date='.(new \DateTimeImmutable('+3 days'))->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'On ne pointe pas dans l\'avenir');
        self::assertInputValueSame('date', (new \DateTimeImmutable('today'))->format('Y-m-d'));
    }

    public function testAnInvalidDateFallsBackToToday(): void
    {
        $this->logIn();

        $this->client->request('GET', '/admin/pointage?date=2026-02-30');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Cette date n\'est pas valide');
    }

    public function testOneClickRecordsThePresenceAndTheEuroOnThatDate(): void
    {
        $consumer = $this->consumer('Alix', 'Consommatrice', self::PAST);
        $this->logIn();

        $this->press(self::PAST, $consumer, 'paye');

        $attendance = $this->attendance(self::PAST, $consumer);
        self::assertNotNull($attendance);
        self::assertTrue($attendance->isPaid());
        self::assertSame(EventAttendance::PRICE_CENTS, $attendance->collectedCents());
        self::assertSame(PaymentMethod::Cash, $attendance->getPaymentMethod());
        self::assertSame(self::PAST, $attendance->getOccurredOn()->format('Y-m-d'));
        self::assertNull($attendance->getEvent(), 'Une seance hors calendrier n\'a pas besoin d\'evenement.');
        self::assertSame(EventAttendance::DEFAULT_LABEL, $attendance->getLabel());
    }

    /**
     * Un evenement du calendrier tombant ce jour-la fournit le libelle, sans etre
     * demande : on ne le choisit pas.
     */
    public function testAnEventOnThatDateNamesTheAttendance(): void
    {
        $consumer = $this->consumer('Alix', 'Consommatrice', self::PAST);
        $event = (new Event())->setTitle('Soirée de test')->setType(EventType::Special)
            ->setStartsAt(new \DateTimeImmutable(self::PAST))->setTimeLabel('18h30 - 00h');
        $this->em->persist($event);
        $this->em->flush();
        $this->logIn();

        $this->client->request('GET', '/admin/pointage?date='.self::PAST);
        self::assertSelectorTextContains('#titre-pointage', 'Soirée de test');

        $this->press(self::PAST, $consumer, 'paye');

        $attendance = $this->attendance(self::PAST, $consumer);
        self::assertSame('Soirée de test', $attendance->getLabel());
        self::assertSame($event->getId(), $attendance->getEvent()?->getId());
    }

    public function testAPresenceWithoutPaymentCollectsNothing(): void
    {
        $consumer = $this->consumer('Alix', 'Consommatrice', self::PAST);
        $this->logIn();

        $this->press(self::PAST, $consumer, 'present');

        $attendance = $this->attendance(self::PAST, $consumer);
        self::assertNotNull($attendance);
        self::assertFalse($attendance->isPaid());
        self::assertSame(0, $attendance->collectedCents());
    }

    public function testPressingTwiceDoesNotCreateASecondLine(): void
    {
        $consumer = $this->consumer('Alix', 'Consommatrice', self::PAST);
        $this->logIn();

        $this->press(self::PAST, $consumer, 'present');
        $this->press(self::PAST, $consumer, 'paye');

        self::assertCount(1, $this->repository()->findBy(['application' => $consumer]));
        self::assertTrue($this->attendance(self::PAST, $consumer)?->isPaid());
    }

    public function testTheSamePersonCanBePointedOnTwoDays(): void
    {
        $consumer = $this->consumer('Alix', 'Consommatrice', '2020-11-01');
        $this->logIn();

        $this->press('2020-11-05', $consumer, 'paye');
        $this->press('2020-12-05', $consumer, 'paye');

        self::assertCount(2, $this->repository()->findBy(['application' => $consumer]));
    }

    public function testAMistakeIsRemoved(): void
    {
        $consumer = $this->consumer('Alix', 'Consommatrice', self::PAST);
        $this->logIn();

        $this->press(self::PAST, $consumer, 'paye');
        $this->press(self::PAST, $consumer, 'retirer');

        self::assertNull($this->attendance(self::PAST, $consumer));
    }

    public function testAnInvalidTokenChangesNothing(): void
    {
        $consumer = $this->consumer('Alix', 'Consommatrice', self::PAST);
        $this->logIn();

        $this->client->request('POST', sprintf('/admin/pointage/%s/%d', self::PAST, $consumer->getId()), [
            'action' => 'paye',
            '_token' => 'faux',
        ]);

        self::assertResponseRedirects();
        self::assertNull($this->attendance(self::PAST, $consumer));
    }

    public function testAFutureOrImpossibleDateCannotBePointed(): void
    {
        $consumer = $this->consumer('Alix', 'Consommatrice', 'now');
        $this->logIn();

        foreach ([(new \DateTimeImmutable('+2 days'))->format('Y-m-d'), '2026-02-30'] as $date) {
            $this->client->request('POST', sprintf('/admin/pointage/%s/%d', $date, $consumer->getId()), ['action' => 'paye']);
            self::assertResponseStatusCodeSame(404);
        }
        self::assertSame([], $this->repository()->findBy(['application' => $consumer]), 'Rien ne doit etre enregistre.');
    }

    /**
     * Le geste tel qu'il se fait : on ouvre la page du jour, on appuie sur le bouton.
     * Le jeton se lit sur le formulaire plutot que de se fabriquer a cote — hors
     * requete, il n'y a pas de session ou le poser.
     */
    private function press(string $date, MembershipApplication $consumer, string $action): void
    {
        $crawler = $this->client->request('GET', '/admin/pointage?date='.$date);
        $selector = sprintf(
            'form[action$="/admin/pointage/%s/%d"] input[name="action"][value="%s"]',
            $date,
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

    private function attendance(string $date, MembershipApplication $consumer): ?EventAttendance
    {
        return $this->repository()->findOneBy(['occurredOn' => new \DateTimeImmutable($date), 'application' => $consumer]);
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

    private function consumer(string $firstName, string $lastName, string $submittedAt): MembershipApplication
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
            ->setMembershipType(MembershipType::Consumer)
            ->setStatutesAccepted(true)
            ->setRulesAccepted(true)
            ->setSignaturePlace('Joigny')
            ->setSignatureName($firstName.' '.$lastName)
            ->setSubmittedAt(new \DateTimeImmutable($submittedAt))
            ->setMember($member);
        $application->validate(null, null, new \DateTimeImmutable($submittedAt));
        $this->em->persist($application);
        $this->em->flush();

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
