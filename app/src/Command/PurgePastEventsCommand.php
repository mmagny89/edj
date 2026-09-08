<?php

namespace App\Command;

use App\Repository\EventRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Supprime les evenements termines. Jouee chaque nuit par le planificateur
 * (App\Scheduler\MainSchedule), et disponible a la main.
 *
 * La suppression est definitive : il n'y a pas d'archive. Le delai de
 * retention (--days) existe pour laisser le temps de s'apercevoir d'une
 * erreur de date avant que la ligne ne parte.
 */
#[AsCommand(
    name: 'app:events:purge',
    description: 'Supprime les évènements dont la date est passée.',
)]
final class PurgePastEventsCommand extends Command
{
    public function __construct(private readonly EventRepository $events)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'days',
                null,
                InputOption::VALUE_REQUIRED,
                "Nombre de jours a conserver apres la fin de l'evenement",
                '0',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait supprime, sans rien supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = filter_var($input->getOption('days'), \FILTER_VALIDATE_INT);
        if (false === $days || $days < 0) {
            $io->error('--days attend un entier positif ou nul.');

            return Command::INVALID;
        }

        // Un evenement du jour n'est jamais supprime : la borne est le debut
        // de la journee, retranche du delai de retention.
        $before = new \DateTimeImmutable('today');
        if ($days > 0) {
            $before = $before->modify(sprintf('-%d days', $days));
        }

        $concerned = $this->events->findEndedBefore($before);

        if ([] === $concerned) {
            $io->success('Aucun évènement à supprimer.');

            return Command::SUCCESS;
        }

        foreach ($concerned as $event) {
            $io->writeln(sprintf(
                '  %s — %s',
                $event->getStartsAt()?->format('d/m/Y') ?? '?',
                (string) $event->getTitle(),
            ));
        }

        if ($input->getOption('dry-run')) {
            $io->note(sprintf('%d évènement(s) seraient supprimés. Rien n\'a été touché.', \count($concerned)));

            return Command::SUCCESS;
        }

        $deleted = $this->events->deleteEndedBefore($before);
        $io->success(sprintf('%d évènement(s) supprimé(s).', $deleted));

        return Command::SUCCESS;
    }
}
