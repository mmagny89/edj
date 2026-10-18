<?php

namespace App\Twig;

use App\Service\MembershipSeasons;
use Twig\Attribute\AsTwigFunction;

/**
 * Expose la saison d'une date aux gabarits : season(date).
 *
 * Le calcul — une adhesion prise a partir du 1er octobre vaut pour l'annee
 * suivante — reste dans MembershipSeasons : un gabarit qui le refaisait en
 * Twig le ferait une fois de plus, et un jour de travers.
 */
final class SeasonExtension
{
    #[AsTwigFunction('season')]
    public function season(\DateTimeImmutable $date): int
    {
        return MembershipSeasons::of($date);
    }
}
