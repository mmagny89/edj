<?php

namespace App\Enum;

/**
 * Symbole du tampon de saison, appose sur la carte d'adherent en face de
 * l'annee reglee.
 *
 * Le symbole et la couleur (voir StampColor) changent chaque annee et tournent
 * a tour de role : c'est ce qui permet de reconnaitre d'un coup d'oeil, a
 * l'entree d'un evenement, une carte a jour d'une carte de l'an dernier. Le
 * couple est choisi par le super-administrateur, jamais deduit : l'association
 * doit pouvoir en changer sans toucher au code.
 */
enum StampSymbol: string
{
    case Star = 'etoile';
    case Crown = 'couronne';
    case Heart = 'coeur';

    public function label(): string
    {
        return match ($this) {
            self::Star => 'Étoile',
            self::Crown => 'Couronne',
            self::Heart => 'Cœur',
        };
    }

    /**
     * Caractere imprime sur la carte. Les trois figurent dans DejaVu Sans, la
     * police livree avec Dompdf : aucune ressource a embarquer.
     */
    public function glyph(): string
    {
        return match ($this) {
            self::Star => '★',
            self::Crown => '♛',
            self::Heart => '♥',
        };
    }

    /**
     * Le symbole qui suit dans la rotation, propose a la creation d'une saison.
     */
    public function next(): self
    {
        $cases = self::cases();

        return $cases[(array_search($this, $cases, true) + 1) % \count($cases)];
    }
}
