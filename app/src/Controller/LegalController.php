<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages legales du site public.
 *
 * Elles ne portent aucune donnee dynamique : le contenu vit dans les
 * gabarits, ou il se relit et se corrige sans toucher au code.
 */
final class LegalController extends AbstractController
{
    #[Route('/mentions-legales', name: 'site_legal_notice')]
    public function legalNotice(): Response
    {
        return $this->render('site/legal_notice.html.twig');
    }

    #[Route('/donnees-personnelles', name: 'site_privacy')]
    public function privacy(): Response
    {
        return $this->render('site/privacy.html.twig');
    }
}
