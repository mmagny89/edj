<?php

namespace App\Form\Guard;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Jeton horodate et signe pour les formulaires publics.
 *
 * Un robot qui remplit un formulaire le fait en quelques millisecondes ; un humain
 * met plusieurs secondes. Le jeton dit quand la page a ete affichee, signe pour
 * qu'on ne puisse ni l'inventer ni le vieillir : le soumettre trop tot, trop tard
 * ou modifie est refuse.
 *
 * Sans etat : rien n'est garde cote serveur, donc pas de session ouverte pour
 * chaque visiteur anonyme — c'est la raison pour laquelle le projet a choisi la
 * protection CSRF sans session, et ce jeton ne la defait pas.
 *
 * C'est un frein, pas une serrure : un robot patient attend trois secondes. Il
 * s'ajoute au piege a robots (champ invisible), a la protection CSRF et aux
 * plafonds de debit, et ne remplace aucun d'eux.
 */
final class FormGuard
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        #[Autowire('%app.form_guard.min_seconds%')]
        private readonly int $minSeconds,
        #[Autowire('%app.form_guard.max_seconds%')]
        private readonly int $maxSeconds,
    ) {
    }

    public function issue(?\DateTimeImmutable $now = null): string
    {
        $timestamp = (string) (($now ?? new \DateTimeImmutable())->getTimestamp());

        return $timestamp.'.'.$this->signature($timestamp);
    }

    /**
     * Renvoie le message d'erreur a afficher, ou null si le jeton est bon.
     */
    public function check(?string $token, ?\DateTimeImmutable $now = null): ?string
    {
        $parts = explode('.', (string) $token, 2);
        if (2 !== \count($parts) || !ctype_digit($parts[0]) || !hash_equals($this->signature($parts[0]), $parts[1])) {
            return 'Le formulaire n\'a pas pu être vérifié. Rechargez la page et recommencez.';
        }

        $age = ($now ?? new \DateTimeImmutable())->getTimestamp() - (int) $parts[0];

        if ($age < $this->minSeconds) {
            return 'Le formulaire a été envoyé trop vite. Patientez un instant et recommencez.';
        }

        if ($age > $this->maxSeconds) {
            return 'Le formulaire est resté ouvert trop longtemps. Rechargez la page et recommencez.';
        }

        return null;
    }

    private function signature(string $timestamp): string
    {
        // Une cle propre a cet usage, derivee du secret de l'application : le
        // jeton signe ne sert a rien ailleurs.
        return hash_hmac('sha256', 'form-guard|'.$timestamp, $this->secret);
    }
}
