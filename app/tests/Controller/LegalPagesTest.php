<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les pages legales decrivent ce que le site fait : si le site change et que ses
 * pages ne suivent pas, elles mentent a l'adherent. Ces tests ne garantissent pas
 * qu'elles disent vrai — personne ne le peut a leur place — mais retiennent les
 * affirmations qui engagent l'association, pour qu'on ne les retire pas sans le vouloir.
 */
final class LegalPagesTest extends WebTestCase
{
    private function privacy(): string
    {
        $client = static::createClient();
        $client->request('GET', '/donnees-personnelles');
        self::assertResponseIsSuccessful();

        return preg_replace('/\s+/', ' ', strip_tags((string) $client->getResponse()->getContent()));
    }

    public function testBothPagesAreReachableWithoutLogin(): void
    {
        $client = static::createClient();
        foreach (['/donnees-personnelles', '/mentions-legales'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }
    }

    /**
     * Ce que le site collecte au-dela du bulletin d'origine : numero, empreinte
     * d'identite, historique des decisions, moyen de reglement.
     */
    public function testItNamesWhatTheSiteAddsToAFiche(): void
    {
        $text = $this->privacy();

        self::assertStringContainsString("numéro d'adhérent", $text);
        self::assertStringContainsString('empreinte', $text);
        self::assertStringContainsString('historique des décisions', $text);
        self::assertStringContainsString('moyen de règlement', $text);
    }

    /**
     * La transmission a Philibert est la seule communication a un tiers a son
     * propre compte : ce qu'elle contient, et qui elle exclut, doivent s'y lire.
     */
    public function testItStatesPreciselyWhatPhilibertReceives(): void
    {
        $text = $this->privacy();

        self::assertStringContainsString("date d'adhésion", $text);
        self::assertStringContainsString('consommateur', $text);
        self::assertStringContainsString('saison en cours', $text);
    }

    public function testItDescribesTheMembersAccessByEmailLink(): void
    {
        $text = $this->privacy();

        self::assertStringContainsString('lien personnel', $text);
        self::assertStringContainsString('ni compte ni mot de passe', $text);
        self::assertStringContainsString('trente minutes', $text);
    }

    public function testItNamesTheProcessorsAndTheRetention(): void
    {
        $text = $this->privacy();

        foreach (['Hostinger', 'Brevo', 'trois ans', 'sauvegardes', 'quatorze'] as $needle) {
            self::assertStringContainsStringIgnoringCase($needle, $text);
        }
    }

    /**
     * Les pages ne doivent plus pretendre que le site ne pose qu'un cookie de
     * session a l'envoi d'un formulaire : c'est faux depuis la protection sans
     * session, et la page doit parler du stockage local des preferences.
     */
    public function testItDescribesTheRealCookiesAndLocalStorage(): void
    {
        $text = $this->privacy();

        self::assertStringNotContainsString("cookie de session strictement nécessaire est posé lors de l'envoi", $text);
        self::assertStringContainsString("préférences d'affichage", $text);
    }

    /**
     * Les liens qui renvoient vers les parcours decrits doivent exister : la page
     * les cite par leur adresse reelle.
     */
    public function testItLinksToTheJourneysItDescribes(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/donnees-personnelles');

        foreach (['/adhesion/bulletin', '/adhesion/renouvellement', '/adhesion/ma-fiche'] as $path) {
            self::assertCount(1, $crawler->filter(sprintf('main a[href="%s"]', $path)), $path);
        }
    }

    public function testTheLegalNoticeMentionsTheEditorAndTheHost(): void
    {
        $client = static::createClient();
        $client->request('GET', '/mentions-legales');
        $text = (string) $client->getResponse()->getContent();

        foreach (['Envie de Jouer', 'W893006812', 'Hostinger', 'Directeur de la publication'] as $needle) {
            self::assertStringContainsString($needle, $text);
        }
    }
}
