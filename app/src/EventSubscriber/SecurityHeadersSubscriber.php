<?php

namespace App\EventSubscriber;

use App\Security\CspNonce;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * En-tetes de securite de toutes les reponses, et en plus, sur l'espace
 * d'administration et la connexion, ceux qui interdisent le cache et
 * l'indexation.
 *
 * Poses ici et non dans le Caddyfile : une politique de contenu a nonce
 * demande une valeur differente a chaque requete, que Caddy ne sait pas
 * fabriquer.
 */
final class SecurityHeadersSubscriber
{
    public function __construct(
        private readonly CspNonce $nonce,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    #[AsEventListener]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        if ($this->isPrivatePath($request->getPathInfo())) {
            // Une page d'administration ne se conserve ni dans le cache du
            // navigateur ni dans un cache intermediaire : apres deconnexion, le
            // bouton « retour » ne doit pas la reafficher.
            $headers->set('Cache-Control', 'no-store, private');
            $headers->set('Pragma', 'no-cache');
            $headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $headers->set('Referrer-Policy', 'same-origin');
        }

        // En developpement, la barre du profileur injecte ses propres scripts
        // et styles en ligne : une CSP stricte, comme HSTS sur un domaine local,
        // la rendrait muette ou genante.
        if ($this->debug) {
            return;
        }

        // HSTS : le navigateur ne reessaie plus jamais en HTTP. Sans
        // includeSubDomains ni preload — qui engageraient tout le domaine,
        // possiblement partage avec d'autres sites.
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        $headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            // Turbo cree une feuille de style pour sa barre de progression,
            // avec le nonce lu dans <meta name="csp-nonce">.
            sprintf("style-src 'self' 'nonce-%s'", $this->nonce->value()),
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));
    }

    private function isPrivatePath(string $path): bool
    {
        return '/login' === $path
            || '/logout' === $path
            || '/admin' === $path
            || str_starts_with($path, '/admin/');
    }
}
