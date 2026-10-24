<?php

namespace App\Service;

use App\Entity\Member;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Le lien de desinscription place en bas de chaque message.
 *
 * Signe, sans date d'expiration : il doit fonctionner dans un courriel ouvert
 * des mois plus tard — un lien perime ne desinscrit personne, et la personne
 * ecrirait de nouveau pour qu'on arrete. Il ne donne acces a rien d'autre qu'a
 * ce choix : ni fiche, ni coordonnees.
 */
final class MailingUnsubscribeLink
{
    public function __construct(
        private readonly UriSigner $signer,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function url(Member $member): string
    {
        return $this->signer->sign(
            $this->urls->generate('mailing_unsubscribe', ['number' => $member->getNumber()], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    public function isValid(Request $request): bool
    {
        return $this->signer->checkRequest($request);
    }
}
