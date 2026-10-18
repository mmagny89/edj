<?php

namespace App\Enum;

/**
 * Comment la cotisation a ete reglee. Nul pour une adhesion consommateur,
 * gratuite a l'annee.
 */
enum PaymentMethod: string
{
    case Transfer = 'virement';
    case Cash = 'especes';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => 'Virement',
            self::Cash => 'Espèces',
            self::Cheque => 'Chèque',
        };
    }
}
