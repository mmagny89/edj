<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * robots.txt et sitemap.xml, produits par l'application plutot que figes : les
 * adresses absolues suivent le domaine de la requete, et la recette n'est
 * jamais ouverte aux moteurs de recherche.
 */
final class SeoController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    #[Route('/robots.txt', name: 'seo_robots', methods: ['GET'])]
    public function robots(Request $request): Response
    {
        // Hors production : tout est interdit. Un domaine de recette indexe
        // ferait concurrence au vrai site dans les resultats.
        if ('prod' !== $this->environment) {
            return $this->text("User-agent: *\nDisallow: /\n");
        }

        return $this->text(implode("\n", [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /login',
            'Disallow: /logout',
            'Allow: /',
            '',
            'Sitemap: '.$request->getSchemeAndHttpHost().'/sitemap.xml',
            '',
        ]));
    }

    #[Route('/sitemap.xml', name: 'seo_sitemap', methods: ['GET'])]
    public function sitemap(Request $request): Response
    {
        $base = $request->getSchemeAndHttpHost();

        // Les pages publiques et indexables. Pas la confirmation de depot ni la
        // page de limitation, qui sont en noindex, ni l'administration.
        $pages = [
            ['/', 'weekly', '1.0'],
            ['/adhesion/bulletin', 'monthly', '0.8'],
            ['/mentions-legales', 'yearly', '0.2'],
            ['/donnees-personnelles', 'yearly', '0.2'],
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($pages as [$path, $frequency, $priority]) {
            $xml .= sprintf(
                "  <url>\n    <loc>%s</loc>\n    <changefreq>%s</changefreq>\n    <priority>%s</priority>\n  </url>\n",
                htmlspecialchars($base.$path, \ENT_XML1 | \ENT_QUOTES),
                $frequency,
                $priority,
            );
        }
        $xml .= "</urlset>\n";

        return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function text(string $body): Response
    {
        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
