<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\IdleSessionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Une session restee inactive est coupee. Ce qui compte : qu'elle le soit passe le
 * delai, qu'elle ne le soit pas avant, et qu'on ne demarre jamais de session pour
 * un visiteur qui n'en a pas.
 */
final class IdleSessionSubscriberTest extends TestCase
{
    private function event(?\Symfony\Component\HttpFoundation\Session\SessionInterface $session, bool $hasCookie = true, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $request = new Request();
        if (null !== $session) {
            $request->setSession($session);
        }
        if ($hasCookie) {
            $request->cookies->set(session_name(), 'abc');
        }

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }

    private function session(int $lastUsed, bool $started = true): FlashBagAwareSessionInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $metadata = $this->createStub(MetadataBag::class);
        $metadata->method('getLastUsed')->willReturn($lastUsed);

        $session = $this->createMock(FlashBagAwareSessionInterface::class);
        $session->method('isStarted')->willReturn($started);
        $session->method('getName')->willReturn(session_name());
        $session->method('getMetadataBag')->willReturn($metadata);
        $session->method('getFlashBag')->willReturn(new FlashBag());

        return $session;
    }

    public function testAnIdleSessionIsInvalidated(): void
    {
        $session = $this->session(time() - 1801);
        $session->expects(self::once())->method('invalidate');

        (new IdleSessionSubscriber(1800))->onRequest($this->event($session));
    }

    public function testASessionStillActiveIsLeftAlone(): void
    {
        $session = $this->session(time() - 600);
        $session->expects(self::never())->method('invalidate');

        (new IdleSessionSubscriber(1800))->onRequest($this->event($session));
    }

    /**
     * Pas de cookie de session, pas de session : on n'en ouvre pas une pour un
     * visiteur anonyme.
     */
    public function testNoSessionIsStartedForAnAnonymousVisitor(): void
    {
        $session = $this->session(time() - 99999);
        $session->expects(self::never())->method('start');
        $session->expects(self::never())->method('invalidate');

        (new IdleSessionSubscriber(1800))->onRequest($this->event($session, hasCookie: false));
    }

    public function testSubRequestsAreIgnored(): void
    {
        $session = $this->session(time() - 99999);
        $session->expects(self::never())->method('invalidate');

        (new IdleSessionSubscriber(1800))->onRequest($this->event($session, type: HttpKernelInterface::SUB_REQUEST));
    }

    public function testTheInvalidatedSessionExplainsWhy(): void
    {
        $flashes = new FlashBag();
        $metadata = $this->createStub(MetadataBag::class);
        $metadata->method('getLastUsed')->willReturn(time() - 5000);
        $session = $this->createStub(FlashBagAwareSessionInterface::class);
        $session->method('isStarted')->willReturn(true);
        $session->method('getName')->willReturn(session_name());
        $session->method('getMetadataBag')->willReturn($metadata);
        $session->method('getFlashBag')->willReturn($flashes);

        (new IdleSessionSubscriber(1800))->onRequest($this->event($session));

        self::assertStringContainsString('expiré', $flashes->peek('error')[0]);
    }
}
