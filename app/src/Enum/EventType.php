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

    /**
     * Titre pose d'office quand le champ est laisse vide : les soirees du
     * mardi et les apres-midi du 3e samedi portent toujours le meme nom, le
     * ressaisir a chaque date n'apporte rien et finit par produire des
     * variantes ("Soiree du mardi", "Soiree jeux mardi"...).
     */
    public function defaultTitle(): ?string
    {
        return match ($this) {
            self::Weekly => 'Soirée jeux du mardi',
            self::Monthly => 'Après-midi ludique',
            self::Special => null,
        };
    }

    /**
     * Horaire pose d'office, meme raison que pour le titre. Une
     * manifestation n'en a pas : sa duree change a chaque fois.
     */
    public function defaultTimeLabel(): ?string
    {
        return match ($this) {
            self::Weekly => '18h30 - 00h',
            self::Monthly => '14h - 18h',
            self::Special => null,
        };
    }

    /**
     * Description posee d'office quand le champ est laisse vide. Le lieu
     * s'affichant deja sur sa propre ligne sous la carte, elle n'a pas a le
     * repeter : elle dit ce qu'on y fait.
     *
     * Une manifestation n'en a pas : c'est justement ce qui la distingue
     * d'une date recurrente.
     */
    public function defaultDescription(): ?string
    {
        return match ($this) {
            self::Weekly => 'Une soirée de jeux : tables ouvertes, ludothèque et explications.',
            self::Monthly => 'Une après-midi de jeux autour de la ludothèque.',
            self::Special => null,
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
