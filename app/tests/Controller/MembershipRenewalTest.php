<?php

namespace App\Tests\Controller;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Service\MemberAccessLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Renouvellement depuis le site : numero d'adherent, lien signe, formulaire
 * pre-rempli.
 *
 * Ce parcours ne doit rien reveler a qui connait seulement un numero — il est
 * devinable — et ne doit jamais donner un second numero a quelqu'un qui en a
 * deja un.
 */
final class MembershipRenewalTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    private function purge(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.MembershipApplication::class)->execute();
        $this->entityManager->createQuery('DELETE FROM '.Member::class)->execute();
    }

    /**
     * Un bulletin d'une saison passee, valide : c'est ce qu'on renouvelle.
     * Numero et adresse propres a chaque cas — les compteurs d'envoi survivent
     * d'une execution de la suite a l'autre.
     */
    private function previous(string $submittedAt = '-14 months', bool $anonymized = false): MembershipApplication
    {
        $member = new Member('EDJ-'.random_int(100000, 999999));
        $application = (new MembershipApplication())
            ->setLastName('Durand')
            ->setFirstName('Camille')
            ->setBirthDate(new \DateTimeImmutable('1990-04-02'))
            ->setAddress('3 rue des Jeux')
            ->setPostalCode('89300')
            ->setCity('Joigny')
            ->setEmail('camille-'.bin2hex(random_bytes(5)).'@example.test')
            ->setMembershipType(MembershipType::Individual)
            ->setPhilibertConsent(true)
            ->setSignaturePlace('Joigny')
            ->setSignatureName('Camille Durand')
            ->setSubmittedAt(new \DateTimeImmutable($submittedAt))
            ->setMember($member);
        $application->validate(PaymentMethod::Cheque, null);

        if ($anonymized) {
            $application->anonymize();
        }

        $this->entityManager->persist($member);
        $this->entityManager->persist($application);
        $this->entityManager->flush();

        return $application;
    }

    private function link(MembershipApplication $application): string
    {
        return static::getContainer()->get(MemberAccessLink::class)->renewalUrl($application->getMember());
    }

    private function askForLink(string $number): void
    {
        $crawler = $this->client->request('GET', '/adhesion/renouvellement');
        $this->client->submit($crawler->selectButton('Recevoir mon lien')->form([
            'member_access_request[number]' => $number,
        ]));
    }

    /**
     * @return list<Email>
     */
    private function emails(): array
    {
        $emails = [];
        foreach (static::getContainer()->get('messenger.transport.async')->getSent() as $envelope) {
            $message = $envelope->getMessage()->getMessage();
            if ($message instanceof Email) {
                $emails[] = $message;
            }
        }

        return $emails;
    }

    private function renew(string $url): void
    {
        $server = ['REMOTE_ADDR' => '198.51.100.'.random_int(1, 254), 'HTTP_ORIGIN' => 'http://localhost'];
        $crawler = $this->client->request('GET', $url, [], [], $server);
        $form = $crawler->selectButton('Envoyer mon bulletin')->form();
        $form['membership_application[statutesAccepted]']->tick();
        $form['membership_application[rulesAccepted]']->tick();
        $form['membership_application[signaturePlace]'] = 'Joigny';
        $form['membership_application[signatureName]'] = 'Camille Durand';
        $this->client->submit($form, [], $server);
    }

    public function testTheBulletinPageOffersBothWays(): void
    {
        $crawler = $this->client->request('GET', '/adhesion/bulletin');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('nav.membership-choice a[aria-current="page"]'));
        self::assertSelectorTextContains('nav.membership-choice', 'Nouvelle adhésion');
        self::assertSelectorTextContains('nav.membership-choice', 'Renouveler mon adhésion');
    }

    public function testTheRequestPageIsPublic(): void
    {
        $this->client->request('GET', '/adhesion/renouvellement');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Renouveler mon adhésion');
    }

    /**
     * Le numero est devinable : l'inconnu et le connu recoivent la meme page, et
     * seul le connu recoit un courriel.
     */
    public function testAnUnknownNumberIsAnsweredLikeAKnownOne(): void
    {
        $application = $this->previous();

        $this->askForLink('EDJ-0000001');
        $unknown = (string) $this->client->getResponse()->getContent();
        self::assertCount(0, $this->emails());

        $this->askForLink((string) $application->getMemberNumber());
        $known = (string) $this->client->getResponse()->getContent();

        self::assertResponseRedirects('/adhesion/renouvellement/envoye');
        self::assertSame($unknown, $known);
        self::assertCount(1, $this->emails());
    }

    public function testTheLinkGoesToTheAddressOnTheLastBulletin(): void
    {
        $application = $this->previous();

        $this->askForLink((string) $application->getMemberNumber());

        $email = $this->emails()[0];
        self::assertSame([$application->getEmail()], array_map(static fn ($a) => $a->getAddress(), $email->getTo()));
        self::assertMatchesRegularExpression('#/adhesion/renouvellement/EDJ-\d+\?\S*_hash=#', (string) $email->getTextBody());
    }

    public function testAnUnsignedUrlIsRefused(): void
    {
        $application = $this->previous();

        $this->client->request('GET', '/adhesion/renouvellement/'.$application->getMemberNumber());

        self::assertResponseStatusCodeSame(403);
    }

    public function testALinkSignedForAnotherNumberIsRefused(): void
    {
        $mine = $this->previous();
        $other = $this->previous();

        $forged = str_replace((string) $mine->getMemberNumber(), (string) $other->getMemberNumber(), $this->link($mine));
        $this->client->request('GET', $forged);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        $application = $this->previous();
        $expired = static::getContainer()->get(MemberAccessLink::class)
            ->renewalUrl($application->getMember(), new \DateTimeImmutable('-1 minute'));

        $this->client->request('GET', $expired);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheFormArrivesPrefilledButUnsigned(): void
    {
        $application = $this->previous();

        $crawler = $this->client->request('GET', $this->link($application));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Renouveler mon adhésion');
        self::assertSelectorTextContains('main', (string) $application->getMemberNumber());
        $form = $crawler->selectButton('Envoyer mon bulletin')->form();
        self::assertSame('Durand', $form['membership_application[lastName]']->getValue());
        self::assertSame('3 rue des Jeux', $form['membership_application[address]']->getValue());
        // Un renouvellement se signe de nouveau.
        self::assertSame('', (string) $form['membership_application[signatureName]']->getValue());
        self::assertFalse($form['membership_application[statutesAccepted]']->hasValue());
    }

    /**
     * Le coeur du parcours : un nouveau bulletin, en attente, sous le meme
     * numero — pas un second adherent.
     */
    public function testRenewingKeepsTheMemberNumber(): void
    {
        $application = $this->previous();
        $number = $application->getMemberNumber();

        $this->renew($this->link($application));

        self::assertResponseRedirects('/adhesion/bulletin/merci');
        $this->entityManager->clear();
        $applications = $this->entityManager->getRepository(MembershipApplication::class)->findBy([], ['id' => 'ASC']);

        self::assertCount(2, $applications);
        self::assertSame($number, $applications[1]->getMemberNumber());
        self::assertTrue($applications[1]->isPending(), 'Un renouvellement ne vaut rien tant que la cotisation n\'est pas reglee.');
        self::assertCount(1, $this->entityManager->getRepository(Member::class)->findAll());
    }

    public function testTheBoardIsToldItIsARenewal(): void
    {
        $application = $this->previous();

        $this->renew($this->link($application));

        $subjects = array_map(static fn (Email $e) => (string) $e->getSubject(), $this->emails());
        self::assertNotEmpty(array_filter($subjects, static fn (string $s) => str_starts_with($s, 'Renouvellement')));
    }

    public function testASeasonCanOnlyBeRenewedOnce(): void
    {
        $application = $this->previous();
        $link = $this->link($application);

        $this->renew($link);
        $this->client->request('GET', $link);

        self::assertSelectorTextContains('h1', "C'est déjà fait");
        $this->entityManager->clear();
        self::assertCount(2, $this->entityManager->getRepository(MembershipApplication::class)->findAll());
    }

    public function testAnAnonymizedBulletinCannotBeRenewed(): void
    {
        $application = $this->previous(anonymized: true);

        $this->client->request('GET', $this->link($application));

        self::assertResponseStatusCodeSame(404);
    }
}
