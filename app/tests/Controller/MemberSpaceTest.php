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
 * Espace adherent : demande de lien, lien signe, fiche et carte.
 *
 * Ce parcours remplace un compte et un mot de passe : ce qui est verifie ici,
 * c'est qu'aucun autre chemin n'y mene — ni une URL devinee, ni un lien
 * retouche, ni un numero saisi au hasard.
 */
final class MemberSpaceTest extends WebTestCase
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
     * Numero propre a chaque cas : la sequence n'est pas remise a zero entre
     * deux lancements, et un numero en dur finirait par se heurter a l'unicite.
     */
    private function create(bool $validated = true, ?string $email = null): MembershipApplication
    {
        $member = new Member('EDJ-'.random_int(100000, 999999));
        $application = (new MembershipApplication())
            ->setLastName('Durand')
            ->setFirstName('Camille')
            ->setBirthDate(new \DateTimeImmutable('1990-04-02'))
            ->setPostalCode('89300')
            ->setCity('Joigny')
            ->setEmail($email ?? 'camille-'.bin2hex(random_bytes(5)).'@example.test')
            ->setMembershipType(MembershipType::Individual)
            ->setSignaturePlace('Joigny')
            ->setSignatureName('Camille Durand')
            ->setMember($member);

        if ($validated) {
            $application->validate(PaymentMethod::Cheque, null);
        }

        $this->entityManager->persist($member);
        $this->entityManager->persist($application);
        $this->entityManager->flush();

        return $application;
    }

    private function askForLink(string $number): void
    {
        $crawler = $this->client->request('GET', '/adhesion/ma-fiche');
        $this->client->submit($crawler->selectButton('Recevoir mon lien')->form([
            'member_access_request[number]' => $number,
        ]));
    }

    private function lastLink(): string
    {
        $messages = static::getContainer()->get('messenger.transport.async')->getSent();
        self::assertNotEmpty($messages, 'Aucun courriel envoyé.');

        $email = end($messages)->getMessage()->getMessage();
        self::assertInstanceOf(Email::class, $email);

        self::assertSame(1, preg_match('#https?://\S+/adhesion/espace/EDJ-\d+\?\S+#', (string) $email->getTextBody(), $found));

        return rtrim($found[0], '.');
    }

    public function testTheRequestPageIsPublic(): void
    {
        $this->client->request('GET', '/adhesion/ma-fiche');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "Ma fiche d'adhérent");
    }

    /**
     * Le coeur de la mesure contre l'enumeration : un numero inconnu donne
     * exactement la meme page qu'un numero connu, et n'envoie rien.
     */
    public function testAnUnknownNumberIsAnsweredLikeAKnownOne(): void
    {
        $application = $this->create();
        $this->askForLink('EDJ-0000001');
        $unknown = (string) $this->client->getResponse()->getContent();
        self::assertCount(0, static::getContainer()->get('messenger.transport.async')->getSent());

        $this->askForLink((string) $application->getMemberNumber());
        $known = (string) $this->client->getResponse()->getContent();

        self::assertResponseRedirects('/adhesion/ma-fiche/envoye');
        self::assertSame($unknown, $known);
    }

    public function testTheLinkIsSentToTheAddressOnTheBulletin(): void
    {
        // Adresse propre a chaque lancement : le plafond par destinataire vit
        // dans le cache applicatif, qui survit d'une execution de la suite a
        // l'autre. Une adresse en dur finissait par etre bridee, et le test
        // echouait au bout de quelques lancements sans qu'aucun code n'ait
        // change.
        $target = 'camille-cible-'.bin2hex(random_bytes(5)).'@example.test';
        $application = $this->create(email: $target);

        $this->askForLink((string) $application->getMemberNumber());

        $sent = static::getContainer()->get('messenger.transport.async')->getSent();
        self::assertCount(1, $sent);
        $email = end($sent)->getMessage()->getMessage();
        self::assertSame([$target], array_map(
            static fn ($address) => $address->getAddress(),
            $email->getTo(),
        ));
    }

    public function testASignedLinkOpensTheSheet(): void
    {
        $application = $this->create();
        $this->askForLink((string) $application->getMemberNumber());

        $this->client->request('GET', $this->lastLink());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bonjour Camille');
        self::assertSelectorTextContains('body', (string) $application->getMemberNumber());
    }

    public function testAnUnsignedUrlIsRefused(): void
    {
        $application = $this->create();

        $this->client->request('GET', '/adhesion/espace/'.$application->getMemberNumber());

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('h1', "Ce lien n'est plus valable");
    }

    public function testATamperedLinkIsRefused(): void
    {
        $application = $this->create();
        $other = $this->create();
        $this->askForLink((string) $application->getMemberNumber());

        // Meme signature, autre numero : c'est exactement ce que tenterait qui
        // voudrait lire la fiche du voisin depuis son propre lien.
        $forged = str_replace(
            (string) $application->getMemberNumber(),
            (string) $other->getMemberNumber(),
            $this->lastLink(),
        );
        $this->client->request('GET', $forged);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        $application = $this->create();
        $links = static::getContainer()->get(MemberAccessLink::class);

        $this->client->request('GET', $links->sheetUrl(
            $application->getMember(),
            new \DateTimeImmutable('-1 minute'),
        ));

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheCardIsAPdfOnceTheMembershipIsValidated(): void
    {
        $application = $this->create();
        $this->askForLink((string) $application->getMemberNumber());
        $crawler = $this->client->request('GET', $this->lastLink());

        $this->client->request('GET', $crawler->selectLink('Télécharger ma carte')->link()->getUri());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString(
            'carte-adherent-'.$application->getMemberNumber().'.pdf',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    /**
     * Une carte remise avant encaissement vaudrait acceptation de l'adhesion.
     */
    public function testAPendingMembershipHasNoCard(): void
    {
        $application = $this->create(validated: false);
        $this->askForLink((string) $application->getMemberNumber());
        $crawler = $this->client->request('GET', $this->lastLink());

        self::assertCount(0, $crawler->selectLink('Télécharger ma carte'));

        $links = static::getContainer()->get(MemberAccessLink::class);
        $this->client->request('GET', $links->cardUrl($application->getMember()));

        self::assertResponseRedirects('/adhesion/ma-fiche');
    }

    public function testAnAnonymizedBulletinIsNoLongerReachable(): void
    {
        $application = $this->create();
        $links = static::getContainer()->get(MemberAccessLink::class);
        $url = $links->sheetUrl($application->getMember());

        $application->anonymize();
        $this->entityManager->flush();

        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }
}
