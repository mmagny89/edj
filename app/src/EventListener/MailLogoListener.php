<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Joint le logo de l'association a tout courriel dont le HTML le reference
 * (src="cid:logo", dans emails/base.html.twig) : un seul endroit, aucun
 * expediteur a modifier. Beaucoup de clients bloquent les images distantes,
 * jamais celles qui sont jointes au message.
 */
final class MailLogoListener
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/icons/icon-192.png')]
        private readonly string $logoPath,
    ) {
    }

    #[AsEventListener]
    public function onMessage(MessageEvent $event): void
    {
        // En file, le message est un clone jete : le logo se joint a l'envoi reel.
        if ($event->isQueued()) {
            return;
        }

        $message = $event->getMessage();
        if (!$message instanceof Email || !str_contains((string) $message->getHtmlBody(), 'cid:logo')) {
            return;
        }

        $message->embedFromPath($this->logoPath, 'logo', 'image/png');
    }
}
