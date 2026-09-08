<?php

namespace App;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Taches recurrentes de l'application.
 *
 * Le planificateur tourne dans le conteneur edj-worker, qui consomme deja le
 * transport "async" et consomme aussi "scheduler_default". Aucun crontab
 * dans l'image : la configuration des taches vit dans le code applicatif, se
 * lit avec debug:scheduler et se joue a la main comme n'importe quelle
 * commande console.
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            // Sans etat, un worker redemarre rejouerait les taches dont
            // l'heure est passee depuis son dernier reveil.
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)

            // Menage du calendrier, chaque nuit a 3 h 15 : l'heure est creuse
            // et decalee de l'heure ronde, ou tout le monde planifie.
            ->add(RecurringMessage::cron(
                '15 3 * * *',
                new RunCommandMessage('app:events:purge'),
            ))
        ;
    }
}
