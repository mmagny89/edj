<?php

namespace App\Service;

/**
 * Ce que donne le choix des destinataires : les messages a envoyer, et pourquoi
 * des adherents de la saison n'en recoivent pas — le bureau doit pouvoir
 * expliquer l'ecart entre « 120 adherents » et « 96 messages ».
 */
final readonly class MailingAudienceResult
{
    /**
     * @param list<MailingRecipient> $recipients un par adresse distincte
     */
    public function __construct(
        public array $recipients,
        public int $members,
        public int $skippedWithoutAddress,
        public int $skippedOptedOut,
        public int $sharedAddress,
    ) {
    }

    public function count(): int
    {
        return \count($this->recipients);
    }
}
