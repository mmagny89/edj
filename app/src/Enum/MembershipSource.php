<?php

namespace App\Enum;

/**
 * D'ou vient un bulletin : rempli par l'adherent sur le site, ou saisi par un
 * administrateur d'apres une fiche papier remise a l'association.
 */
enum MembershipSource: string
{
    case Online = 'en_ligne';
    case Paper = 'papier';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'En ligne',
            self::Paper => 'Fiche papier',
        };
    }
}
