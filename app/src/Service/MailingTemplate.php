<?php

namespace App\Service;

use App\Entity\MembershipApplication;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Le texte d'un message et ses variables.
 *
 * Les variables s'ecrivent {{prenom}}, {{nom}}, {{numero}}, {{saison}},
 * {{formule}}. Le remplacement est un simple remplacement de texte, jamais le
 * moteur de gabarits : le texte est saisi par un administrateur, et lui laisser
 * la syntaxe de Twig lui donnerait la main sur le serveur.
 *
 * Une variable inconnue n'est pas remplacee, elle est refusee a la saisie
 * (unknownVariables) : « {{prenon}} » parti a deux cents personnes ne se rattrape
 * pas.
 */
final class MailingTemplate
{
    /** Nom de la variable => ce qu'elle designe, tel que l'affiche le formulaire. */
    public const VARIABLES = [
        'prenom' => 'Prénom',
        'nom' => 'Nom',
        'numero' => "Numéro d'adhérent",
        'saison' => 'Saison',
        'formule' => "Formule d'adhésion",
    ];

    /**
     * Les balises sont tolerees dans les accolades : l'editeur coupe « {{prenom}} »
     * d'une balise des qu'une partie du mot est en gras ou en italique, et la
     * variable cessait d'etre reconnue sans qu'aucune erreur ne le signale.
     */
    private const PATTERN = '/\{\{\s*((?:[^{}<]|<[^>]*>){0,120}?)\s*\}\}/u';

    /**
     * Les variables du texte que l'on ne sait pas remplacer, dans l'ordre ou elles
     * apparaissent, sans doublon. « Prénom » et « prenom » sont la meme variable.
     *
     * @return list<string>
     */
    public static function unknownVariables(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $found);

        $unknown = [];
        foreach ($found[1] as $name) {
            if (!isset(self::VARIABLES[self::variableName($name)])) {
                $unknown[trim(strip_tags($name))] = true;
            }
        }

        return array_keys($unknown);
    }

    private static function variableName(string $raw): string
    {
        return MemberDirectory::normalize(html_entity_decode(strip_tags($raw), \ENT_QUOTES, 'UTF-8'));
    }

    /**
     * @param array<string, string> $values nom de variable => valeur
     */
    public static function render(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            static fn (array $match): string => $values[self::variableName($match[1])] ?? $match[0],
            $text,
        );
    }

    /**
     * @return array<string, string>
     */
    public static function valuesFor(MembershipApplication $application, int $season): array
    {
        return [
            'prenom' => (string) $application->getFirstName(),
            'nom' => (string) $application->getLastName(),
            'numero' => (string) $application->getMember()?->getNumber(),
            'saison' => self::seasonLabel($season),
            'formule' => (string) $application->getMembershipType()?->label(),
        ];
    }

    /**
     * Des valeurs d'exemple, pour un essai ou un apercu sans destinataire.
     *
     * @return array<string, string>
     */
    public static function sampleValues(int $season): array
    {
        return [
            'prenom' => 'Camille',
            'nom' => 'Dupont',
            'numero' => 'EDJ-0042',
            'saison' => self::seasonLabel($season),
            'formule' => 'Adhésion individuelle annuelle',
        ];
    }

    /** La saison 2027 va d'octobre 2026 a septembre 2027 : on l'ecrit « 2026-2027 ». */
    public static function seasonLabel(int $season): string
    {
        return ($season - 1).'-'.$season;
    }

    /**
     * Le texte brut en HTML : echappe d'abord, puis seulement les adresses
     * http(s) deviennent des liens et les lignes vides des paragraphes. Rien de ce
     * que contient le texte — variables comprises — ne peut donc y glisser de balise.
     */
    public static function toHtml(string $text): string
    {
        $escaped = htmlspecialchars(str_replace("\r\n", "\n", $text), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $escaped = (string) preg_replace('~(https?://[^\s<]+[^\s<.,;:!?)])~i', '<a href="$1">$1</a>', $escaped);

        $paragraphs = [];
        foreach (preg_split('/\n{2,}/', trim($escaped)) ?: [] as $paragraph) {
            $paragraphs[] = '<p style="margin:0 0 14px;">'.nl2br($paragraph, false).'</p>';
        }

        return implode("\n", $paragraphs);
    }

    /**
     * Le corps d'un message, en HTML sur : celui de l'editeur est nettoye, un texte
     * simple (sans balise) est converti comme avant. Tout passe par ici avant d'etre
     * affiche, stocke ou envoye — on ne fait jamais confiance au champ.
     */
    public static function normalize(string $body): string
    {
        $body = str_replace("\r\n", "\n", $body);
        if (!preg_match('~</?[a-z][^>]*>~i', $body)) {
            return self::toHtml($body);
        }

        return self::inlineStyles(self::sanitizer()->sanitize($body));
    }

    /**
     * Les variables remplacees dans du HTML : les valeurs y sont echappees, un
     * nom saisi par un adherent ne peut pas y glisser de balise.
     *
     * @param array<string, string> $values
     */
    public static function renderHtml(string $html, array $values): string
    {
        $escaped = array_map(static fn (string $v): string => htmlspecialchars($v, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'), $values);

        return self::render($html, $escaped);
    }

    /**
     * Le texte brut d'un HTML nettoye : la version « texte » du courriel, et ce
     * qui sert a verifier qu'un message n'est pas vide.
     */
    public static function toText(string $html): string
    {
        $text = (string) preg_replace_callback(
            '~<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>~is',
            static function (array $m): string {
                $label = trim(strip_tags($m[2]));
                $url = html_entity_decode($m[1], \ENT_QUOTES, 'UTF-8');

                return '' === $label || $label === $url ? $url : $label.' ('.$url.')';
            },
            $html,
        );
        $text = (string) preg_replace(['~<br\s*/?>~i', '~<li\b[^>]*>~i', '~</(p|div|h2|ul|ol)>~i'], ["\n", "\n- ", "\n\n"], $text);
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES, 'UTF-8');

        return trim((string) preg_replace("~\n{3,}~", "\n\n", str_replace("\xC2\xA0", ' ', $text)));
    }

    private static function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('p', ['style'])
            ->allowElement('div', ['style'])
            ->allowElement('h2', ['style'])
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer');

        return new HtmlSanitizer($config);
    }

    /**
     * Les clients de messagerie ignorent les feuilles de style : l'espacement et
     * l'alignement s'ecrivent en ligne. Du style saisi, seul l'alignement du texte
     * survit.
     */
    private static function inlineStyles(string $html): string
    {
        $base = [
            'p' => 'margin:0 0 14px;',
            'div' => 'margin:0 0 14px;',
            'h2' => 'margin:20px 0 10px;font-size:18px;color:#171922;',
            'ul' => 'margin:0 0 14px;padding-left:22px;',
            'ol' => 'margin:0 0 14px;padding-left:22px;',
            'li' => 'margin:0 0 4px;',
        ];

        // Liens aux couleurs de l'association, soulignes pour ne pas dependre de la couleur seule.
        $html = (string) preg_replace('~<a (?=href=)~i', '<a style="color:#255db0;text-decoration:underline;" ', $html);

        return (string) preg_replace_callback(
            '~<(p|div|h2|ul|ol|li)\b([^>]*)>~i',
            static function (array $m) use ($base): string {
                $tag = strtolower($m[1]);
                $align = preg_match('~text-align:\s*(left|center|right|justify)~i', $m[2], $a) ? 'text-align:'.strtolower($a[1]).';' : '';

                return '<'.$tag.' style="'.$align.$base[$tag].'">';
            },
            $html,
        );
    }
}
