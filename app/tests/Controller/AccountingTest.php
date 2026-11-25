<?php

namespace App\Tests\Controller;

use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La section comptable vue du navigateur : qui y accede, et ce que donne
 * l'export.
 */
final class AccountingTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    public function testAnonymousIsSentToTheLogin(): void
    {
        $this->client->request('GET', '/admin/comptabilite');

        self::assertResponseRedirects();
    }

    public function testContributorSeesTheJournal(): void
    {
        $this->logIn();
        $this->client->request('GET', '/admin/comptabilite');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Comptabilité');
        self::assertSelectorTextContains('body', 'Total encaissé');
        // Les libelles du moyen de reglement, pas leurs valeurs brutes.
        self::assertSelectorTextContains('body', 'Espèces');
        self::assertSelectorTextContains('body', 'Chèque');
    }

    public function testTheExportIsACsvForASpreadsheet(): void
    {
        $this->logIn();
        $this->client->request('GET', '/admin/comptabilite/export.csv');
        $response = $this->client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('comptabilite-', (string) $response->headers->get('Content-Disposition'));

        $body = (string) $this->client->getInternalResponse()->getContent();
        // BOM et point-virgule : sans eux, un tableur francophone ouvre tout
        // sur une colonne et mange les accents.
        self::assertStringStartsWith("\u{FEFF}", $body);
        self::assertStringContainsString('Date;Nature;', $body);
    }

    public function testTheDepositDateIsRecordedFromTheJournal(): void
    {
        $this->logIn();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $application = (new MembershipApplication())
            ->setFirstName('Dominique')->setLastName('Depot')
            ->setBirthDate(new \DateTimeImmutable('1990-01-01'))
            ->setPostalCode('89300')->setCity('Joigny')->setEmail('depot@depot.example.test')
            ->setMembershipType(MembershipType::Individual)
            ->setStatutesAccepted(true)->setRulesAccepted(true)
            ->setSignaturePlace('Joigny')->setSignatureName('Dominique Depot');
        $application->validate(PaymentMethod::Cheque, null);
        $em->persist($application);
        $em->flush();
        $id = $application->getId();

        try {
            $selector = sprintf('input#t-cotisation-%d', $id);
            $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

            foreach ([[$today, $today], [(new \DateTimeImmutable('+1 day'))->format('Y-m-d'), $today], ['', null]] as [$typed, $expected]) {
                $crawler = $this->client->request('GET', '/admin/comptabilite');
                $form = $crawler->filter($selector)->closest('form')?->form(['date' => $typed]);
                self::assertNotNull($form);
                $this->client->submit($form);
                self::assertResponseRedirects('/admin/comptabilite');

                $em = static::getContainer()->get(EntityManagerInterface::class);
                $em->clear();
                self::assertSame($expected, $em->find(MembershipApplication::class, $id)?->getCashedOn()?->format('Y-m-d'));
            }

            $crawler = $this->client->request('GET', '/admin/adhesions/'.$id);
            self::assertCount(1, $crawler->filter(sprintf('input#f-cotisation-%d', $id)));
        } finally {
            static::getContainer()->get(EntityManagerInterface::class)
                ->createQuery('DELETE FROM '.MembershipApplication::class.' a WHERE a.id = :id')
                ->setParameter('id', $id)->execute();
        }
    }

    private function logIn(): void
    {
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $this->client->loginUser($provider->loadUserByIdentifier('contributeur@example.test'));
    }
}
