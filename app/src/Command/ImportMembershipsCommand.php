<?php

namespace App\Command;

use App\Import\ImportException;
use App\Import\ImportOptions;
use App\Import\ImportReport;
use App\Import\MembershipImporter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Importe une liste d'adherents depuis un fichier Excel (.xlsx).
 *
 * Par defaut, une SIMULATION : le fichier est lu et compris, le compte rendu est
 * affiche, rien n'est ecrit. --appliquer ecrit.
 *
 * Le compte rendu ne nomme personne : il parle de lignes (« ligne 12 »). Un terminal
 * garde son historique, et la liste des adherents n'a pas a y rester. --noms les
 * affiche, quand on en a besoin pour retrouver une ligne.
 */
#[AsCommand(
    name: 'app:memberships:import',
    description: 'Importe des adhérents depuis un fichier Excel (simulation par défaut).',
)]
final class ImportMembershipsCommand extends Command
{
    public function __construct(
        private readonly MembershipImporter $importer,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fichier', InputArgument::REQUIRED, 'Le fichier .xlsx à importer')
            ->addOption('image', null, InputOption::VALUE_REQUIRED, 'Sens d\'un « 1 » dans la colonne IMAGE : « refus » (refuse les photos) ou « accord » (accepte les photos)')
            ->addOption('appliquer', null, InputOption::VALUE_NONE, 'Écrit réellement les adhésions (sans cela : simulation)')
            ->addOption('noms', null, InputOption::VALUE_NONE, 'Affiche le nom des personnes dans le compte rendu');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $meaning = $input->getOption('image');
        if (!\in_array($meaning, ['refus', 'accord'], true)) {
            // Pas de valeur par defaut : voir ImportOptions.
            $io->error('Précisez le sens de la colonne IMAGE : --image=refus (un « 1 » = refuse les photos) ou --image=accord (un « 1 » = accepte les photos). Se tromper publie la photo de quelqu\'un qui l\'avait refusée.');

            return Command::INVALID;
        }
        $options = new ImportOptions('refus' === $meaning);

        try {
            $report = $this->importer->analyze((string) $input->getArgument('fichier'), $options);
        } catch (ImportException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->show($io, $report, (bool) $input->getOption('noms'));

        if (!$input->getOption('appliquer')) {
            $io->note('Simulation : rien n\'a été écrit. Relancez avec --appliquer pour importer.');

            return $report->hasErrors() ? Command::FAILURE : Command::SUCCESS;
        }

        if (0 === $report->importable()) {
            $io->warning('Rien à importer.');

            return Command::SUCCESS;
        }

        $created = $this->importer->apply($report, $options);
        $this->securityLogger->warning('Import des adhesions.', ['created' => $created, 'skipped' => \count($report->skipped), 'errors' => \count($report->errors), 'source' => 'console']);
        $io->success(sprintf('%d adhésion(s) importée(s).', $created));

        return Command::SUCCESS;
    }

    private function show(SymfonyStyle $io, ImportReport $report, bool $names): void
    {
        $io->title('Analyse du fichier');
        $io->definitionList(
            ['À importer' => $report->importable()],
            ['Déjà présentes ou en double' => \count($report->skipped)],
            ['Lignes en erreur' => \count($report->errors)],
            ['Avertissements' => $report->warningCount()],
            ['Lignes vides ou notes' => $report->blankLines],
        );

        if ([] !== $report->ignoredColumns) {
            $io->writeln('Colonnes laissées de côté (sans usage dans la fiche) : '.implode(', ', array_unique($report->ignoredColumns)));
        }
        if ([] !== $report->unknownColumns) {
            $io->writeln('<comment>Colonnes non reconnues, ignorées : '.implode(', ', array_unique($report->unknownColumns)).'</comment>');
        }

        foreach ($report->errors as $error) {
            $io->writeln(sprintf('<error> ligne %d </error> %s', $error['line'], $error['message']));
        }
        foreach ($report->skipped as $skipped) {
            $io->writeln(sprintf('  ligne %d : écartée — %s', $skipped['line'], $skipped['reason']));
        }
        foreach ($report->rows as $row) {
            foreach ($row->warnings as $warning) {
                $who = $names ? sprintf(' (%s %s)', $row->application->getFirstName(), $row->application->getLastName()) : '';
                $io->writeln(sprintf('<comment>  ligne %d%s : %s</comment>', $row->line, $who, $warning));
            }
        }
    }
}
