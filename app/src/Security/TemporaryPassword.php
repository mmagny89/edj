<?php

namespace App\Security;

/**
 * Mot de passe provisoire communique de vive voix ou par message a un nouvel
 * administrateur, qui doit le changer des sa premiere connexion.
 *
 * Seize caracteres tires par random_int (generateur cryptographique) dans un
 * alphabet sans les caracteres qui se confondent — 0/O, 1/l/I — pour qu'on
 * puisse le dicter sans erreur : environ 90 bits, bien au-dela de ce qu'un
 * mot de passe provisoire exige. Les tirets ne servent qu'a la lecture.
 */
final class TemporaryPassword
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    public static function generate(): string
    {
        $groups = [];
        for ($group = 0; $group < 4; ++$group) {
            $chars = '';
            for ($i = 0; $i < 4; ++$i) {
                $chars .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $chars;
        }

        return implode('-', $groups);
    }
}
