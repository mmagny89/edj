<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Demande de generation des dates recurrentes d'une saison.
 *
 * Un objet dedie plutot que des champs non mappes : les regles de coherence
 * (bornes dans le bon ordre, amplitude raisonnable, au moins une recurrence)
 * vivent ainsi avec les donnees qu'elles contraignent, et restent valables
 * si la generation est un jour declenchee autrement que par ce formulaire.
 */
final class SeasonGenerationRequest
{
    /**
     * Deux ans : de quoi couvrir la saison en cours et la suivante. Au-dela,
     * c'est une faute de frappe sur l'annee — et quelques centaines de lignes
     * creees d'un clic.
     */
    public const MAX_YEARS = 2;

    #[Assert\NotNull(message: 'La date de début est obligatoire.')]
    public ?\DateTimeImmutable $from = null;

    #[Assert\NotNull(message: 'La date de fin est obligatoire.')]
    public ?\DateTimeImmutable $to = null;

    public bool $weekly = true;

    public bool $monthly = true;

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (!$this->weekly && !$this->monthly) {
            $context->buildViolation('Choisissez au moins un rendez-vous à générer.')
                ->atPath('weekly')
                ->addViolation();
        }

        if (null === $this->from || null === $this->to) {
            return;
        }

        if ($this->to < $this->from) {
            $context->buildViolation('La date de fin ne peut pas précéder la date de début.')
                ->atPath('to')
                ->addViolation();

            return;
        }

        if ($this->to > $this->from->modify(sprintf('+%d years', self::MAX_YEARS))) {
            $context->buildViolation(sprintf(
                'La période ne peut pas dépasser %d ans. Vérifiez l\'année de la date de fin.',
                self::MAX_YEARS,
            ))->atPath('to')->addViolation();
        }
    }
}
