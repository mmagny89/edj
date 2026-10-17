<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tant qu'un compte porte un mot de passe provisoire, la seule page accessible
 * est celle qui le change (et la deconnexion). Sans cela, le mot de passe
 * communique par un administrateur resterait valable indefiniment.
 */
final class MustChangePasswordSubscriber
{
    private const ALLOWED_ROUTES = ['app_account', 'app_logout'];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[AsEventListener(priority: 0)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/admin')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->mustChangePassword()) {
            return;
        }

        if (\in_array($request->attributes->get('_route'), self::ALLOWED_ROUTES, true)) {
            return;
        }

        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add('error', 'Vous utilisez un mot de passe provisoire : choisissez le vôtre pour continuer.');
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_account')));
    }
}
