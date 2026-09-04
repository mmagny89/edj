<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Cree un compte, ou remet a jour le mot de passe d'un compte existant.
 *
 * Le mot de passe n'est jamais un argument de la commande : il est demande de
 * facon masquee. Passe en argument, il resterait en clair dans l'historique
 * du shell et dans la liste des processus de la machine.
 */
#[AsCommand(
    name: 'app:create-user',
    description: "Cree un compte (ou change son mot de passe) et lui attribue un role.",
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adresse e-mail, qui sert aussi d\'identifiant de connexion')
            ->addOption(
                'roles',
                null,
                InputOption::VALUE_REQUIRED,
                'Roles separes par des virgules',
                'ROLE_SUPER_ADMIN',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $roles = array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) $input->getOption('roles')),
        )));

        if (\count($this->validator->validate($email, new Email())) > 0) {
            $io->error(sprintf('"%s" n\'est pas une adresse e-mail valide.', $email));

            return Command::INVALID;
        }

        if (!$input->isInteractive()) {
            $io->error('Cette commande demande un mot de passe : elle ne peut pas tourner en mode non interactif.');

            return Command::FAILURE;
        }

        $question = (new Question('Mot de passe : '))
            ->setHidden(true)
            ->setValidator(static function (?string $value): string {
                if (null === $value || \strlen($value) < 12) {
                    throw new \RuntimeException('Le mot de passe doit faire au moins 12 caracteres.');
                }

                return $value;
            });

        $password = (string) $io->askQuestion($question);

        $confirmation = (new Question('Confirmation : '))->setHidden(true);
        if ($password !== $io->askQuestion($confirmation)) {
            $io->error('Les deux saisies different.');

            return Command::FAILURE;
        }

        $user = $this->users->findOneBy(['email' => $email]);
        $existing = null !== $user;

        if (!$existing) {
            $user = new User();
            $user->setEmail($email);
        }

        $user->setRoles($roles);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            '%s : %s (%s)',
            $existing ? 'Mot de passe et roles mis a jour' : 'Compte cree',
            $email,
            implode(', ', $roles),
        ));

        return Command::SUCCESS;
    }
}
