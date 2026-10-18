<?php

namespace App\Tests\Controller;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Service\MembershipSeasons;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le tableau de bord : une page qui assemble des chiffres, des graphiques et
 * leurs vues en tableau. Deux bulletins valides — l'un de la saison en cours,
 * l'autre de trois saisons plus tot — donnent de la matiere aux colonnes et au
 * choix de saison ; les tables sont videes avant et apres chaque cas.
 *
 * Le cas « la page s'ouvre » est le premier de la liste pour une raison : un
 * gabarit qui attend une variable que le controleur oublie de lui passer ne
 * s'attrape par aucun test de composant.
 */
final class DashboardTest extends WebTestCase
{
    private int $oldSeason;

    protected function setUp(): void
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge($manager);

        foreach (['today', '-3 years'] as $when) {
            $member = new Member('EDJ-'.random_int(100000, 999999));
            $application = (new MembershipApplication())
                ->setLastName('Durand')
                ->setFirstName('Camille')
                ->setBirthDate(new \DateTimeImmutable('1990-04-02'))
                ->setPostalCode('89300')
                ->setCity('Joigny')
                ->setEmail('c-'.bin2hex(random_bytes(4)).'@example.test')
                ->setMembershipType(MembershipType::Individual)
                ->setSignaturePlace('Joigny')
                ->setSignatureName('Camille Durand')
                ->setSubmittedAt(new \DateTimeImmutable($when))
                ->setMember($member);
            $application->validate(PaymentMethod::Cheque, null);
            $manager->persist($member);
            $manager->persist($application);
            $this->oldSeason = MembershipSeasons::of($application->getSubmittedAt());
        }
        $manager->flush();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::bootKernel();
        $this->purge(static::getContainer()->get(EntityManagerInterface::class));
        parent::tearDown();
    }

    private function purge(EntityManagerInterface $manager): void
    {
        $manager->createQuery('DELETE FROM '.MembershipApplication::class)->execute();
        $manager->createQuery('DELETE FROM '.Member::class)->execute();
    }

    private function open(string $identifier = 'admin@example.test', string $query = ''): KernelBrowser
    {
        $client = static::createClient();
        $provider = static::getContainer()->get('security.user.provider.concrete.test_memory_provider');
        $client->loginUser($provider->loadUserByIdentifier($identifier));
        $client->request('GET', '/admin'.$query);

        return $client;
    }

    public function testThePageOpens(): void
    {
        $this->open();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');
    }

    public function testAContributorSeesTheCharts(): void
    {
        $this->open('contributeur@example.test');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('figure.viz');
    }

    /**
     * Six graphiques, six jumeaux : chacun a sa vue en tableau, qui porte les
     * memes nombres que l'infobulle. Rien ne depend de la souris.
     */
    public function testEveryChartHasItsTableTwin(): void
    {
        $crawler = $this->open()->getCrawler();

        self::assertCount(6, $crawler->filter('figure.viz'));
        self::assertCount(6, $crawler->filter('figure.viz details.viz-data table'));
    }

    /**
     * La politique de contenu n'autorise les styles que par nonce : un attribut
     * « style » est refuse en production, et la couleur ou la position qu'il
     * portait disparait sans une erreur. Les graphiques n'en emploient aucun ;
     * ce test garde cette regle pour la page entiere.
     */
    public function testThePageCarriesNoInlineStyleNorScript(): void
    {
        $body = (string) $this->open()->getResponse()->getContent();

        self::assertDoesNotMatchRegularExpression('/<[a-z][^>]*\sstyle=/i', $body);
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/i', $body);
    }

    public function testASeasonCanBeChosenInTheUrl(): void
    {
        $client = $this->open(query: '?saison='.$this->oldSeason);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#titre-stats', 'saison '.$this->oldSeason);
        self::assertSame((string) $this->oldSeason, $client->getCrawler()->filter('#s-saison option[selected]')->attr('value'));
    }

    /**
     * L'URL se modifie a la main : une saison inconnue retombe sur la saison en
     * cours, elle ne leve pas d'erreur.
     */
    public function testAnUnknownSeasonFallsBackToTheCurrentOne(): void
    {
        $this->open(query: '?saison=1850');

        self::assertResponseIsSuccessful();
    }

    public function testEachSeasonColumnLinksToItsSeason(): void
    {
        $crawler = $this->open()->getCrawler();

        $links = $crawler->filter('figure.viz a.viz-col');
        self::assertGreaterThan(0, $links->count());
        self::assertMatchesRegularExpression('#/admin\?saison=\d{4}$#', (string) $links->first()->attr('href'));
    }

    public function testTheTooltipIsFilledByTheStimulusController(): void
    {
        $crawler = $this->open()->getCrawler();

        self::assertSame('chart', $crawler->filter('figure.viz')->first()->attr('data-controller'));
        self::assertCount(6, $crawler->filter('figure.viz .viz-tip[hidden]'));
    }
}
