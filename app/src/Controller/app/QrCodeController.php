<?php

namespace App\Controller\app;

use App\Service\QrCodeGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Generateur de QR code de l'adresse du site, pour les affiches et les flyers.
 */
#[Route('/admin/qr-code')]
final class QrCodeController extends AbstractController
{
    public function __construct(private readonly QrCodeGenerator $generator)
    {
    }

    #[Route('', name: 'app_qrcode', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('app/qrcode.html.twig', [
            'addresses' => QrCodeGenerator::ADDRESSES,
            'codes' => array_map(fn (string $url) => $this->generator->svg($url), QrCodeGenerator::ADDRESSES),
        ]);
    }

    #[Route('/{variant}.{format}', name: 'app_qrcode_download', methods: ['GET'], requirements: ['variant' => 'sans-www|avec-www', 'format' => 'svg|png'])]
    public function download(string $variant, string $format): Response
    {
        $url = QrCodeGenerator::ADDRESSES[$variant];
        $body = 'svg' === $format ? $this->generator->svg($url) : $this->generator->png($url);

        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'svg' === $format ? 'image/svg+xml' : 'image/png',
            'Content-Disposition' => sprintf('attachment; filename="qr-edj-%s.%s"', $variant, $format),
        ]);
    }
}
