<?php

namespace App\Import;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Garde un fichier d'import entre l'analyse et la confirmation.
 *
 * Le fichier porte des donnees personnelles : il vit dans var/import, hors de
 * public/, dans un dossier ferme a tout autre utilisateur (0700), sous un nom
 * aleatoire de 128 bits qu'on ne devine pas, en lecture pour le seul proprietaire
 * (0600). Il est supprime des qu'on a confirme ou annule, et au bout d'une heure
 * s'il ne s'est rien passe : un fichier d'adherents ne doit pas dormir sur un serveur.
 */
final class ImportStorage
{
    /** Au-dela, un fichier abandonne est supprime. */
    private const MAX_AGE_SECONDS = 3600;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/import')]
        private readonly string $directory,
    ) {
    }

    /**
     * @return string le jeton qui retrouve le fichier
     */
    public function store(UploadedFile $file): string
    {
        $this->prepare();
        $this->purge();

        $token = bin2hex(random_bytes(16));
        $file->move($this->directory, $token.'.xlsx');
        chmod($this->path($token) ?? $this->directory.'/'.$token.'.xlsx', 0o600);

        return $token;
    }

    /**
     * Le chemin du fichier, ou null si le jeton n'a pas la forme d'un jeton ou si le
     * fichier n'existe plus. Un jeton vient du navigateur : il ne devient un chemin
     * qu'apres avoir ete reconnu, jamais en le collant a un dossier tel quel.
     */
    public function path(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        $path = $this->directory.'/'.$token.'.xlsx';

        return is_file($path) ? $path : null;
    }

    public function delete(string $token): void
    {
        if (null !== $path = $this->path($token)) {
            @unlink($path);
        }
    }

    public function purge(): void
    {
        foreach (glob($this->directory.'/*.xlsx') ?: [] as $file) {
            if (time() - (int) filemtime($file) > self::MAX_AGE_SECONDS) {
                @unlink($file);
            }
        }
    }

    private function prepare(): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o700, true);
        }
        chmod($this->directory, 0o700);
    }
}
