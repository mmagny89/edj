<?php

namespace App\Tests\Form\Guard;

use App\Form\Guard\FormGuard;
use PHPUnit\Framework\TestCase;

/**
 * Le jeton horodate des formulaires publics : emis a l'affichage, verifie a
 * l'envoi. Un robot remplit un formulaire en quelques millisecondes.
 */
final class FormGuardTest extends TestCase
{
    private function guard(int $min = 3, int $max = 3600, string $secret = 'secret-de-test'): FormGuard
    {
        return new FormGuard($secret, $min, $max);
    }

    public function testAFormSubmittedInstantlyIsRefused(): void
    {
        $guard = $this->guard();
        $now = new \DateTimeImmutable('2026-10-18 12:00:00');

        self::assertStringContainsString('trop vite', (string) $guard->check($guard->issue($now), $now));
        self::assertStringContainsString('trop vite', (string) $guard->check($guard->issue($now), $now->modify('+2 seconds')));
    }

    public function testAHumanPaceIsAccepted(): void
    {
        $guard = $this->guard();
        $now = new \DateTimeImmutable('2026-10-18 12:00:00');

        self::assertNull($guard->check($guard->issue($now), $now->modify('+3 seconds')));
        self::assertNull($guard->check($guard->issue($now), $now->modify('+20 minutes')));
    }

    public function testAFormLeftOpenTooLongIsRefused(): void
    {
        $guard = $this->guard(max: 3600);
        $now = new \DateTimeImmutable('2026-10-18 12:00:00');

        self::assertStringContainsString('trop longtemps', (string) $guard->check($guard->issue($now), $now->modify('+2 hours')));
    }

    /**
     * Le jeton ne se vieillit pas a la main : un robot qui voudrait paraitre
     * patient modifierait l'horodatage, et la signature ne correspondrait plus.
     */
    public function testAnAgedTokenIsRefused(): void
    {
        $guard = $this->guard();
        $now = new \DateTimeImmutable('2026-10-18 12:00:00');
        [$timestamp, $signature] = explode('.', $guard->issue($now));

        $forged = ($timestamp - 600).'.'.$signature;

        self::assertStringContainsString('vérifié', (string) $guard->check($forged, $now->modify('+10 seconds')));
    }

    public function testATokenSignedWithAnotherSecretIsRefused(): void
    {
        $now = new \DateTimeImmutable('2026-10-18 12:00:00');
        $foreign = $this->guard(secret: 'un-autre-secret')->issue($now);

        self::assertNotNull($this->guard()->check($foreign, $now->modify('+10 seconds')));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function garbage(): iterable
    {
        yield 'absent' => [null];
        yield 'vide' => [''];
        yield 'sans signature' => ['1760000000'];
        yield 'horodatage non numerique' => ['abc.def'];
        yield 'signature vide' => ['1760000000.'];
        yield 'injection' => ["1760000000.'; DROP TABLE member; --"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('garbage')]
    public function testGarbageIsRefusedWithoutAnError(?string $token): void
    {
        self::assertNotNull($this->guard()->check($token, new \DateTimeImmutable('2026-10-18 12:00:00')));
    }
}
