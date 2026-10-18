<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Coupe la session d'une personne restee inactive.
 *
 * Un poste laisse ouvert sur la page des adhesions montre des donnees
 * personnelles — dont celles de mineurs — a qui s'assoit devant. Apres
 * IDLE_SECONDS sans requete, la session est invalidee : la personne se reconnecte.
 *
 * Le cookie de session de PHP, lui, ne dit rien de l'inactivite : sans cela, la
 * session ne s'eteint qu'a la fermeture du navigateur — c'est-a-dire jamais, pour
 * un onglet laisse ouvert.
 *
 * Ne s'applique qu'a une requete qui porte deja un cookie de session : on ne
 * demarre jamais une session pour un visiteur anonyme (voir la protection CSRF
 * sans session).
 */
final class IdleSessionSubscriber
{
    public function __construct(
        #[Autowire('%app.session.idle_seconds%')]
        private readonly int $idleSeconds,
    ) {
    }

    // Avant le pare-feu (priorite 8) : une session invalidee ici n'a plus de
    // jeton a lui lire, la requete arrive anonyme et l'access_control renvoie
    // vers la connexion.
    #[AsEventListener(priority: 20)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasPreviousSession()) {
            return;
        }

        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $lastUsed = $session->getMetadataBag()->getLastUsed();
        if ($lastUsed > 0 && time() - $lastUsed > $this->idleSeconds) {
            $session->invalidate();
            // Une nouvelle session, vide, porte le message : la personne sait
            // pourquoi on lui redemande son mot de passe.
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('error', 'Votre session a expiré après une période d\'inactivité. Reconnectez-vous.');
            }
        }
    }
}
