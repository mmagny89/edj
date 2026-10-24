<?php

namespace App\Controller\app;

use App\Dto\MailingDraft;
use App\Entity\MailCampaign;
use App\Entity\User;
use App\Form\MailingType;
use App\Repository\MailCampaignRepository;
use App\Repository\UserRepository;
use App\Service\MailingAudience;
use App\Service\MailingAudienceResult;
use App\Service\MailingSender;
use App\Service\MailingTemplate;
use App\Service\MembershipStatistics;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ecrire aux adherents d'une saison.
 *
 * Un envoi de masse ne se rattrape pas : le parcours est donc en deux temps, et
 * le second ne se contente pas d'un clic. L'apercu montre a combien de personnes
 * le message part, et pourquoi d'autres n'en recoivent pas ; l'envoi n'est
 * accepte qu'avec le nombre que cet apercu a annonce — si la liste a bouge entre
 * les deux, il est refuse — et avec un jeton a usage unique, qui fait qu'un double
 * clic, ou une page rechargee, ne part pas deux fois.
 */
#[Route('/admin/courriels')]
#[IsGranted('ROLE_CONTRIBUTOR')]
final class MailingController extends AbstractController
{
    private const NONCE_KEY = 'mailing_nonce';

    public function __construct(
        private readonly MailingAudience $audience,
        private readonly MailingSender $sender,
        private readonly MailCampaignRepository $campaigns,
        private readonly MembershipStatistics $statistics,
        private readonly UserRepository $users,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[Route('', name: 'app_mailing', methods: ['GET'])]
    public function index(): Response
    {
        $campaigns = $this->campaigns->findLatest();

        return $this->render('app/mailing/index.html.twig', [
            'campaigns' => $campaigns,
            'actors' => $this->actorsOf($campaigns),
        ]);
    }

    #[Route('/nouveau', name: 'app_mailing_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $current = $this->statistics->currentSeason();
        $draft = new MailingDraft();
        $draft->season = $current;

        $form = $this->createForm(MailingType::class, $draft, [
            'seasons' => $this->statistics->availableSeasons(),
            'current_season' => $current,
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('app/mailing/new.html.twig', ['form' => $form, 'preview' => null], new Response('', $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        }

        $action = $form->getClickedButton()?->getName();

        if ('test' === $action) {
            return $this->sendTest($form, $draft);
        }

        $audience = $this->audience->resolve((int) $draft->season, $draft->includeAnnual, $draft->includeConsumers);

        if ('send' === $action) {
            return $this->sendCampaign($request, $form, $draft, $audience);
        }

        return $this->renderPreview($request, $form, $draft, $audience);
    }

    #[Route('/{id}', name: 'app_mailing_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(MailCampaign $campaign): Response
    {
        return $this->render('app/mailing/show.html.twig', [
            'campaign' => $campaign,
            'actors' => $this->actorsOf([$campaign]),
        ]);
    }

    private function renderPreview(Request $request, FormInterface $form, MailingDraft $draft, MailingAudienceResult $audience, int $status = Response::HTTP_OK): Response
    {
        // Le jeton se fabrique ici et nulle part ailleurs : pas d'apercu, pas d'envoi.
        $nonce = bin2hex(random_bytes(16));
        $request->getSession()->set(self::NONCE_KEY, $nonce);

        $sample = $audience->recipients[0] ?? null;
        $values = null !== $sample
            ? MailingTemplate::valuesFor($sample->application, (int) $draft->season)
            : MailingTemplate::sampleValues((int) $draft->season);

        return $this->render('app/mailing/new.html.twig', [
            'form' => $form,
            'preview' => [
                'audience' => $audience,
                'nonce' => $nonce,
                'subject' => trim((string) preg_replace('/\s+/', ' ', MailingTemplate::render($draft->subject, $values))),
                'body' => MailingTemplate::render($draft->body, $values),
                'sampleName' => null !== $sample ? trim($sample->application->getFirstName().' '.$sample->application->getLastName()) : null,
            ],
        ], new Response('', $status));
    }

    private function sendTest(FormInterface $form, MailingDraft $draft): Response
    {
        $user = $this->getUser();
        $to = $user instanceof User ? (string) $user->getEmail() : $this->getUser()?->getUserIdentifier();

        if (null === $to || false === filter_var($to, \FILTER_VALIDATE_EMAIL)) {
            $form->addError(new FormError('Votre compte n\'a pas d\'adresse e-mail à laquelle envoyer un essai.'));

            return $this->render('app/mailing/new.html.twig', ['form' => $form, 'preview' => null], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->sender->sendTest($draft, $to);
        $this->addFlash('success', 'Un essai vient de vous être envoyé, avec des valeurs d\'exemple (Camille Dupont, EDJ-0042).');

        return $this->render('app/mailing/new.html.twig', ['form' => $form, 'preview' => null]);
    }

    private function sendCampaign(Request $request, FormInterface $form, MailingDraft $draft, MailingAudienceResult $audience): Response
    {
        $session = $request->getSession();
        $expected = (string) $session->get(self::NONCE_KEY, '');
        $given = (string) $form->get('nonce')->getData();

        // Consomme avant d'envoyer : un second POST identique trouve un jeton vide.
        $session->remove(self::NONCE_KEY);

        if ('' === $expected || !hash_equals($expected, $given)) {
            $form->addError(new FormError('Cet envoi a déjà été fait, ou l\'aperçu est trop ancien. Relisez l\'aperçu avant d\'envoyer.'));

            return $this->render('app/mailing/new.html.twig', ['form' => $form, 'preview' => null], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        if (0 === $audience->count()) {
            $form->addError(new FormError('Aucun destinataire : rien à envoyer.'));

            return $this->render('app/mailing/new.html.twig', ['form' => $form, 'preview' => null], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        if ($audience->count() !== (int) $draft->expected) {
            $form->addError(new FormError(sprintf(
                'La liste a changé depuis l\'aperçu : %d message(s) au lieu de %d. Relisez le nouvel aperçu avant d\'envoyer.',
                $audience->count(),
                (int) $draft->expected,
            )));

            return $this->renderPreview($request, $form, $draft, $audience, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->getUser();
        $campaign = $this->sender->send($draft, $audience, $user instanceof User ? $user->getId() : null);

        $this->securityLogger->notice('Message aux adherents envoye.', [
            'event' => 'mailing_sent',
            'user_id' => $user instanceof User ? $user->getId() : null,
            'campaign_id' => $campaign->getId(),
            'season' => $campaign->getSeason(),
            'recipients' => $campaign->getRecipientCount(),
        ]);

        $this->addFlash('success', sprintf('Message mis en file pour %d adresse(s). Il part au rythme du serveur d\'envoi : quelques minutes pour une grande liste.', $campaign->getRecipientCount()));

        return $this->redirectToRoute('app_mailing_show', ['id' => $campaign->getId()]);
    }

    /**
     * Les adresses des administrateurs qui ont envoye, indexees par identifiant :
     * l'historique ne garde que l'identifiant, un compte supprime depuis n'a plus
     * d'entree ici, et la page l'ecrit comme tel.
     *
     * @param list<MailCampaign> $campaigns
     *
     * @return array<int, string>
     */
    private function actorsOf(array $campaigns): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (MailCampaign $c) => $c->getSentById(), $campaigns))));
        $actors = [];
        foreach ([] === $ids ? [] : $this->users->findBy(['id' => $ids]) as $user) {
            $actors[(int) $user->getId()] = (string) $user->getEmail();
        }

        return $actors;
    }
}
