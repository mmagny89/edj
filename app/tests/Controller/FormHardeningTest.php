<?php

namespace App\Tests\Controller;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Durcissement du bulletin public : ce qu'un robot ou un mal-intentionne tente, et
 * que le formulaire doit refuser sans rien enregistrer.
 */
final class FormHardeningTest extends WebTestCase
{
    private KernelBrowser $client;

    private string $email;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->email = 'h-'.bin2hex(random_bytes(5)).'@durcissement.example.test';
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    private function purge(): void
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $manager->createQuery('DELETE FROM '.MembershipApplication::class.' a WHERE a.email LIKE :d')
            ->setParameter('d', '%@durcissement.example.test')->execute();
        $manager->createQuery('DELETE FROM '.Member::class.' m WHERE NOT EXISTS (SELECT 1 FROM '.MembershipApplication::class.' a WHERE a.member = m)')->execute();
    }

    /**
     * @param array<string, string> $overrides
     */
    private function submit(array $overrides = []): void
    {
        $server = ['REMOTE_ADDR' => '198.51.100.'.random_int(1, 254), 'HTTP_ORIGIN' => 'http://localhost'];
        $crawler = $this->client->request('GET', '/adhesion/bulletin', [], [], $server);
        $form = $crawler->selectButton('Envoyer mon bulletin')->form();

        $values = array_merge([
            'membership_application[lastName]' => 'Martin',
            'membership_application[firstName]' => 'Camille',
            'membership_application[birthDate]' => '1990-04-02',
            'membership_application[postalCode]' => '89300',
            'membership_application[city]' => 'Joigny',
            'membership_application[email]' => $this->email,
            'membership_application[signaturePlace]' => 'Joigny',
            'membership_application[signatureName]' => 'Camille Martin',
        ], $overrides);
        foreach ($values as $name => $value) {
            $form[$name] = $value;
        }
        $form['membership_application[membershipType]']->select('individuelle');
        $form['membership_application[statutesAccepted]']->tick();
        $form['membership_application[rulesAccepted]']->tick();

        $this->client->submit($form, [], $server);
    }

    private function stored(): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('SELECT COUNT(a.id) FROM '.MembershipApplication::class.' a WHERE a.email = :e')
            ->setParameter('e', $this->email)->getSingleScalarResult();
    }

    public function testAnOrdinaryBulletinStillGoesThrough(): void
    {
        $this->submit();

        self::assertResponseRedirects('/adhesion/bulletin/merci');
        self::assertSame(1, $this->stored());
    }

    public function testTheFormCarriesAnIssuedGuardToken(): void
    {
        $crawler = $this->client->request('GET', '/adhesion/bulletin');

        self::assertMatchesRegularExpression('/^\d{10}\.[0-9a-f]{64}$/', (string) $crawler->filter('input[name="membership_application[guard]"]')->attr('value'));
    }

    /**
     * Un jeton falsifie — vieilli, invente ou vide — est un envoi qui ne vient pas
     * de la page : rien n'est enregistre.
     */
    public function testATamperedGuardIsRefusedAndStoresNothing(): void
    {
        foreach (['1000000000.'.str_repeat('0', 64), 'nimporte-quoi', ''] as $token) {
            $this->submit(['membership_application[guard]' => $token]);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('main', 'n\'a pas pu être vérifié');
            self::assertSame(0, $this->stored());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forbidden(): iterable
    {
        yield 'balise dans le nom' => ['membership_application[lastName]', '<script>alert(1)</script>'];
        yield 'chevron dans le prenom' => ['membership_application[firstName]', 'Camille<b>'];
        yield 'retour a la ligne dans la ville' => ['membership_application[city]', "Joigny\nBcc: autre@example.test"];
        yield 'octet nul dans l\'adresse' => ['membership_application[address]', "3 rue\0des Jeux"];
        yield 'caractere invisible dans la signature' => ['membership_application[signatureName]', "Camille\u{200B}Martin\u{202E}"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbidden')]
    public function testMarkupAndControlCharactersAreRefused(string $field, string $value): void
    {
        $this->submit([$field => $value]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->stored());
    }

    /**
     * Le filtre ne doit pas refuser de vrais noms : accents, apostrophes, tirets,
     * points et chiffres (une adresse) passent.
     */
    public function testRealNamesAreAccepted(): void
    {
        $this->submit([
            'membership_application[lastName]' => "D'Arcy-Lefèvre",
            'membership_application[firstName]' => 'Jean-Baptiste Zoë',
            'membership_application[address]' => '12 bis, rue du 8 mai 1945',
            'membership_application[city]' => 'Saint-Julien-du-Sault',
            'membership_application[signatureName]' => "Jean-Baptiste Zoë D'Arcy-Lefèvre",
        ]);

        self::assertResponseRedirects('/adhesion/bulletin/merci');
        self::assertSame(1, $this->stored());
    }

    public function testTheAccessRequestFormAlsoCarriesTheGuard(): void
    {
        foreach (['/adhesion/ma-fiche', '/adhesion/renouvellement'] as $path) {
            $crawler = $this->client->request('GET', $path);

            self::assertCount(1, $crawler->filter('input[type="hidden"][name$="[guard]"]'), $path);
        }
    }
}
