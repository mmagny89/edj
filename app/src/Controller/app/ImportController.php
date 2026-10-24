<?php

namespace App\Controller\app;

use App\Entity\User;
use App\Form\ImportUploadType;
use App\Import\ImportException;
use App\Import\ImportOptions;
use App\Import\ImportStorage;
use App\Import\MembershipImporter;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Import d'une liste d'adherents depuis un classeur Excel.
 *
 * Reserve au super-administrateur, en regle (security.yaml) et en attribut. Deux
 * temps : on televerse et on lit le compte rendu, puis on confirme. Rien n'est
 * ecrit en base avant la confirmation, qui rejoue l'analyse sur le fichier garde.
 */
#[Route('/admin/import')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class ImportController extends AbstractController
{
    public function __construct(
        private readonly MembershipImporter $importer,
        private readonly ImportStorage $storage,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    #[Route('', name: 'app_import', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->createForm(ImportUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('file')->getData();
            $meaning = (string) $form->get('imageMeaning')->getData();
            $token = $this->storage->store($file);

            return $this->redirectToRoute('app_import_report', ['token' => $token, 'image' => $meaning]);
        }

        return $this->render('app/import/index.html.twig', ['form' => $form]);
    }

    #[Route('/{token}', name: 'app_import_report', methods: ['GET'], requirements: ['token' => '[a-f0-9]{32}'])]
    public function report(Request $request, string $token): Response
    {
        $options = $this->options($request->query->getString('image'));
        $path = $this->storage->path($token);
        if (null === $options || null === $path) {
            $this->addFlash('error', 'Ce fichier n\'est plus disponible : téléversez-le de nouveau.');

            return $this->redirectToRoute('app_import');
        }

        try {
            $report = $this->importer->analyze($path, $options);
        } catch (ImportException $e) {
            $this->storage->delete($token);
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_import');
        }

        return $this->render('app/import/report.html.twig', [
            'report' => $report,
            'token' => $token,
            'image' => $request->query->getString('image'),
        ]);
    }

    #[Route('/{token}/appliquer', name: 'app_import_apply', methods: ['POST'], requirements: ['token' => '[a-f0-9]{32}'])]
    public function apply(Request $request, string $token): Response
    {
        if (!$this->isCsrfTokenValid('import_'.$token, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action refusée : jeton invalide. Recommencez l\'import.');

            return $this->redirectToRoute('app_import');
        }

        $user = $this->getUser();
        $options = $this->options($request->request->getString('image'), $user instanceof User ? $user->getId() : null);
        $path = $this->storage->path($token);
        if (null === $options || null === $path) {
            $this->addFlash('error', 'Ce fichier n\'est plus disponible : téléversez-le de nouveau.');

            return $this->redirectToRoute('app_import');
        }

        try {
            $report = $this->importer->analyze($path, $options);
            // Les lignes en erreur ne bloquent plus les autres : on importe ce qui
            // est valide. Relancer l'import avec le fichier corrige est sans
            // danger, une personne deja inscrite pour la saison etant ecartee.
            if (0 === $report->importable()) {
                $this->addFlash('error', 'Aucune ligne valide à importer : corrigez le fichier puis téléversez-le de nouveau.');

                return $this->redirectToRoute('app_import_report', ['token' => $token, 'image' => $request->request->getString('image')]);
            }
            $created = $this->importer->apply($report, $options);
        } catch (ImportException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_import');
        } finally {
            $this->storage->delete($token);
        }

        $this->securityLogger->notice('Import de adherents', [
            'event' => 'member_import',
            'user_id' => $user instanceof User ? $user->getId() : null,
            'created' => $created,
        ]);
        $rejected = \count($report->errors);
        $this->addFlash('success', sprintf('%d adhésion(s) importée(s).', $created));
        if ($rejected > 0) {
            $this->addFlash('error', sprintf(
                '%d ligne(s) rejetée(s), non importée(s). Corrigez-les dans le fichier et relancez l\'import : les personnes déjà importées seront ignorées.',
                $rejected,
            ));
        }

        return $this->redirectToRoute('app_membership_applications');
    }

    #[Route('/{token}/annuler', name: 'app_import_cancel', methods: ['POST'], requirements: ['token' => '[a-f0-9]{32}'])]
    public function cancel(Request $request, string $token): Response
    {
        if ($this->isCsrfTokenValid('import_'.$token, (string) $request->request->get('_token'))) {
            $this->storage->delete($token);
            $this->addFlash('success', 'Import annulé : le fichier a été supprimé du serveur.');
        }

        return $this->redirectToRoute('app_import');
    }

    private function options(string $image, ?int $adminId = null): ?ImportOptions
    {
        return match ($image) {
            'refus' => new ImportOptions(true, $adminId),
            'accord' => new ImportOptions(false, $adminId),
            default => null,
        };
    }
}
