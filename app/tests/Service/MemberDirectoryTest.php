<?php

namespace App\Tests\Service;

use App\Service\MemberDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rapprochement de deux bulletins vers le meme adherent. C'est cette empreinte
 * qui decide si une readhesion garde le numero ou en recoit un neuf : une
 * normalisation trop laxiste donnerait a une personne l'acces a la fiche d'une
 * autre.
 */
final class MemberDirectoryTest extends TestCase
{
    private const BIRTH = '1990-04-02';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sameIdentity(): iterable
    {
        yield 'casse' => ['DURAND', 'durand'];
        yield 'accents' => ['Lefèvre', 'Lefevre'];
        yield 'espaces en trop' => ['Durand', '  Durand  '];
        yield 'trait d\'union' => ['Martin-Dupont', 'Martin Dupont'];
        yield 'apostrophe' => ["D'Arcy", 'D Arcy'];
    }

    #[DataProvider('sameIdentity')]
    public function testTwoSpellingsOfTheSameNameShareAnIdentity(string $a, string $b): void
    {
        self::assertSame($this->key($a), $this->key($b));
    }

    public function testADifferentBirthDateIsADifferentPerson(): void
    {
        $father = MemberDirectory::identityKey('Durand', 'Camille', new \DateTimeImmutable('1960-04-02'));
        $child = MemberDirectory::identityKey('Durand', 'Camille', new \DateTimeImmutable(self::BIRTH));

        self::assertNotSame($father, $child);
    }

    public function testADifferentFirstNameIsADifferentPerson(): void
    {
        self::assertNotSame(
            MemberDirectory::identityKey('Durand', 'Camille', new \DateTimeImmutable(self::BIRTH)),
            MemberDirectory::identityKey('Durand', 'Camille-Marie', new \DateTimeImmutable(self::BIRTH)),
        );
    }

    /**
     * L'empreinte ne doit pas se lire : elle vit dans une table que l'on
     * consulte, et dire le nom en clair reviendrait a ne rien avoir fait.
     */
    public function testTheKeyRevealsNothingOfTheName(): void
    {
        $key = $this->key('Durand');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        self::assertStringNotContainsStringIgnoringCase('durand', $key);
    }

    private function key(string $lastName): string
    {
        return MemberDirectory::identityKey($lastName, 'Camille', new \DateTimeImmutable(self::BIRTH));
    }
}
