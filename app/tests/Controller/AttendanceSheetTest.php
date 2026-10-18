<?php

namespace App\Tests\Controller;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Import\ImportOptions;
use App\Import\MembershipImporter;
use App\Repository\MembershipApplicationRepository;
use App\Service\MembershipSeasons;
use App\Tests\Import\XlsxFixture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AttendanceSheetTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function logIn(string $identifier): void
    {
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $this->client->loginUser($provider->loadUserByIdentifier($identifier));
    }

    public function testAnonymousIsSentToTheLogin(): void
    {
        $this->client->request('GET', '/admin/emargement');

        self::assertResponseRedirects();
    }

    public function testContributorGetsAPdf(): void
    {
        $this->logIn('contributeur@example.test');
        $crawler = $this->client->request('GET', '/admin/emargement');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Télécharger la feuille (PDF)')->form([
            'attendance_sheet[date]' => '2026-11-14',
            'attendance_sheet[place]' => 'Salle des fêtes',
        ]);
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('emargement-2026-11-14.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testPlaceRejectsMarkup(): void
    {
        $this->logIn('contributeur@example.test');
        $crawler = $this->client->request('GET', '/admin/emargement');
        $form = $crawler->selectButton('Télécharger la feuille (PDF)')->form(['attendance_sheet[place]' => '<b>x</b>']);
        $this->client->submit($form);

        self::assertResponseIsUnprocessable();
    }

    public function testQueryKeepsAdultsSortedByNameAndMinorsOnRequest(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $purge = static function () use ($em): void {
            $em->createQuery('DELETE FROM '.MembershipApplication::class.' a WHERE a.email LIKE :d')->setParameter('d', '%@emargement.example.test')->execute();
            $em->createQuery('DELETE FROM '.Member::class.' m WHERE NOT EXISTS (SELECT 1 FROM '.MembershipApplication::class.' a WHERE a.member = m)')->execute();
            $em->clear();
        };
        $purge();

        $serial = static fn (string $d): int => (int) (new \DateTimeImmutable('1899-12-30'))->diff(new \DateTimeImmutable($d))->days;
        $row = static fn (string $nom, string $naissance): array => [$serial('now'), $nom, 'Test', $serial($naissance), '3 rue des Jeux', 89300, 'Joigny', strtolower($nom).'@emargement.example.test', 'IND', 0, 10, 'Chèque'];
        $path = sys_get_temp_dir().'/emargement-'.bin2hex(random_bytes(4)).'.xlsx';
        XlsxFixture::build([
            ['DATE ADHESION', 'NOM', 'PRENOM', 'NAISSANCE', 'ADRESSE', 'CP', 'VILLE', 'EMAIL', 'TYPE', 'IMAGE', 'COTISATION', 'PAIEMENT'],
            $row('Zola', '1980-01-01'),
            $row('Abel', '1985-01-01'),
            $row('Petit', (new \DateTimeImmutable('-8 years'))->format('Y-m-d')),
        ], [], $path);

        try {
            $importer = $container->get(MembershipImporter::class);
            $report = $importer->analyze($path, new ImportOptions(true));
            $importer->apply($report, new ImportOptions(true));
            $em->clear();

            $repo = $container->get(MembershipApplicationRepository::class);
            $season = MembershipSeasons::of(new \DateTimeImmutable());
            $mine = static fn (array $list): array => array_values(array_map(
                static fn (MembershipApplication $a) => $a->getLastName(),
                array_filter($list, static fn (MembershipApplication $a) => str_ends_with((string) $a->getEmail(), '@emargement.example.test')),
            ));

            self::assertSame(['Abel', 'Zola'], $mine($repo->findForAttendance($season, false, false)));
            self::assertContains('Petit', $mine($repo->findForAttendance($season, true, false)));
        } finally {
            @unlink($path);
            $purge();
        }
    }
}
