<?php

namespace App\Controller;

use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * robots.txt et sitemap.xml, produits par l'application plutot que figes : les
 * adresses absolues suivent le domaine de la requete, et le site ne s'ouvre
 * aux moteurs de recherche que quand SITE_INDEXABLE le dit.
 */
final class SeoController extends AbstractController
{
    private const MAX_EVENTS = 12;

    public function __construct(
        #[Autowire('%app.seo.indexable%')]
        private readonly bool $indexable,
        private readonly EventRepository $events,
    ) {
    }

    #[Route('/robots.txt', name: 'seo_robots', methods: ['GET'])]
    public function robots(Request $request): Response
    {
        // Domaine provisoire, recette ou developpement : tout est interdit. Un
        // domaine provisoire indexe ferait concurrence au vrai site dans les
        // resultats, avec le meme contenu.
        if (!$this->indexable) {
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
            ['/rendez-vous', 'weekly', '0.8'],
            ['/adhesion/bulletin', 'monthly', '0.8'],
            ['/mentions-legales', 'yearly', '0.2'],
            ['/donnees-personnelles', 'yearly', '0.2'],
        ];

        // Les prochains evenements seulement : le calendrier en compte une
        // cinquantaine, aux pages quasi identiques, qui noieraient les pages
        // utiles. Une page sort du plan quand l'evenement est passe.
        foreach (\array_slice($this->events->findUpcoming(), 0, self::MAX_EVENTS) as $event) {
            $pages[] = [$this->generateUrl('site_event', ['id' => $event->getId()]), 'weekly', '0.6'];
        }

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
