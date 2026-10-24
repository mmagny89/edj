<?php

namespace App\Tests\Service;

use App\Service\MailingTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MailingTemplateTest extends TestCase
{
    public function testKnownVariablesAreReplaced(): void
    {
        $text = MailingTemplate::render(
            'Bonjour {{prenom}} {{ nom }}, adhérent n° {{numero}} pour la saison {{saison}}.',
            MailingTemplate::sampleValues(2027),
        );

        self::assertSame('Bonjour Camille Dupont, adhérent n° EDJ-0042 pour la saison 2026-2027.', $text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function spellings(): iterable
    {
        yield 'minuscules' => ['{{prenom}}'];
        yield 'espaces' => ['{{  prenom  }}'];
        yield 'majuscule' => ['{{Prenom}}'];
        yield 'accent' => ['{{prénom}}'];
        yield 'tout en majuscules' => ['{{ PRÉNOM }}'];
    }

    #[DataProvider('spellings')]
    public function testAVariableIsRecognisedWhateverItsSpelling(string $variable): void
    {
        self::assertSame([], MailingTemplate::unknownVariables($variable));
        self::assertSame('Camille', MailingTemplate::render($variable, MailingTemplate::sampleValues(2027)));
    }

    public function testAnUnknownVariableIsReportedOnceAndNotReplaced(): void
    {
        $text = 'Bonjour {{prenon}}, {{prenon}} et {{ age }}.';

        self::assertSame(['prenon', 'age'], MailingTemplate::unknownVariables($text));
        self::assertSame($text, MailingTemplate::render($text, MailingTemplate::sampleValues(2027)));
    }

    /**
     * Le texte est saisi par un administrateur : rien de ce qu'il ecrit ne doit
     * etre interprete comme du code de gabarit.
     */
    public function testTwigSyntaxIsNeverExecuted(): void
    {
        $text = MailingTemplate::render("{{ 7*7 }} {% if true %}x{% endif %} {{ _self }}", MailingTemplate::sampleValues(2027));

        self::assertStringContainsString('{{ 7*7 }}', $text);
        self::assertStringNotContainsString('49', $text);
        self::assertSame(['7*7', '_self'], MailingTemplate::unknownVariables($text));
    }

    public function testTheHtmlNeverCarriesMarkupFromTheTextOrTheValues(): void
    {
        $html = MailingTemplate::toHtml(MailingTemplate::render(
            "Bonjour {{prenom}}\n\n<script>alert(1)</script>",
            ['prenom' => '<b onmouseover="x">Zoé</b>'],
        ));

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<b onmouseover', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAddressesBecomeLinksAndBlankLinesBecomeParagraphs(): void
    {
        $html = MailingTemplate::toHtml("Première ligne\nsuite\n\nVoir https://edj.mmagny.fr/adhesion, merci.");

        self::assertSame(2, substr_count($html, '<p '));
        self::assertStringContainsString('Première ligne<br>', $html);
        self::assertStringContainsString('<a href="https://edj.mmagny.fr/adhesion">https://edj.mmagny.fr/adhesion</a>,', $html);
    }

    public function testOnlyHttpAddressesBecomeLinks(): void
    {
        $html = MailingTemplate::toHtml('javascript:alert(1) et ftp://exemple.test');

        self::assertStringNotContainsString('<a ', $html);
    }

    public function testTheSeasonIsWrittenAsTheTwoYearsItCovers(): void
    {
        self::assertSame('2026-2027', MailingTemplate::seasonLabel(2027));
    }
}
