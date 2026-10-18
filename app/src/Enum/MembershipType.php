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
     * Ce que doit la personne qui choisit cette formule, en euros, a
     * encaisser a la validation. Nul pour la formule consommateur, gratuite a
     * l'annee et reglee evenement par evenement.
     *
     * A ne pas confondre avec priceLabel(), qui decrit le tarif de la formule
     * pour un foyer entier : « 10 € par an, puis 5 € par personne
     * supplementaire » se lisait comme 10 € sur une fiche famille, alors que
     * la personne ne doit que 5 €.
     */
    public function amountDue(): ?int
    {
        return match ($this) {
            self::Individual => 10,
            self::Family => 5,
            self::Consumer => null,
        };
    }

    /**
     * Le montant du, ecrit pour etre lu : « 5 € » ou, faute de montant, ce que
     * paie reellement la personne.
     */
    public function amountDueLabel(): string
    {
        return null !== $this->amountDue()
            ? $this->amountDue().' €'
            : "Gratuite à l'année, puis 1 € par évènement";
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
