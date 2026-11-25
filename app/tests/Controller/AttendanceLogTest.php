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
 * On ne pointe que pour un evenement du calendrier, aujourd'hui ou passe.
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

    public function testThePageOpensOnTodaysEventWithItsConsumers(): void
    {
        $this->consumer('Alix', 'Consommatrice', 'now');
        $this->event('today', 'Mardi du jeu');
        $this->logIn();

        $this->client->request('GET', '/admin/pointage');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#titre-pointage', 'Mardi du jeu');
        self::assertSelectorTextContains('#titre-pointage', 'aujourd\'hui');
        self::assertSelectorTextContains('body', 'Alix Consommatrice');
    }

    /**
     * Sans evenement au calendrier, il n'y a rien a pointer : pas de liste de
     * consommateurs, pas de bouton.
     */
    public function testWithoutAnyEventTodayIsPointedAsAFreeSession(): void
    {
        $this->consumer('Alix', 'Consommatrice', 'now');
        $this->logIn();

        $this->client->request('GET', '/admin/pointage');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#titre-pointage', 'Séance');
        self::assertSelectorTextContains('body', 'Alix Consommatrice');
        self::assertSelectorExists('form[action*="/admin/pointage/"]');
    }

    /**
     * Un jour sans evenement se pointe sur sa date libre, sous le libelle « Séance ».
     */
    public function testADayWithoutEventIsOpenedAsAFreeDate(): void
    {
        $this->consumer('Alix', 'Consommatrice', self::PAST);
        $this->event(self::PAST);
        $this->logIn();

        $this->client->request('GET', '/admin/pointage?date=2020-12-06');

        self::assertSelectorTextContains('#titre-pointage', 'Séance');
        self::assertSelectorTextContains('#titre-pointage', '6 décembre 2020');
        self::assertSelectorTextContains('body', 'Alix Consommatrice');
    }

    public function testTheEventsAreOfferedInASelector(): void
    {
        $this->event(self::PAST, 'Soirée passée');
        $this->event('today', 'Mardi du jeu');
        $this->logIn();

        $this->client->request('GET', '/admin/pointage');

        self::assertSelectorExists('select[name="date"]');
        self::assertSelectorExists('input[type="date"][name="date"][max]');
        self::assertSelectorTextContains('select[name="date"]', 'Soirée passée');
        self::assertSelectorTextContains('select[name="date"]', 'Mardi du jeu');
    }

    public function testAPastEventListsTheConsumersOfItsSeasonAndSaysSo(): void
    {
        $this->consumer('Alix', 'Consommatrice', self::PAST);
        $this->consumer('Berthe', 'Autresaison', '2019-12-05');
        $this->event(self::PAST);
        $this->logIn();

        $this->client->request('GET', '/admin/pointage?date='.self::PAST);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#titre-pointage', 'séance passée');
        self::assertSelectorTextContains('body', 'Alix Consommatrice');
        self::assertSelectorTextNotContains('body', 'Berthe Autresaison');
        self::assertSelectorTextContains('body', '05/12/2020');
    }

    public function testAFutureDateIsRefused(): void
    {
        $this->event('today', 'Mardi du jeu');
        $this->event('+3 days', 'Soirée à venir');
        $this->logIn();

        $this->client->request('GET', '/admin/pointage?date='.(new \DateTimeImmutable('+3 days'))->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'On ne pointe pas dans l\'avenir');
        self::assertSelectorTextContains('#titre-pointage', 'Mardi du jeu');
        self::assertSelectorTextNotContains('select[name="date"]', 'Soirée à venir');
    }

    public function testAnInvalidDateFallsBackToTheLatestEvent(): void
    {
        $this->event('today', 'Mardi du jeu');
        $this->logIn();

        $this->client->request('GET', '/admin/pointage?date=2026-02-30');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Cette date n\'est pas valide');
        self::assertSelectorTextContains('#titre-pointage', 'Mardi du jeu');
    }

    public function testOneClickRecordsThePresenceAndTheEuroForTheEvent(): void
    {
        $consumer = $this->consumer('Alix', 'Consommatrice', self::PAST);
        $event = $this->event(self::PAST);
        $this->logIn();

        $this->press(self::PAST, $consumer, 'paye');

        $attendance = $this->attendance(self::PAST, $consumer);
        self::assertNotNull($attendance);
        self::assertTrue($attendance->isPaid());
        self::assertSame(EventAttendance::PRICE_CENTS, $attendance->collectedCents());
        self::assertSame(PaymentMethod::Cash, $attendance->getPaymentMethod());
        self::assertSame(self::PAST, $attendance->getOccurredOn()->format('Y-m-d'));
        self::assertSame($event->getId(), $attendance->getEvent()?->getId());
        self::assertSame('Soirée de test', $attendance->getLabel());
    }

    public function testADayWithoutEventIsPointedWithTheDefaultLabel(): void
    {
        $consumer = $this->consumer('Alix', 'Consommatrice', self::PAST);
        $this->logIn();

        $this->press(self::PAST, $consumer, 'paye');

        $attendance = $this->attendance(self::PAST, $consumer);
        self::assertNotNull($attendance);
        self::assertNull($attendance->getEvent());
        self::assertSame('Séance', $attendance->getLabel());
        self::assertTrue($attendance->isPaid());
    }

    public function testAPresenceWithoutPaymentCollectsNothing(): void
    {
        $consumer = $this->consumer('Alix', 'Consommatrice', self::PAST);
        $this->event(self::PAST);
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
        $this->event(self::PAST);
        $this->logIn();

        $this->press(self::PAST, $consumer, 'present');
        $this->press(self::PAST, $consumer, 'paye');

        self::assertCount(1, $this->repository()->findBy(['application' => $consumer]));
        self::assertTrue($this->attendance(self::PAST, $consumer)?->isPaid());
    }

    public function testTheSamePersonCanBePointedOnTwoDays(): void
    {
        $consumer = $this->consumer('Alix', 'Consommatrice', '2020-11-01');
        $this->event('2020-11-05');
        $this->event(self::PAST);
        $this->logIn();

        $this->press('2020-11-05', $consumer, 'paye');
        $this->press('2020-12-05', $consumer, 'paye');

        self::assertCount(2, $this->repository()->findBy(['application' => $consumer]));
    }

    public function testAMistakeIsRemoved(): void
    {
        $consumer = $this->consumer('Alix', 'Consommatrice', self::PAST);
        $this->event(self::PAST);
        $this->logIn();

        $this->press(self::PAST, $consumer, 'paye');
        $this->press(self::PAST, $consumer, 'retirer');

        self::assertNull($this->attendance(self::PAST, $consumer));
    }

    public function testAnInvalidTokenChangesNothing(): void
    {
        $consumer = $this->consumer('Alix', 'Consommatrice', self::PAST);
        $this->event(self::PAST);
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

    public function testAContributorCannotPointAnyoneElse(): void
    {
        $this->consumer('Alix', 'Consommatrice', self::PAST);
        $this->logIn();

        $this->client->request('GET', '/admin/pointage?date='.self::PAST);
        self::assertSelectorNotExists('#p-adherent');

        $this->client->request('POST', sprintf('/admin/pointage/%s/ajouter', self::PAST));
        self::assertResponseStatusCodeSame(403);
    }

    public function testASuperAdminPointsAnyMemberOnAFutureDate(): void
    {
        $date = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $annual = $this->consumer('Camille', 'Annuelle', 'now', MembershipType::Individual);
        $this->logIn('admin@example.test');

        $crawler = $this->client->request('GET', '/admin/pointage?date='.$date);
        $form = $crawler->filter('#p-adherent')->closest('form')?->form(['application' => (string) $annual->getId()]);
        self::assertNotNull($form);
        $this->client->submit($form);
        $this->em->clear();

        $attendance = $this->attendance($date, $annual);
        self::assertNotNull($attendance);
        self::assertFalse($attendance->isPaid());

        $this->client->request('GET', '/admin/pointage?date='.$date);
        self::assertSelectorTextContains('body', 'Camille Annuelle');
        self::assertSelectorNotExists('input[name="action"][value="paye"]');
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

    private function event(string $date, string $title = 'Soirée de test'): Event
    {
        $event = (new Event())->setTitle($title)->setType(EventType::Special)
            ->setStartsAt(new \DateTimeImmutable($date))->setTimeLabel('18h30 - 00h');
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    private function logIn(string $user = 'contributeur@example.test'): void
    {
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $this->client->loginUser($provider->loadUserByIdentifier($user));
    }

    private function consumer(string $firstName, string $lastName, string $submittedAt, MembershipType $type = MembershipType::Consumer): MembershipApplication
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
            ->setStatutesAccepted(true)
            ->setRulesAccepted(true)
            ->setSignaturePlace('Joigny')
            ->setSignatureName($firstName.' '.$lastName)
            ->setSubmittedAt(new \DateTimeImmutable($submittedAt))
            ->setMember($member);
        $application->validate(MembershipType::Consumer === $type ? null : PaymentMethod::Cash, null, new \DateTimeImmutable($submittedAt));
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
        $this->em->createQuery('DELETE FROM '.Event::class.' e WHERE e.title IN (:t)')
            ->setParameter('t', ['Soirée de test', 'Mardi du jeu', 'Soirée passée', 'Soirée à venir'])->execute();
        $this->em->createQuery('DELETE FROM '.Member::class.' m WHERE NOT EXISTS (SELECT 1 FROM '.MembershipApplication::class.' a WHERE a.member = m)')->execute();
        $this->em->clear();
    }
}
