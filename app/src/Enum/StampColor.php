<?php

namespace App\Enum;

/**
 * Couleur du tampon de saison. Deux teintes qui alternent d'une annee a
 * l'autre — voir StampSymbol pour le principe.
 */
enum StampColor: string
{
    case Orange = 'orange';
    case Blue = 'bleu';

    public function label(): string
    {
        return match ($this) {
            self::Orange => 'Orange',
            self::Blue => 'Bleu',
        };
    }

    /**
     * Teinte imprimee. Les deux passent les 4,5:1 de WCAG AA sur du blanc : la
     * carte se lit aussi en photocopie pale ou sous un mauvais eclairage.
     */
    public function hex(): string
    {
        return match ($this) {
            self::Orange => '#b4480b',
            self::Blue => '#1d4ed8',
        };
    }

    public function next(): self
    {
        return self::Orange === $this ? self::Blue : self::Orange;
    }
}
