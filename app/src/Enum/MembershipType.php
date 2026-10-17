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

    /**
     * « Pour qui ? » : la phrase qui permet de choisir sans connaitre les noms
     * des formules. Affichee dans le bulletin en ligne.
     */
    public function audience(): string
    {
        return match ($this) {
            self::Individual => 'Je viens seul(e), ou je suis le premier de ma famille à adhérer.',
            self::Family => "Un membre de ma famille a déjà pris l'adhésion à 10 € : je rejoins son adhésion.",
            self::Consumer => 'Je veux seulement payer à chaque événement, sans cotisation annuelle.',
        };
    }

    /**
     * Ce que paie la personne qui choisit cette formule, en une ligne.
     */
    public function yourPrice(): string
    {
        return match ($this) {
            self::Individual => '10 € pour la saison',
            self::Family => '5 € pour la saison',
            self::Consumer => 'Adhésion gratuite, puis 1 € par événement',
        };
    }
}
