<?php

namespace App\Tests\Controller;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\MembershipSource;
use App\Tests\Import\XlsxFixture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'ecran d'import : reserve au super-administrateur, en deux temps, et sans rien
 * ecrire avant la confirmation. Les personnes du fichier sont inventees.
 */
final class ImportAdminTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        foreach ([...$this->files, ...(glob(static::getContainer()->getParameter('kernel.project_dir').'/var/import/*.xlsx') ?: [])] as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function purge(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.MembershipApplication::class.' a WHERE a.email LIKE :d OR a.source = :import')
            ->setParameter('d', '%@import-ecran.example.test')
            ->setParameter('import', MembershipSource::Import)
            ->execute();
        $this->entityManager->createQuery('DELETE FROM '.Member::class.' m WHERE NOT EXISTS (SELECT 1 FROM '.MembershipApplication::class.' a WHERE a.member = m)')->execute();
        $this->entityManager->clear();
    }

    private function logIn(string $identifier): void
    {
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $this->client->loginUser($provider->loadUserByIdentifier($identifier));
    }

    private function workbook(): string
    {
        $serial = (int) (new \DateTimeImmutable('1899-12-30'))->diff(new \DateTimeImmutable('now'))->days;
        $birth = (int) (new \DateTimeImmutable('1899-12-30'))->diff(new \DateTimeImmutable('1990-04-02'))->days;
        $path = sys_get_temp_dir().'/import-ecran-'.bin2hex(random_bytes(4)).'.xlsx';
        $this->files[] = $path;

        return XlsxFixture::build([
            ['DATE ADHESION', 'NOM', 'PRENOM', 'NAISSANCE', 'ADRESSE', 'CP', 'VILLE', 'EMAIL', 'TYPE', 'IMAGE', 'COTISATION', 'PAIEMENT'],
            [$serial, 'Ecran', 'Alice', $birth, '3 rue des Jeux', 89300, 'Joigny', 'alice@import-ecran.example.test', 'IND', 0, 10, 'Chèque'],
        ], [], $path);
    }

    private function upload(string $meaning = 'refus'): void
    {
        $crawler = $this->client->request('GET', '/admin/import');
        $form = $crawler->selectButton('Analyser le fichier')->form();
        $form['import_upload[file]']->upload($this->workbook());
        if ('' !== $meaning) {
            $form['import_upload[imageMeaning]']->select($meaning);
        }
        $this->client->submit($form);
    }

    public function testContributorCannotReachTheImport(): void
    {
        $this->logIn('contributeur@example.test');

        $this->client->request('GET', '/admin/import');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsSentToTheLogin(): void
    {
        $this->client->request('GET', '/admin/import');

        self::assertResponseRedirects();
    }

    public function testTheMeaningOfTheImageColumnIsRequired(): void
    {
        $this->logIn('admin@example.test');

        $this->upload('');

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('body', 'Précisez le sens de la colonne IMAGE.');
    }

    public function testAnalysisWritesNothingAndConfirmationImports(): void
    {
        $this->logIn('admin@example.test');

        $this->upload('refus');
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ecran Alice');
        self::assertSame(0, $this->entityManager->getRepository(MembershipApplication::class)->count(['email' => 'alice@import-ecran.example.test']));

        $this->client->submit($crawler->selectButton('Importer 1 adhésion(s)')->form());

        self::assertResponseRedirects('/admin/adhesions');
        self::assertSame(1, $this->entityManager->getRepository(MembershipApplication::class)->count(['email' => 'alice@import-ecran.example.test']));
        self::assertSame([], glob(static::getContainer()->getParameter('kernel.project_dir').'/var/import/*.xlsx') ?: []);
    }

    public function testCancelDeletesTheFile(): void
    {
        $this->logIn('admin@example.test');

        $this->upload('accord');
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Annuler et supprimer le fichier')->form());

        self::assertResponseRedirects('/admin/import');
        self::assertSame([], glob(static::getContainer()->getParameter('kernel.project_dir').'/var/import/*.xlsx') ?: []);
        self::assertSame(0, $this->entityManager->getRepository(MembershipApplication::class)->count(['email' => 'alice@import-ecran.example.test']));
    }

    public function testAMadeUpTokenIsRefused(): void
    {
        $this->logIn('admin@example.test');

        $this->client->request('GET', '/admin/import/'.str_repeat('a', 32).'?image=refus');

        self::assertResponseRedirects('/admin/import');
    }
}
