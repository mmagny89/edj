<?php

namespace App\Controller\app;

use App\Entity\User;
use App\Form\UserCreationType;
use App\Repository\UserRepository;
use App\Security\TemporaryPassword;
use App\Service\AdminWelcomeNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Comptes d'administration : creer, bloquer, changer de role, reinitialiser un
 * mot de passe. Reserve au super-administrateur, en regle (security.yaml) et en
 * attribut.
 *
 * Deux garde-fous valent pour toutes les actions : on ne touche pas a son
 * propre compte (se bloquer ou se retrograder sans retour possible), et on ne
 * prive jamais l'association de son dernier super-administrateur actif.
 */
#[Route('/admin/utilisateurs')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class UserAdminController extends AbstractController
{
    /** Validite du lien d'accueil : deux jours, la personne ne lit pas son courrier dans la demi-heure. */
    private const WELCOME_LINK_LIFETIME = 172800;

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AdminWelcomeNotifier $welcome,
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[Route('', name: 'app_users', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('app/users/index.html.twig', [
            'users' => $this->users->findAllOrdered(),
            'currentIdentifier' => $this->getUser()?->getUserIdentifier(),
        ]);
    }

    #[Route('/nouveau', name: 'app_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->createForm(UserCreationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = mb_strtolower(trim((string) $form->get('email')->getData()));

            if (null !== $this->users->findOneBy(['email' => $email])) {
                $form->get('email')->addError(new \Symfony\Component\Form\FormError('Un compte existe déjà avec cette adresse.'));
            } else {
                $role = (string) $form->get('role')->getData();

                // Aucun mot de passe n'est communique : le compte porte un mot de
                // passe aleatoire que personne ne connait, et la personne choisit
                // le sien par le lien du courriel.
                $user = (new User())->setEmail($email)->setRoles([$role]);
                $user->setPassword($this->passwordHasher->hashPassword($user, TemporaryPassword::generate()));
                $this->entityManager->persist($user);
                $this->entityManager->flush();

                $this->audit('Compte cree.', $user, ['role' => $role]);
                $token = $this->resetPasswordHelper->generateResetToken($user, self::WELCOME_LINK_LIFETIME);
                $this->welcome->send($user, $token);
                $this->addFlash('success', sprintf('Compte créé. Un courriel part à %s avec un lien pour choisir son mot de passe (valable 48 heures).', $email));

                return $this->redirectToRoute('app_users');
            }
        }

        return $this->render('app/users/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/bloquer', name: 'app_user_block', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function block(Request $request, User $user): Response
    {
        if ($refusal = $this->refuse($request, 'user_block_'.$user->getId(), $user, protectsLastAdmin: true)) {
            return $refusal;
        }

        $user->block();
        $this->entityManager->flush();
        $this->audit('Compte bloque.', $user);
        $this->addFlash('success', sprintf('%s est bloqué : il ne peut plus se connecter, et sa session ouverte est coupée.', $user->getEmail()));

        return $this->redirectToRoute('app_users');
    }

    #[Route('/{id}/debloquer', name: 'app_user_unblock', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unblock(Request $request, User $user): Response
    {
        if (!$this->isCsrfTokenValid('user_unblock_'.$user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action refusée : jeton invalide. Réessayez depuis la liste.');

            return $this->redirectToRoute('app_users');
        }

        $user->unblock();
        $this->entityManager->flush();
        $this->audit('Compte debloque.', $user);
        $this->addFlash('success', sprintf('%s est débloqué.', $user->getEmail()));

        return $this->redirectToRoute('app_users');
    }

    #[Route('/{id}/role', name: 'app_user_role', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function changeRole(Request $request, User $user): Response
    {
        $role = (string) $request->request->get('role');
        if (!\in_array($role, [User::ROLE_CONTRIBUTOR, User::ROLE_SUPER_ADMIN], true)) {
            $this->addFlash('error', 'Rôle inconnu.');

            return $this->redirectToRoute('app_users');
        }

        // Retrograder un super-administrateur est la seule modification qui
        // peut priver l'association de son dernier : promouvoir n'a rien a garder.
        $demotion = User::ROLE_CONTRIBUTOR === $role;
        if ($refusal = $this->refuse($request, 'user_role_'.$user->getId(), $user, protectsLastAdmin: $demotion)) {
            return $refusal;
        }

        $user->setRoles([$role]);
        $this->entityManager->flush();
        $this->audit('Role modifie.', $user, ['role' => $role]);
        $this->addFlash('success', sprintf('Le rôle de %s est modifié. Sa session ouverte est coupée : il se reconnecte avec ses nouveaux droits.', $user->getEmail()));

        return $this->redirectToRoute('app_users');
    }

    #[Route('/{id}/mot-de-passe', name: 'app_user_reset_password', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resetPassword(Request $request, User $user): Response
    {
        if ($refusal = $this->refuse($request, 'user_reset_'.$user->getId(), $user)) {
            return $refusal;
        }

        // L'ancien mot de passe est coupe tout de suite (compte perdu ou compromis) ;
        // la personne en choisit un nouveau par le lien du courriel.
        $user->setPassword($this->passwordHasher->hashPassword($user, TemporaryPassword::generate()));
        $user->setMustChangePassword(false);
        $this->entityManager->flush();

        $this->audit('Mot de passe reinitialise.', $user);
        $token = $this->resetPasswordHelper->generateResetToken($user, self::WELCOME_LINK_LIFETIME);
        $this->welcome->send($user, $token, created: false);
        $this->addFlash('success', sprintf('Mot de passe réinitialisé. Un courriel part à %s avec un lien pour en choisir un nouveau (valable 48 heures).', $user->getEmail()));

        return $this->redirectToRoute('app_users');
    }

    /**
     * Supprime un compte contributeur. Un super-administrateur ne se supprime
     * pas : il faut d'abord le rendre contributeur, geste qui coupe sa session
     * et que le dernier super-administrateur actif ne peut pas subir.
     */
    #[Route('/{id}/supprimer', name: 'app_user_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, User $user): Response
    {
        if ($user->isSuperAdmin()) {
            $this->addFlash('error', 'Un super-administrateur ne peut pas être supprimé : rendez-le d\'abord contributeur.');

            return $this->redirectToRoute('app_users');
        }

        if ($refusal = $this->refuse($request, 'user_delete_'.$user->getId(), $user)) {
            return $refusal;
        }

        $email = (string) $user->getEmail();
        $this->audit('Compte supprime.', $user);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('Le compte %s est supprimé.', $email));

        return $this->redirectToRoute('app_users');
    }

    /**
     * Controles communs : jeton valide, pas son propre compte, et le dernier
     * super-administrateur actif reste en place. Renvoie la reponse de refus,
     * ou null si l'action peut avoir lieu.
     */
    private function refuse(Request $request, string $tokenId, User $target, bool $protectsLastAdmin = false): ?Response
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action refusée : jeton invalide. Réessayez depuis la liste.');

            return $this->redirectToRoute('app_users');
        }

        if ($target->getUserIdentifier() === $this->getUser()?->getUserIdentifier()) {
            $this->addFlash('error', 'Vous ne pouvez pas faire cela sur votre propre compte. Demandez à un autre administrateur.');

            return $this->redirectToRoute('app_users');
        }

        if ($protectsLastAdmin && $target->isSuperAdmin() && !$target->isBlocked() && $this->users->countActiveSuperAdmins() <= 1) {
            $this->addFlash('error', 'C\'est le dernier super-administrateur actif : l\'association ne peut pas s\'en passer.');

            return $this->redirectToRoute('app_users');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(string $message, User $target, array $context = []): void
    {
        // Identifiants internes seulement : l'adresse e-mail n'est jamais ecrite
        // au journal (conventions, section 24, point 7).
        $this->securityLogger->warning($message, $context + [
            'target_user_id' => $target->getId(),
            'by_user_id' => $this->getUser() instanceof User ? $this->getUser()->getId() : null,
        ]);
    }
}
