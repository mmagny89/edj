<?php

namespace App\Enum;

/**
 * D'ou vient un bulletin : rempli par l'adherent sur le site, saisi par un
 * administrateur d'apres une fiche papier remise a l'association, ou repris d'un
 * fichier Excel d'adherents existants.
 */
enum MembershipSource: string
{
    case Online = 'en_ligne';
    case Paper = 'papier';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'En ligne',
            self::Paper => 'Fiche papier',
            self::Import => 'Import',
        };
    }
}
