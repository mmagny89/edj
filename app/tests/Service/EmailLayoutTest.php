<?php

namespace App\Tests\Service;

use App\EventListener\MailLogoListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

final class EmailLayoutTest extends KernelTestCase
{
    public function testPasswordResetUsesBrandedLayout(): void
    {
        self::bootKernel();
        $html = static::getContainer()->get('twig')->render('emails/admin_password_reset.html.twig', ['url' => 'https://x.test/r', 'minutes' => 30]);

        self::assertStringContainsString('src="cid:logo"', $html);
        self::assertStringContainsString('Envie de Jouer', $html);
        self::assertStringContainsString('#e9c33a', $html);
        self::assertStringContainsString('href="https://x.test/r"', $html);
    }

    public function testListenerEmbedsLogoOnceAndOnlyWhenReferenced(): void
    {
        self::bootKernel();
        $listener = static::getContainer()->get(MailLogoListener::class);
        $envelope = new Envelope(new \Symfony\Component\Mime\Address('a@x.test'), [new \Symfony\Component\Mime\Address('b@x.test')]);

        $with = (new Email())->from('a@x.test')->to('b@x.test')->html('<img src="cid:logo">');
        $listener->onMessage(new MessageEvent($with, $envelope, 'null'));
        self::assertCount(1, $with->getAttachments());

        $without = (new Email())->from('a@x.test')->to('b@x.test')->html('<p>x</p>');
        $listener->onMessage(new MessageEvent($without, $envelope, 'null'));
        self::assertCount(0, $without->getAttachments());
    }
}
