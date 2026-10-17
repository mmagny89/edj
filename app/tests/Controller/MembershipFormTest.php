<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MembershipFormTest extends WebTestCase
{
    /**
     * Les champs que le bulletin doit signaler comme obligatoires au chargement.
     * Les champs conditionnels — membre de la famille, responsable legal — ne le
     * deviennent qu'a l'ecran, selon la formule et l'age.
     */
    public function testRequiredFieldsAreFlaggedWithTheRequiredClass(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/adhesion/bulletin');

        $required = $crawler->filter('.membership-form label.required')->each(
            static fn ($label) => trim(preg_replace('/\s+/', ' ', $label->text())),
        );

        foreach ([
            'Nom', 'Prénom', 'Date de naissance', 'Code postal', 'Ville', 'Adresse e-mail',
            "Formule d'adhésion", 'Fait à',
        ] as $label) {
            self::assertContains($label, $required, $label);
        }
        self::assertCount(11, $required, 'Huit champs, deux déclarations et la signature : onze.');
    }

    public function testOptionalFieldsAreNotFlagged(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/adhesion/bulletin');

        $required = $crawler->filter('.membership-form label.required')->each(
            static fn ($label) => trim($label->text()),
        );

        foreach (['Adresse', 'Téléphone'] as $optional) {
            self::assertNotContains($optional, $required, $optional);
        }
    }

    public function testConditionalFieldsAreMarkedByTheServerRulesToo(): void
    {
        $client = static::createClient();
        $client->request('GET', '/adhesion/bulletin');

        self::assertSelectorExists('[data-controller="membership-type"]');
        self::assertSelectorTextContains('.form-required-note', 'Champ obligatoire');
    }
}
