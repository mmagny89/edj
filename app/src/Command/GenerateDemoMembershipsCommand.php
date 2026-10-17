<?php

namespace App\Command;

use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Repository\MembershipApplicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Cree des bulletins d'adhesion fictifs pour remplir la liste de
 * l'administration en developpement.
 *
 * Le depot est public et les bulletins portent des donnees personnelles : rien
 * ici n'est reel. Les adresses sont toutes en @example.test — domaine reserve,
 * qui ne peut recevoir aucun courrier — et c'est ce suffixe qui permet a
 * --purge de ne retirer que les bulletins fictifs, jamais un vrai.
 *
 * La commande refuse de tourner en production : un bulletin fictif y serait
 * indiscernable d'un vrai pour qui consulte la liste.
 */
#[AsCommand(
    name: 'app:memberships:demo',
    description: 'Génère des bulletins d\'adhésion fictifs (développement uniquement).',
)]
final class GenerateDemoMembershipsCommand extends Command
{
    private const DEMO_DOMAIN = '@example.test';

    private const LAST_NAMES = ['Martin', 'Bernard', 'Dubois', 'Thomas', 'Robert', 'Richard', 'Petit', 'Durand', 'Leroy', 'Moreau', 'Simon', 'Laurent', 'Lefebvre', 'Michel', 'Garcia', 'David', 'Bertrand', 'Roux', 'Vincent', 'Fournier'];

    private const FIRST_NAMES = ['Camille', 'Louis', 'Emma', 'Jules', 'Léa', 'Hugo', 'Chloé', 'Gabriel', 'Manon', 'Arthur', 'Inès', 'Raphaël', 'Jade', 'Paul', 'Alice', 'Nathan', 'Lina', 'Tom', 'Zoé', 'Adam'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MembershipApplicationRepository $applications,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Nombre de bulletins à créer', '20')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Supprime les bulletins fictifs (adresses en @example.test) au lieu d\'en créer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('Cette commande est réservée au développement : elle ne tourne pas en production.');

            return Command::FAILURE;
        }

        if ($input->getOption('purge')) {
            $deleted = $this->applications->deleteDemo(self::DEMO_DOMAIN);
            $io->success(sprintf('%d bulletin(s) fictif(s) supprimé(s).', $deleted));

            return Command::SUCCESS;
        }

        $count = filter_var($input->getOption('count'), \FILTER_VALIDATE_INT);
        if (false === $count || $count < 1 || $count > 500) {
            $io->error('--count attend un entier entre 1 et 500.');

            return Command::INVALID;
        }

        $types = MembershipType::cases();
        $minors = 0;

        for ($i = 0; $i < $count; ++$i) {
            // Les trois formules tournent, puis un mineur sur quatre et des
            // autorisations qui varient : la liste montre tous les cas.
            $application = $this->build($i, $types[$i % \count($types)], 0 === $i % 4);
            $minors += $application->isMinor() ? 1 : 0;
            $this->entityManager->persist($application);
        }

        $this->entityManager->flush();

        $io->success(sprintf('%d bulletin(s) fictif(s) créé(s), dont %d mineur(s).', $count, $minors));
        $io->note('Pour les retirer : php bin/console app:memberships:demo --purge');

        return Command::SUCCESS;
    }

    private function build(int $index, MembershipType $type, bool $minor): MembershipApplication
    {
        $lastName = self::LAST_NAMES[$index % \count(self::LAST_NAMES)];
        $firstName = self::FIRST_NAMES[($index * 7) % \count(self::FIRST_NAMES)];
        $fullName = $firstName.' '.$lastName;

        // Un mineur a entre 8 et 15 ans ; un majeur, entre 20 et 70.
        $age = $minor ? 8 + ($index % 8) : 20 + (($index * 11) % 51);

        $application = (new MembershipApplication())
            ->setLastName($lastName)
            ->setFirstName($firstName)
            ->setBirthDate(new \DateTimeImmutable(sprintf('-%d years -%d days', $age, ($index * 17) % 300)))
            ->setAddress(sprintf('%d rue des Jeux', 1 + $index))
            ->setPostalCode('89300')
            ->setCity('Joigny')
            ->setPhone(0 === $index % 3 ? null : sprintf('06 00 00 %02d %02d', $index % 100, ($index * 3) % 100))
            ->setEmail(sprintf('demo.%02d%s', $index + 1, self::DEMO_DOMAIN))
            ->setMembershipType($type)
            ->setPhilibertConsent(0 === $index % 2)
            ->setImageRightsRefused(0 === $index % 5)
            ->setStatutesAccepted(true)
            ->setRulesAccepted(true)
            ->setSignaturePlace('Joigny')
            ->setSignatureName($fullName);

        if (MembershipType::Family === $type) {
            $application->setPrincipalMemberName($fullName);
        }

        if ($minor) {
            $application
                ->setGuardianFirstName('Responsable')
                ->setGuardianLastName($lastName)
                ->setGuardianEmail(sprintf('demo.tuteur.%02d%s', $index + 1, self::DEMO_DOMAIN))
                ->setSignatureName('Responsable '.$lastName);
        }

        return $application;
    }
}
