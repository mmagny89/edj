<?php

namespace App\Tests\Controller;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\MembershipStatus;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Service\MemberAccessLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Modifier son adhesion depuis l'espace adherent : coordonnees et passage a l'annuel.
 */
final class MemberEditTest extends WebTestCase
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

    private function create(MembershipType $type, bool $validated = true): MembershipApplication
    {
        $member = new Member('EDJ-'.random_int(100000, 999999));
        $application = (new MembershipApplication())
            ->setLastName('Durand')
            ->setFirstName('Camille')
            ->setBirthDate(new \DateTimeImmutable('1990-04-02'))
            ->setPostalCode('89300')
            ->setCity('Joigny')
            ->setEmail('camille-'.bin2hex(random_bytes(5)).'@example.test')
            ->setMembershipType($type)
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

    private function editUrl(MembershipApplication $application): string
    {
        return static::getContainer()->get(MemberAccessLink::class)->editUrl($application->getMember());
    }

    private function spaceUrl(MembershipApplication $application): string
    {
        return static::getContainer()->get(MemberAccessLink::class)->sheetUrl($application->getMember());
    }

    public function testTheChoiceOffersTwoWays(): void
    {
        $this->client->request('GET', '/adhesion/modification');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->client->getCrawler()->filter('nav.membership-choice a'));
        self::assertSelectorTextContains('nav.membership-choice', 'Nouvelle adhésion');
        self::assertSelectorTextContains('nav.membership-choice', 'Modifier ou renouveler mon adhésion');
    }

    /**
     * « Ma fiche » ne fait que lire : ni formulaire, ni lien de modification ;
     * la carte, elle, est proposee.
     */
    public function testTheSpaceIsReadOnlyAndShowsTheCard(): void
    {
        $this->client->request('GET', $this->spaceUrl($this->create(MembershipType::Consumer)));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form');
        self::assertSelectorNotExists('a[href*="/modifier"]');
        self::assertSelectorExists('a[href*="carte.pdf"]');
    }

    public function testAnAnnualMemberChangesTheAddressOnly(): void
    {
        $application = $this->create(MembershipType::Individual);
        $id = $application->getId();

        $crawler = $this->client->request('GET', $this->editUrl($application));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="member_edit[membershipType]"]');
        $form = $crawler->selectButton('Enregistrer')->form(['member_edit[city]' => 'Auxerre', 'member_edit[postalCode]' => '89000']);
        $this->client->submit($form);
        self::assertResponseRedirects('/adhesion/modification/termine');
        $this->client->followRedirect();

        self::assertSelectorTextContains('h1', "C'est enregistré");
        $this->entityManager->clear();
        $saved = $this->entityManager->find(MembershipApplication::class, $id);
        self::assertSame('Auxerre', $saved->getCity());
        self::assertSame(MembershipType::Individual, $saved->getMembershipType());
        self::assertSame(MembershipStatus::Validated, $saved->getStatus());
    }

    public function testAnInvalidPostalCodeIsRefused(): void
    {
        $application = $this->create(MembershipType::Individual);

        $crawler = $this->client->request('GET', $this->editUrl($application));
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['member_edit[postalCode]' => 'abc']));

        self::assertSelectorExists('[role="alert"]');
    }

    public function testAConsumerBecomesAnnualAndTheBoardIsTold(): void
    {
        $application = $this->create(MembershipType::Consumer);
        $id = $application->getId();

        $crawler = $this->client->request('GET', $this->editUrl($application));
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['member_edit[membershipType]']->select('individuelle');
        $this->client->submit($form);
        self::assertResponseRedirects('/adhesion/modification/termine');
        // Les courriels se lisent avant la redirection : le noyau redemarre ensuite.
        $sent = array_filter(array_map(
            static fn ($envelope) => $envelope->getMessage()->getMessage(),
            static::getContainer()->get('messenger.transport.async')->getSent(),
        ), static fn ($message) => $message instanceof Email);
        $this->client->followRedirect();

        self::assertSelectorTextContains('h1', "C'est enregistré");
        $this->entityManager->clear();
        $saved = $this->entityManager->find(MembershipApplication::class, $id);
        self::assertSame(MembershipType::Individual, $saved->getMembershipType());
        self::assertSame(MembershipStatus::Pending, $saved->getStatus());
        self::assertNotEmpty($sent);
        $board = end($sent);
        self::assertSame("Passage à l'adhésion annuelle : Camille Durand", $board->getSubject());
        self::assertStringContainsString('Cotisation à encaisser', (string) $board->getHtmlBody());
        self::assertStringContainsString('Fiche actuelle', (string) $board->getHtmlBody());
    }

    /**
     * Double envoi : le second arrive apres le passage a l'annuel, avec les
     * champs de formule du premier affichage. Il ne doit pas afficher d'erreur.
     */
    public function testASecondSubmitAfterTheUpgradeShowsNoError(): void
    {
        $application = $this->create(MembershipType::Consumer);

        $crawler = $this->client->request('GET', $this->editUrl($application));
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['member_edit[membershipType]']->select('individuelle');
        $values = $form->getPhpValues();

        $this->client->submit($form);
        self::assertResponseRedirects('/adhesion/modification/termine');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', "C'est enregistré");

        $this->client->request('POST', $this->editUrl($application), $values);
        self::assertResponseRedirects('/adhesion/modification/termine');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[role="alert"]');
        self::assertSelectorTextContains('h1', "C'est enregistré");
    }

    public function testAConsumerWhoKeepsTheFormulaStaysValidated(): void
    {
        $application = $this->create(MembershipType::Consumer);
        $id = $application->getId();

        $crawler = $this->client->request('GET', $this->editUrl($application));
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['member_edit[city]' => 'Sens']));

        $this->entityManager->clear();
        $saved = $this->entityManager->find(MembershipApplication::class, $id);
        self::assertSame(MembershipType::Consumer, $saved->getMembershipType());
        self::assertSame(MembershipStatus::Validated, $saved->getStatus());
    }

    public function testFamilyNeedsAPrincipalMember(): void
    {
        $application = $this->create(MembershipType::Consumer);

        $crawler = $this->client->request('GET', $this->editUrl($application));
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['member_edit[membershipType]']->select('famille');
        $this->client->submit($form);

        self::assertSelectorExists('[role="alert"]');
    }

    public function testTheRenewalLinkOpensTheEditionWhenTheSeasonIsCovered(): void
    {
        $application = $this->create(MembershipType::Individual);
        $link = static::getContainer()->get(MemberAccessLink::class)->changeUrl($application->getMember());

        $this->client->request('GET', $link);
        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Modifier mon adhésion');
    }

    public function testTheEditionAlsoChangesTheOtherFields(): void
    {
        $application = $this->create(MembershipType::Individual);
        $id = $application->getId();

        $crawler = $this->client->request('GET', $this->editUrl($application));
        $form = $crawler->selectButton('Enregistrer')->form([
            'member_edit[firstName]' => 'Camille-Anne',
            'member_edit[phone]' => '06 12 34 56 78',
        ]);
        $form['member_edit[imageRightsRefused]']->tick();
        $this->client->submit($form);

        $this->entityManager->clear();
        $saved = $this->entityManager->find(MembershipApplication::class, $id);
        self::assertSame('Camille-Anne', $saved->getFirstName());
        self::assertTrue($saved->isImageRightsRefused());
    }

    public function testAnUnsignedUrlIsRefused(): void
    {
        $application = $this->create(MembershipType::Consumer);

        $this->client->request('GET', '/adhesion/espace/'.$application->getMemberNumber().'/modifier');

        self::assertResponseStatusCodeSame(403);
    }
}
