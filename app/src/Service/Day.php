<?php

namespace App\Service;

/**
 * Les jours du calendrier, vus de Joigny.
 *
 * Les instants (depot, validation, connexion) sont enregistres en UTC et
 * affiches a l'heure de Paris (twig.date.timezone). Les jours (seance, depot en
 * banque) n'ont pas d'heure : ils se manipulent a minuit, dans le fuseau du
 * serveur. Cette classe fait le passage de l'un a l'autre — entre minuit et
 * 2 h a Paris, le serveur est encore la veille.
 */
final class Day
{
    public const TIMEZONE = 'Europe/Paris';

    /** Le jour qu'il est a Paris. */
    public static function today(): \DateTimeImmutable
    {
        return self::of(new \DateTimeImmutable());
    }

    /** Le jour de Paris auquel appartient cet instant. */
    public static function of(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::local($instant)->format('Y-m-d'));
    }

    /** Le meme instant, a lire a l'heure de Paris. */
    public static function local(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return $instant->setTimezone(new \DateTimeZone(self::TIMEZONE));
    }

    /**
     * Le jour saisi « AAAA-MM-JJ », a minuit, ou null si la valeur n'en est pas
     * un : « 2026-02-30 » est refusee, pas reportee au 2 mars.
     */
    public static function parse(string $value): ?\DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if (false === $day || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $day;
    }
}
