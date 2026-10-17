<?php

namespace App\Security;

/**
 * Jeton a usage unique d'une requete, pose dans l'en-tete
 * Content-Security-Policy et repris par les gabarits.
 *
 * Un nonce par requete et non par session : un nonce reutilisable ne protege
 * plus de rien des qu'il a fuite une fois. Le service est partage pour la
 * duree de la requete — en mode worker, FrankenPHP le remet a zero entre deux
 * requetes grace a ResetInterface.
 */
final class CspNonce implements \Symfony\Contracts\Service\ResetInterface
{
    private ?string $value = null;

    public function value(): string
    {
        return $this->value ??= base64_encode(random_bytes(16));
    }

    public function reset(): void
    {
        $this->value = null;
    }
}
