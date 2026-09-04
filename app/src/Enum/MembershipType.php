<?php

namespace App\Enum;

/**
 * Formules d'adhésion, telles qu'elles figurent sur le bulletin papier.
 */
enum MembershipType: string
{
    case Individual = 'individuelle';
    case Family = 'famille';
    case Consumer = 'consommateur';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Adhésion individuelle annuelle',
            self::Family => 'Adhésion famille annuelle',
            self::Consumer => 'Adhésion consommateur',
        };
    }

    public function priceLabel(): string
    {
        return match ($this) {
            self::Individual => '10 € par an',
            self::Family => '10 € par an, puis 5 € par personne supplémentaire',
            self::Consumer => "Gratuite à l'année, 1 € par événement",
        };
    }
}
