<?php

namespace App\Twig;

use App\Security\CspNonce;
use Twig\Attribute\AsTwigFunction;

/**
 * Expose le nonce de la requete aux gabarits : csp_nonce().
 */
final class CspExtension
{
    public function __construct(private readonly CspNonce $nonce)
    {
    }

    #[AsTwigFunction('csp_nonce')]
    public function nonce(): string
    {
        return $this->nonce->value();
    }
}
