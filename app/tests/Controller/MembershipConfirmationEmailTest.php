<?php

namespace App\Tests\Controller;

use App\Entity\MembershipApplication;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mime\Email;

/**
 * Courriel de confirmation envoye a l'adherent au depot du bulletin.
 *
 * Ecrit en base de test. Chaque cas part d'une adresse IP differente : le
 * plafond de depots du bulletin compte par IP, et ses compteurs survivent entre
 * deux lancements.
 */
final class MembershipConfirmationEmailTest extends WebTestCase
{
    private KernelBrowser $client;

    /** Adresse propre a chaque cas : le plafond par destinataire survit entre deux lancements. */
    private string $email;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->email = 'c-'.bin2hex(random_bytes(5)).'@confirmation.example.test';
        static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM '.MembershipApplication::class.' a WHERE a.email LIKE :d')
            ->setParameter('d', '%@confirmation.example.test')
            ->execute();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM '.MembershipApplication::class.' a WHERE a.email LIKE :d')
            ->setParameter('d', '%@confirmation.example.test')
            ->execute();
        parent::tearDown();
    }

    /**
     * @param array<string, string> $extra
     */
    private function submit(string $birth, array $extra = []): void
    {
        $server = ['REMOTE_ADDR' => '198.51.100.'.random_int(1, 254), 'HTTP_ORIGIN' => 'http://localhost'];
        $crawler = $this->client->request('GET', '/adhesion/bulletin', [], [], $server);
        $form = $crawler->selectButton('Envoyer mon bulletin')->form();

        $values = array_merge([
            'membership_application[lastName]' => 'Martin',
            'membership_application[firstName]' => 'Camille',
            'membership_application[birthDate]' => $birth,
            'membership_application[postalCode]' => '89300',
            'membership_application[city]' => 'Joigny',
            'membership_application[email]' => $this->email,
            'membership_application[signaturePlace]' => 'Joigny',
            'membership_application[signatureName]' => 'Camille Martin',
        ], $extra);
        foreach ($values as $name => $value) {
            $form[$name] = $value;
        }
        $form['membership_application[membershipType]']->select('individuelle');
        $form['membership_application[statutesAccepted]']->tick();
        $form['membership_application[rulesAccepted]']->tick();

        $this->client->submit($form, [], $server);
    }

    /**
     * Les courriels partent par le transport « async », en memoire pendant les
     * tests : ce sont les messages mis en file, tels que le worker les
     * trouverait.
     *
     * @return list<Email>
     */
    private function sentEmails(): array
    {
        $messages = [];
        foreach (static::getContainer()->get('messenger.transport.async')->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SendEmailMessage && $message->getMessage() instanceof Email) {
                $messages[] = $message->getMessage();
            }
        }

        return $messages;
    }

    private function confirmation(): Email
    {
        foreach ($this->sentEmails() as $message) {
            if ('Envie de Jouer' === ($message->getFrom()[0]->getName() ?? null)) {
                return $message;
            }
        }
        self::fail('Aucun courriel de confirmation envoyé.');
    }

    public function testAdultReceivesAConfirmationWithThePublicSocialLinks(): void
    {
        $this->submit('1990-04-02');

        self::assertResponseRedirects('/adhesion/bulletin/merci');
        $email = $this->confirmation();

        self::assertSame($this->email, $email->getTo()[0]->getAddress());
        self::assertCount(1, $email->getTo());

        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('facebook.com/EnviedeJouer89', $html);
        self::assertStringContainsString('instagram.com/envie_de_jouer_89', $html);
        self::assertStringContainsString('/donnees-personnelles', $html);
        self::assertNotNull($email->getTextBody(), 'Une version texte accompagne le HTML.');
    }

    public function testFacebookGroupIsAlwaysListedAndWhatsappOnlyWhenConfigured(): void
    {
        // MEMBERS_WHATSAPP_URL est vide dans .env.test.
        $this->submit('1990-04-02');
        $html = (string) $this->confirmation()->getHtmlBody();

        self::assertStringContainsString('facebook.com/groups/enviedejouer89/', $html);
        self::assertStringNotContainsString('?ref=share', $html);
        self::assertStringNotContainsString('WhatsApp', $html);
    }

    public function testGuardianIsAlsoAddressedForAMinor(): void
    {
        $birth = (new \DateTimeImmutable('-10 years'))->format('Y-m-d');
        $this->submit($birth, [
            'membership_application[guardianLastName]' => 'Martin',
            'membership_application[guardianFirstName]' => 'Sam',
            'membership_application[guardianEmail]' => 'sam@confirmation.example.test',
        ]);

        $addresses = array_map(
            static fn ($a) => $a->getAddress(),
            $this->confirmation()->getTo(),
        );
        self::assertSame([$this->email, 'sam@confirmation.example.test'], $addresses);
    }

    public function testNoConfirmationIsSentWhenTheFormIsInvalid(): void
    {
        $this->submit('1990-04-02', ['membership_application[email]' => 'pas-une-adresse']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->sentEmails());
    }

    /**
     * Le lien WhatsApp se rend quand un lien est fourni, et seulement
     * alors : rendu directement, car MEMBERS_WHATSAPP_URL est vide en test.
     */
    public function testWhatsappLinkIsRenderedWhenConfigured(): void
    {
        $application = (new MembershipApplication())
            ->setFirstName('Camille')
            ->setLastName('Martin')
            ->setMembershipType(\App\Enum\MembershipType::Individual);
        $twig = static::getContainer()->get('twig');

        $html = $twig->render('emails/membership_confirmation.html.twig', ['application' => $application, 'membersWhatsappUrl' => 'https://groupe.example.test/invitation']);
        $text = $twig->render('emails/membership_confirmation.txt.twig', ['application' => $application, 'membersWhatsappUrl' => 'https://groupe.example.test/invitation']);
        $without = $twig->render('emails/membership_confirmation.html.twig', ['application' => $application, 'membersWhatsappUrl' => null]);

        self::assertStringContainsString('https://groupe.example.test/invitation', $html);
        self::assertStringContainsString('https://groupe.example.test/invitation', $text);
        self::assertStringContainsString('Groupe WhatsApp', $html);
        self::assertStringNotContainsString('groupe.example.test', $without);
        self::assertStringNotContainsString('Groupe WhatsApp', $without);
    }
}
