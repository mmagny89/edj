<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Form\ResetPasswordRequestType;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use App\Service\AdminPasswordResetNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Mot de passe oublie de l'administration.
 *
 * Trois garanties tiennent l'ensemble :
 *  - la reponse a une demande est toujours la meme, que l'adresse corresponde a
 *    un compte, a un compte bloque, ou a rien : sinon le formulaire devient un
 *    moyen de savoir qui administre le site ;
 *  - le lien part a l'adresse du compte, jamais a une adresse saisie, et ne sert
 *    qu'une fois, pendant une demi-heure ;
 *  - changer le mot de passe coupe les sessions ouvertes du compte (voir
 *    User::isEqualTo) et revoque tous les liens encore en circulation.
 */
#[Route('/mot-de-passe-oublie')]
final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly ResetPasswordRequestRepository $resetRequests,
        private readonly UserRepository $users,
        private readonly AdminPasswordResetNotifier $notifier,
        private readonly EntityManagerInterface $entityManager,
        private readonly RateLimiterFactoryInterface $adminPasswordResetRequestLimiter,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[Route('', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        $form = $this->createForm(ResetPasswordRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Le plafond se consomme a l'envoi et non a l'affichage : un
            // visiteur qui recharge la page ne s'en prive pas lui-meme.
            if (!$this->adminPasswordResetRequestLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $this->securityLogger->warning('Demandes de mot de passe oublie : plafond atteint.', ['event' => 'password_reset_throttled']);
                $form->addError(new FormError('Trop de demandes depuis cette connexion. Réessayez dans une heure.'));

                return $this->render('security/reset_request.html.twig', ['form' => $form], new Response('', Response::HTTP_TOO_MANY_REQUESTS));
            }

            $this->sendLink((string) $form->get('email')->getData());

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('security/reset_request.html.twig', ['form' => $form]);
    }

    /**
     * Meme page pour tout le monde. Sans jeton en session (aucun compte, compte
     * bloque, delai entre deux demandes), un faux jeton fournit la duree de
     * validite affichee : la page ne trahit rien.
     */
    #[Route('/envoye', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        $token = $this->getTokenObjectFromSession() ?? $this->resetPasswordHelper->generateFakeResetToken();

        return $this->render('security/reset_check_email.html.twig', [
            'minutes' => max(1, intdiv($token->getExpiresAt()->getTimestamp() - time(), 60)),
        ]);
    }

    /**
     * Le lien du courriel arrive ici avec son jeton dans l'adresse : il est mis
     * en session et retire de l'adresse par une redirection, pour qu'il ne reste
     * ni dans l'historique du navigateur ni dans l'en-tete Referer d'une page
     * suivante.
     */
    #[Route('/reinitialiser/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request, UserPasswordHasherInterface $passwordHasher, ?string $token = null): Response
    {
        if (null !== $token) {
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();
        if (null === $token) {
            throw $this->createNotFoundException('Aucun jeton de réinitialisation.');
        }

        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            $this->cleanSessionAfterReset();
            $this->addFlash('error', 'Ce lien n\'est plus valable : il a expiré ou a déjà servi. Faites une nouvelle demande.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        // Un compte bloque apres l'envoi du lien ne doit pas retrouver l'acces par lui.
        if (!$user instanceof User || $user->isBlocked()) {
            $this->cleanSessionAfterReset();
            $this->addFlash('error', 'Ce lien n\'est plus valable : faites une nouvelle demande.');

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ChangePasswordType::class, null, ['with_current_password' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $new = (string) $form->get('newPassword')->getData();

            $user->setPassword($passwordHasher->hashPassword($user, $new));
            $user->setMustChangePassword(false);
            // Tous les liens du compte, pas seulement celui-ci : un lien demande
            // plus tot et encore valide permettrait de reprendre la main.
            $this->resetRequests->removeAllFor($user);
            $this->entityManager->flush();
            $this->cleanSessionAfterReset();

            $this->securityLogger->info('Mot de passe reinitialise par lien.', ['event' => 'password_reset', 'user_id' => $user->getId()]);

            $this->addFlash('success', 'Votre mot de passe a été changé. Connectez-vous avec le nouveau.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset.html.twig', ['form' => $form]);
    }

    /**
     * Envoie le lien si, et seulement si, un compte actif porte cette adresse.
     * Aucun des cas qui n'envoie rien ne se distingue, pour l'appelant, de celui
     * qui envoie.
     */
    private function sendLink(string $email): void
    {
        $user = $this->users->findOneByEmailIgnoringCase($email);

        if (null === $user || $user->isBlocked()) {
            return;
        }

        try {
            $token = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // Une demande recente existe deja pour ce compte (delai entre deux
            // demandes) : on n'en envoie pas une seconde, et on ne le dit pas.
            return;
        }

        $this->notifier->send($user, $token);
        $this->setTokenObjectInSession($token);
        $this->securityLogger->info('Lien de reinitialisation du mot de passe envoye.', ['event' => 'password_reset_requested', 'user_id' => $user->getId()]);
    }
}
