<?php

namespace App\Command;

use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Repository\MemberRepository;
use App\Repository\MembershipApplicationRepository;
use App\Service\MemberDirectory;
use App\Service\MembershipSeasons;
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
        private readonly MemberRepository $memberRepository,
        private readonly MemberDirectory $members,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Nombre de bulletins à créer', '20')
            ->addOption('former', null, InputOption::VALUE_REQUIRED, "Nombre d'anciens adhérents à créer, avec leur historique sur les saisons passées (pour travailler les renouvellements)", '0')
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
            // Les adherents que ces bulletins faisaient naitre ne designent
            // plus rien : les laisser consommerait des numeros pour toujours.
            $orphans = $this->memberRepository->deleteOrphans();
            $io->success(sprintf('%d bulletin(s) fictif(s) supprimé(s), et %d adhérent(s) devenu(s) orphelin(s).', $deleted, $orphans));

            return Command::SUCCESS;
        }

        $count = filter_var($input->getOption('count'), \FILTER_VALIDATE_INT);
        $former = filter_var($input->getOption('former'), \FILTER_VALIDATE_INT);
        if (false === $count || $count < 0 || $count > 500) {
            $io->error('--count attend un entier entre 0 et 500.');

            return Command::INVALID;
        }
        if (false === $former || $former < 0 || $former > 200) {
            $io->error('--former attend un entier entre 0 et 200.');

            return Command::INVALID;
        }
        if (0 === $count && 0 === $former) {
            $io->error('Rien à créer : --count et --former valent zéro.');

            return Command::INVALID;
        }

        $types = MembershipType::cases();
        $minors = 0;

        for ($i = 0; $i < $count; ++$i) {
            // Les trois formules tournent, puis un mineur sur quatre et des
            // autorisations qui varient : la liste montre tous les cas.
            $application = $this->build($i, $types[$i % \count($types)], 0 === $i % 4);
            $minors += $application->isMinor() ? 1 : 0;
            $this->members->attach($application);
            $this->entityManager->persist($application);
        }

        $scenarios = [];
        for ($i = 0; $i < $former; ++$i) {
            $scenarios[] = $this->createFormerMember($i);
        }

        $this->entityManager->flush();

        if ($former > 0) {
            $io->section(sprintf('%d ancien(s) adhérent(s), saison en cours : %d', $former, MembershipSeasons::of(new \DateTimeImmutable())));
            foreach (array_count_values($scenarios) as $label => $number) {
                $io->writeln(sprintf('  %3d × %s', $number, $label));
            }
        }

        $io->success(sprintf('%d bulletin(s) fictif(s) créé(s), dont %d mineur(s).', $count, $minors));
        $io->note('Pour les retirer : php bin/console app:memberships:demo --purge');

        return Command::SUCCESS;
    }

    /**
     * Les quatre situations d'un ancien adherent face a la saison en cours,
     * dans l'ordre ou elles tournent. Ce sont celles que l'interface de
     * renouvellement doit savoir distinguer : qui relancer, qui est a jour, qui
     * a deja redepose mais n'a pas encore paye.
     *
     * Chaque entree donne les saisons validees, comptees depuis la saison en
     * cours (0 = la saison en cours, 1 = la precedente...), et la saison en
     * attente, s'il y en a une.
     */
    private const FORMER_SCENARIOS = [
        'à renouveler — adhérent jusqu\'à la saison dernière' => ['validated' => [3, 2, 1], 'pending' => null],
        'perdu de vue — dernière adhésion il y a trois saisons' => ['validated' => [3], 'pending' => null],
        'déjà renouvelé pour la saison en cours' => ['validated' => [1, 0], 'pending' => null],
        'renouvellement déposé, pas encore payé' => ['validated' => [1], 'pending' => 0],
    ];

    /**
     * Cree une personne avec son historique sur les saisons passees, et
     * renvoie le libelle de sa situation.
     *
     * Une seule personne, plusieurs bulletins : le premier passe par l'annuaire
     * qui lui donne son numero d'adherent, les suivants s'y rattachent
     * directement. Les relire par l'annuaire echouerait — l'adherent n'est pas
     * encore ecrit en base, il ne s'y retrouverait pas, et son empreinte
     * d'identite entrerait en conflit avec elle-meme.
     */
    private function createFormerMember(int $index): string
    {
        $labels = array_keys(self::FORMER_SCENARIOS);
        $label = $labels[$index % \count($labels)];
        $scenario = self::FORMER_SCENARIOS[$label];

        $season = MembershipSeasons::of(new \DateTimeImmutable());
        $lastName = self::LAST_NAMES[($index * 3) % \count(self::LAST_NAMES)];
        $firstName = self::FIRST_NAMES[($index * 7 + 3) % \count(self::FIRST_NAMES)];
        // Un foyer sur quatre prend la formule famille, rattachee a quelqu'un
        // d'autre : jamais a soi-meme (voir MembershipApplication).
        $family = 3 === $index % 4;
        $methods = [PaymentMethod::Cheque, PaymentMethod::Transfer, PaymentMethod::Cash];
        $birth = new \DateTimeImmutable(sprintf('-%d years -%d days', 25 + (($index * 11) % 46), ($index * 17) % 300));

        $steps = [];
        foreach ($scenario['validated'] as $back) {
            $steps[] = [$season - $back, true];
        }
        if (null !== $scenario['pending']) {
            $steps[] = [$season - $scenario['pending'], false];
        }
        usort($steps, static fn (array $a, array $b) => $a[0] <=> $b[0]);

        $member = null;
        foreach ($steps as [$year, $validated]) {
            $application = (new MembershipApplication())
                ->setLastName($lastName)
                ->setFirstName($firstName)
                ->setBirthDate($birth)
                ->setAddress(sprintf('%d rue des Anciens', 1 + $index))
                ->setPostalCode('89300')
                ->setCity(0 === $index % 3 ? 'Joigny' : (1 === $index % 3 ? 'Cézy' : 'Auxerre'))
                ->setEmail(sprintf('ancien.%02d%s', $index + 1, self::DEMO_DOMAIN))
                ->setMembershipType($family ? MembershipType::Family : MembershipType::Individual)
                ->setPrincipalMemberName($family ? self::FIRST_NAMES[($index * 7 + 9) % \count(self::FIRST_NAMES)].' '.$lastName : null)
                ->setPhilibertConsent(0 === $index % 2)
                ->setStatutesAccepted(true)
                ->setRulesAccepted(true)
                ->setSignaturePlace('Joigny')
                ->setSignatureName($firstName.' '.$lastName)
                ->setSubmittedAt($this->submissionDate($year, $index));

            if ($validated) {
                $application->validate($methods[$index % \count($methods)], null, min($application->getSubmittedAt()->modify('+2 days'), new \DateTimeImmutable()));
            }

            if (null === $member) {
                $member = $this->members->attach($application);
            } else {
                $application->setMember($member);
            }
            $this->entityManager->persist($application);
        }

        return $label;
    }

    /**
     * Un depot en novembre de la saison, sauf pour la saison en cours si ce
     * novembre n'est pas encore arrive : un bulletin date du futur serait
     * refuse par le formulaire, et fausserait les tris de l'administration.
     */
    private function submissionDate(int $season, int $index): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        $start = new \DateTimeImmutable(sprintf('%d-10-01', $season - 1));
        $date = new \DateTimeImmutable(sprintf('%d-11-%02d 18:30', $season - 1, 3 + $index % 20));

        return $date > $now ? max($start, $now->modify('-1 day')) : $date;
    }

    private function build(int $index, MembershipType $type, bool $minor): MembershipApplication
    {
        $lastName = self::LAST_NAMES[$index % \count(self::LAST_NAMES)];
        $firstName = self::FIRST_NAMES[($index * 7) % \count(self::FIRST_NAMES)];
        $fullName = $firstName.' '.$lastName;

        // Un mineur a entre 8 et 15 ans ; un majeur, entre 20 et 70.
        $age = $minor ? 8 + ($index % 8) : 20 + (($index * 11) % 51);

        // Un mineur ne prend jamais l'adhesion individuelle : il se rattache a
        // celle d'un adulte de son foyer (voir MembershipApplication). Des
        // donnees de demonstration qui l'enfreignent donneraient a lire dans
        // l'administration un cas que le bulletin refuse.
        if ($minor && MembershipType::Individual === $type) {
            $type = MembershipType::Family;
        }

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
            // Jamais pour un consommateur : le bulletin le refuse.
            ->setPhilibertConsent(0 === $index % 2 && MembershipType::Consumer !== $type)
            ->setImageRightsRefused(0 === $index % 5)
            ->setStatutesAccepted(true)
            ->setRulesAccepted(true)
            ->setSignaturePlace('Joigny')
            ->setSignatureName($fullName);

        if (MembershipType::Family === $type) {
            // Jamais son propre nom : une adhesion famille se greffe sur
            // l'adhesion individuelle d'une autre personne du foyer. C'est
            // precisement ce que le bulletin refuse desormais, et des donnees
            // de demonstration qui l'enfreignent donnent a lire dans
            // l'administration un cas qui ne peut plus se produire.
            $principal = self::FIRST_NAMES[(($index + 3) * 7) % \count(self::FIRST_NAMES)].' '.$lastName;
            $application->setPrincipalMemberName($principal);
        }

        // Les quatre etats possibles tournent, pour que la liste montre une
        // adhesion en attente, deux validees aux reglements differents et une
        // annulee.
        $owes = ($application->amountDue() ?? 0) > 0;
        match ($index % 4) {
            1 => $application->validate($owes ? PaymentMethod::Cheque : null, null),
            2 => $application->validate($owes ? PaymentMethod::Transfer : null, null),
            3 => $application->cancel(null),
            default => null,
        };

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
