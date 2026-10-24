<?php

namespace App\Enum;

/**
 * Ce qui s'inscrit dans l'historique d'un bulletin : les decisions du bureau,
 * pas la vie du bulletin. Qui a valide un encaissement, qui a annule une
 * adhesion, qui a efface les donnees d'une personne — et quand.
 */
enum MembershipEventType: string
{
    case Validated = 'validee';
    case Cancelled = 'annulee';
    case Reopened = 'rouverte';
    case Anonymized = 'anonymisee';
    case Upgraded = 'formule';
    case Edited = 'modifiee';

    public function label(): string
    {
        return match ($this) {
            self::Validated => 'Adhésion validée',
            self::Cancelled => 'Adhésion annulée',
            self::Reopened => 'Remise en attente',
            self::Anonymized => 'Bulletin anonymisé',
            self::Upgraded => 'Formule modifiée',
            self::Edited => 'Fiche corrigée',
        };
    }
}
