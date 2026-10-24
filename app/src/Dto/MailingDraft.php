<?php

namespace App\Dto;

use App\Service\MailingTemplate;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un message en cours de redaction : la saison, a qui il va, ce qu'il dit.
 *
 * `expected` est le nombre de messages que l'apercu a annonce. Il revient avec
 * le formulaire d'envoi : si la liste a change entre-temps (une adhesion validee,
 * un adherent desinscrit), l'envoi est refuse plutot que de partir vers une liste
 * que personne n'a relue.
 */
final class MailingDraft
{
    #[Assert\NotNull(message: 'Choisissez la saison.')]
    public ?int $season = null;

    public bool $includeAnnual = true;

    public bool $includeConsumers = false;

    #[Assert\NotBlank(message: "L'objet est obligatoire.")]
    #[Assert\Length(max: 150, maxMessage: "L'objet ne peut pas dépasser {{ limit }} caractères.")]
    public string $subject = '';

    #[Assert\NotBlank(message: 'Le message est vide.')]
    #[Assert\Length(max: 20000, maxMessage: 'Le message ne peut pas dépasser {{ limit }} caractères.')]
    public string $body = '';

    public ?int $expected = null;

    #[Assert\Callback]
    public function validateContent(ExecutionContextInterface $context): void
    {
        if (!$this->includeAnnual && !$this->includeConsumers) {
            $context->buildViolation('Choisissez à qui écrire : adhérents à l\'année, consommateurs, ou les deux.')
                ->atPath('includeAnnual')
                ->addViolation();
        }

        // Un retour a la ligne dans l'objet ouvrirait une nouvelle ligne d'en-tete.
        if (preg_match('/[\r\n]/', $this->subject)) {
            $context->buildViolation("L'objet tient sur une seule ligne.")->atPath('subject')->addViolation();
        }

        foreach (['subject' => $this->subject, 'body' => $this->body] as $field => $text) {
            $unknown = MailingTemplate::unknownVariables($text);
            if ([] !== $unknown) {
                $context->buildViolation('Variable inconnue : {{ names }}. Variables possibles : {{ known }}.')
                    ->setParameter('{{ names }}', implode(', ', array_map(static fn (string $n) => '{{'.$n.'}}', $unknown)))
                    ->setParameter('{{ known }}', implode(', ', array_map(static fn (string $n) => '{{'.$n.'}}', array_keys(MailingTemplate::VARIABLES))))
                    ->atPath($field)
                    ->addViolation();
            }
        }
    }
}
