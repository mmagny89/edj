<?php

namespace App\Tests\Entity;

use App\Entity\User;
use App\Security\TemporaryPassword;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

final class UserTest extends TestCase
{
    private function user(string $email = 'a@example.test', string $role = User::ROLE_CONTRIBUTOR): User
    {
        return (new User())->setEmail($email)->setRoles([$role])->setPassword('hash-complet-du-mot-de-passe');
    }

    /**
     * Ce que Symfony garde en session : le mot de passe y est un condense
     * CRC32C (User::__serialize). La session est « l'utilisateur d'avant », la
     * base fournit « l'utilisateur rafraichi ».
     */
    private function sessionCopy(User $user): User
    {
        /** @var User $copy */
        $copy = unserialize(serialize($user));

        return $copy;
    }

    public function testASessionStaysValidWhileNothingChanged(): void
    {
        $user = $this->user();

        self::assertTrue($this->sessionCopy($user)->isEqualTo($user));
    }

    public function testBlockingCutsTheOpenSession(): void
    {
        $session = $this->sessionCopy($this->user());
        $refreshed = $this->user()->block();

        self::assertFalse($session->isEqualTo($refreshed));
    }

    public function testChangingThePasswordCutsTheOpenSession(): void
    {
        $session = $this->sessionCopy($this->user());
        $refreshed = $this->user()->setPassword('un-autre-hash');

        self::assertFalse($session->isEqualTo($refreshed));
    }

    public function testChangingTheRoleCutsTheOpenSession(): void
    {
        $session = $this->sessionCopy($this->user());
        $refreshed = $this->user('a@example.test', User::ROLE_SUPER_ADMIN);

        self::assertFalse($session->isEqualTo($refreshed));
    }

    public function testUnblockingDoesNotReviveTheOldSessionButAllowsANewLogin(): void
    {
        $user = $this->user()->block()->unblock();

        self::assertFalse($user->isBlocked());
        self::assertTrue($this->sessionCopy($user)->isEqualTo($user));
    }

    public function testBlockingKeepsTheFirstDate(): void
    {
        $first = new \DateTimeImmutable('2026-01-01');
        $user = $this->user()->block($first)->block(new \DateTimeImmutable('2026-06-01'));

        self::assertSame($first, $user->getBlockedAt());
    }

    public function testABlockedAccountCannotLogIn(): void
    {
        $this->expectException(CustomUserMessageAccountStatusException::class);

        (new UserChecker())->checkPreAuth($this->user()->block());
    }

    public function testAnActiveAccountPassesTheChecker(): void
    {
        (new UserChecker())->checkPreAuth($this->user());

        $this->addToAssertionCount(1);
    }

    public function testTemporaryPasswordIsLongRandomAndReadable(): void
    {
        $a = TemporaryPassword::generate();
        $b = TemporaryPassword::generate();

        self::assertMatchesRegularExpression('/^[A-Za-z2-9]{4}(-[A-Za-z2-9]{4}){3}$/', $a);
        self::assertNotSame($a, $b);
        self::assertDoesNotMatchRegularExpression('/[0O1lI]/', $a, 'Pas de caractères qui se confondent.');
    }
}
