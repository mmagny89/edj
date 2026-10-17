<?php

namespace App\Controller\app;

use App\Entity\User;
use App\Form\ChangePasswordType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Compte de la personne connectee : changer son propre mot de passe.
 */
#[Route('/admin/mon-compte')]
#[IsGranted('ROLE_CONTRIBUTOR')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[Route('', name: 'app_account', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $current = (string) $form->get('currentPassword')->getData();
            $new = (string) $form->get('newPassword')->getData();

            if (!$this->passwordHasher->isPasswordValid($user, $current)) {
                $form->get('currentPassword')->addError(new FormError('Le mot de passe actuel est incorrect.'));
            } elseif ($current === $new) {
                $form->get('newPassword')->get('first')->addError(new FormError('Le nouveau mot de passe doit différer de l\'actuel.'));
            } else {
                $user->setPassword($this->passwordHasher->hashPassword($user, $new));
                $user->setMustChangePassword(false);
                $this->entityManager->flush();
                $this->securityLogger->info('Mot de passe change par son titulaire.', ['user_id' => $user->getId()]);

                // Le mot de passe a change : la session en cours n'est plus
                // reconnue (voir User::isEqualTo), et la personne se reconnecte.
                $this->addFlash('success', 'Votre mot de passe a été changé. Reconnectez-vous avec le nouveau.');

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('app/account.html.twig', [
            'form' => $form,
            'forced' => $user->mustChangePassword(),
        ]);
    }
}
