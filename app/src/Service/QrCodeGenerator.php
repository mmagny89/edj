<?php

namespace App\Service;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * QR code d'une adresse, produit sur le serveur : aucun service tiers ne voit
 * l'adresse encodee, et le site reste utilisable sans acces a un CDN.
 */
final class QrCodeGenerator
{
    /**
     * Adresses que le generateur accepte d'encoder. Liste fermee : l'outil
     * n'est pas un generateur libre, il sert les deux adresses du site.
     */
    public const ADDRESSES = [
        'sans-www' => 'https://enviedejouer89.fr',
        'avec-www' => 'https://www.enviedejouer89.fr',
    ];

    public function svg(string $url): string
    {
        return $this->render($url, QRMarkupSVG::class);
    }

    public function png(string $url): string
    {
        return $this->render($url, QRGdImagePNG::class);
    }

    private function render(string $url, string $outputClass): string
    {
        $options = new QROptions([
            'outputInterface' => $outputClass,
            // Correction d'erreur elevee : le code reste lisible une fois
            // imprime sur une affiche, froisse ou partiellement masque.
            'eccLevel' => EccLevel::H,
            'scale' => 12,
            'quietzoneSize' => 4,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
        ]);

        return (new QRCode($options))->render($url);
    }
}
