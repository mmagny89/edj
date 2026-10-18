<?php

namespace App\Enum;

/**
 * Ou en est un bulletin dans le circuit de l'association : depose, valide par
 * le bureau (cotisation encaissee ou formule gratuite), ou annule.
 *
 * Un bulletin annule reste en base : l'association doit pouvoir dire qu'une
 * adhesion a existe puis a ete annulee, et par qui. C'est l'anonymisation,
 * distincte, qui retire les donnees personnelles.
 */
enum MembershipStatus: string
{
    case Pending = 'en_attente';
    case Validated = 'validee';
    case Cancelled = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Validated => 'Validée',
            self::Cancelled => 'Annulée',
        };
    }

    /**
     * Classes de la pastille d'etat dans l'administration.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'border-amber-300 bg-amber-50 text-amber-900',
            self::Validated => 'border-emerald-300 bg-emerald-50 text-emerald-900',
            self::Cancelled => 'border-slate-300 bg-slate-100 text-slate-700',
        };
    }
}
