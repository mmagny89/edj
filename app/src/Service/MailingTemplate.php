<?php

namespace App\Service;

use App\Entity\MembershipApplication;

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

    private const PATTERN = '/\{\{\s*([^{}]{0,40}?)\s*\}\}/u';

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
            if (!isset(self::VARIABLES[MemberDirectory::normalize($name)])) {
                $unknown[trim($name)] = true;
            }
        }

        return array_keys($unknown);
    }

    /**
     * @param array<string, string> $values nom de variable => valeur
     */
    public static function render(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            static fn (array $match): string => $values[MemberDirectory::normalize($match[1])] ?? $match[0],
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
}
