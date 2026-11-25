<?php

namespace App\Service;

/**
 * Lecture d'un jour saisi « AAAA-MM-JJ ».
 */
final class Day
{
    /**
     * Le jour, a minuit, ou null si la valeur n'en est pas un : « 2026-02-30 »
     * est refusee, pas reportee au 2 mars.
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
