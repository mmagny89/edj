<?php

namespace App\Service;

use App\Entity\Member;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Liens d'acces a l'espace adherent : fabrication et verification.
 *
 * Un adherent n'a pas de compte — il n'en veut pas, et l'association n'a pas a
 * garder un mot de passe de plus. Il demande un lien avec son numero, le lien
 * part a l'adresse de son bulletin, et c'est l'acces a cette boite qui fait
 * l'authentification. Le lien est signe (secret de l'application) et expire :
 * reexpedie ou retrouve dans un historique, il ne vaut plus rien.
 *
 * Le numero est devinable (EDJ-0001) : c'est sans consequence, puisque la
 * demande n'ouvre rien et se contente d'ecrire au titulaire.
 */
final class MemberAccessLink
{
    /**
     * Duree de validite. Assez pour relever ses courriels sans se presser,
     * trop court pour qu'un lien qui traine serve encore demain.
     */
    public const LIFETIME = '+30 minutes';

    /**
     * Duree d'un lien de renouvellement : plus longue, parce qu'il ouvre un
     * formulaire a remplir, signer et relire — un lien de trente minutes
     * expirerait sous la main de qui prend le temps de verifier ses donnees.
     * Elle reste celle d'une journee de travail, pas d'un historique de mails.
     */
    public const RENEWAL_LIFETIME = '+2 hours';

    public function __construct(
        private readonly UriSigner $signer,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function sheetUrl(Member $member, ?\DateTimeImmutable $expiresAt = null): string
    {
        return $this->sign('member_space', $member, $expiresAt);
    }

    public function cardUrl(Member $member, ?\DateTimeImmutable $expiresAt = null): string
    {
        return $this->sign('member_card', $member, $expiresAt);
    }

    public function renewalUrl(Member $member, ?\DateTimeImmutable $expiresAt = null): string
    {
        return $this->sign('membership_renewal', $member, $expiresAt ?? new \DateTimeImmutable(self::RENEWAL_LIFETIME));
    }

    public function isValid(Request $request): bool
    {
        return $this->signer->checkRequest($request);
    }

    /**
     * Echeance portee par la requete en cours, pour que la carte proposee sur
     * la page expire avec le lien qui y a mene : un lien de trente minutes ne
     * doit pas en engendrer un autre de trente minutes de plus, indefiniment.
     */
    public function expirationOf(Request $request): \DateTimeImmutable
    {
        $expiration = $request->query->getInt('_expiration');

        return $expiration > 0
            ? new \DateTimeImmutable('@'.$expiration)
            : new \DateTimeImmutable(self::LIFETIME);
    }

    private function sign(string $route, Member $member, ?\DateTimeImmutable $expiresAt): string
    {
        return $this->signer->sign(
            $this->urls->generate($route, ['number' => $member->getNumber()], UrlGeneratorInterface::ABSOLUTE_URL),
            $expiresAt ?? new \DateTimeImmutable(self::LIFETIME),
        );
    }
}
