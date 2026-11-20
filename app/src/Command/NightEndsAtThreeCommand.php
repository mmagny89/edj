<?php

namespace App\Command;

use App\Entity\Event;
use App\Enum\EventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande a usage unique : les Nuits du Jeu se terminent a 3h et non a 4h.
 *
 * L'horaire d'un evenement est un texte libre (« 15h - 4h ») : la commande
 * remplace l'heure de fin « 4h » par « 3h » dans celui des evenements de type
 * Nuit du jeu. Les evenements de type Manifestation dont le titre contient
 * « Nuit du Jeu » passent au type Nuit du jeu. « 14h » n'est jamais touche. Rejouable sans effet : une fois
 * l'horaire corrige, il n'y a plus rien a remplacer.
 */
#[AsCommand(
    name: 'app:events:nuit-fin-3h',
    description: 'Ramene la fin des Nuits du Jeu de 4h a 3h (usage unique).',
)]
final class NightEndsAtThreeCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les changements sans rien enregistrer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var list<Event> $nights */
        $nights = array_values(array_filter(
            $this->entityManager->getRepository(Event::class)->findAll(),
            static fn (Event $event): bool => EventType::GameNight === $event->getType()
                || (EventType::Special === $event->getType() && 1 === preg_match('/nuits?\s+du\s+jeu/iu', (string) $event->getTitle())),
        ));

        $changed = 0;
        foreach ($nights as $night) {
            $before = (string) $night->getTimeLabel();
            // (?<!\d) : « 14h » ne doit pas devenir « 13h ». « 04h » est accepte et
            // garde son zero : « 03h ».
            $after = preg_replace('/(?<!\d)(0?)4h(00)?(?!\d)/u', '${1}3h${2}', $before);

            // Une « Nuit du Jeu » saisie avant l'existence du type passe au type
            // Nuit du jeu : c'est lui qui lui donne sa page publique.
            $retyped = EventType::GameNight !== $night->getType();
            if ($retyped && !$dryRun) {
                $night->setType(EventType::GameNight);
            }

            if (null === $after || $after === $before) {
                if ($retyped) {
                    $io->writeln(sprintf('#%d %s : passé au type Nuit du jeu', $night->getId(), $night->getTitle()));
                    ++$changed;
                }

                continue;
            }

            $io->writeln(sprintf('#%d %s (%s) : « %s » → « %s »%s', $night->getId(), $night->getTitle(), $night->getStartsAt()?->format('d/m/Y'), $before, $after, $retyped ? ' + type Nuit du jeu' : ''));
            if (!$dryRun) {
                $night->setTimeLabel($after);
            }
            ++$changed;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf('%d évènement(s) %s.', $changed, $dryRun ? 'à corriger (rien enregistré)' : 'corrigé(s)'));

        return Command::SUCCESS;
    }
}
