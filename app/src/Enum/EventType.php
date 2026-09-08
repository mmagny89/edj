<?php

namespace App\Enum;

/**
 * Nature d'un evenement, telle qu'elle apparait dans les filtres du
 * calendrier de la page d'accueil.
 *
 * Les mardis et les 3e samedis sont des regles de recurrence, calculees par
 * le front pour toute la saison : ils n'ont pas a etre saisis un par un. Ces
 * deux valeurs restent disponibles ici pour saisir une exception — une soiree
 * deplacee, un samedi supplementaire — qui vient alors s'ajouter aux dates
 * calculees.
 */
enum EventType: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Special = 'special';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Mardi soir',
            self::Monthly => '3e samedi',
            self::Special => 'Manifestation',
        };
    }

    public function formLabel(): string
    {
        return match ($this) {
            self::Weekly => 'Soirée du mardi (exception ou date ajoutée)',
            self::Monthly => 'Après-midi du 3e samedi (exception ou date ajoutée)',
            self::Special => 'Manifestation — nuit du jeu, festival, week-end…',
        };
    }
}
