<?php

namespace App\Tests\Service;

use App\Entity\Member;
use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Enum\PaymentMethod;
use App\Service\MembershipCardRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Carte d'adherent : deux pages, pas trois.
 *
 * Une page vaut exactement une carte. Tout ce qui depasse 53,98 mm de haut
 * part sur une page de plus, et la carte imprimee recto-verso n'a plus de
 * sens. Rien dans le gabarit ne le signale — le PDF se genere sans erreur —,
 * d'ou ce test : c'est le seul garde-fou a la prochaine retouche de mise en
 * page.
 */
final class MembershipCardTest extends KernelTestCase
{
    private function application(string $lastName = 'Durand', string $firstName = 'Camille'): MembershipApplication
    {
        $application = (new MembershipApplication())
            ->setLastName($lastName)
            ->setFirstName($firstName)
            ->setBirthDate(new \DateTimeImmutable('1990-04-02'))
            ->setPostalCode('89300')
            ->setCity('Joigny')
            ->setEmail('camille@example.test')
            ->setMembershipType(MembershipType::Individual)
            ->setSignaturePlace('Joigny')
            ->setSignatureName($firstName.' '.$lastName)
            ->setMember(new Member('EDJ-0001'));

        return $application->validate(PaymentMethod::Cheque, null);
    }

    private function pageCount(string $pdf): int
    {
        self::assertStringStartsWith('%PDF', $pdf);
        self::assertSame(1, preg_match('#/Type\s*/Pages\b[^>]*?/Count\s+(\d+)#s', $pdf, $found), 'Nombre de pages introuvable.');

        return (int) $found[1];
    }

    public function testTheCardIsExactlyTwoPages(): void
    {
        self::bootKernel();
        $renderer = static::getContainer()->get(MembershipCardRenderer::class);

        self::assertSame(2, $this->pageCount($renderer->render($this->application())));
    }

    /**
     * Un nom long est le cas qui faisait deborder : il allonge le verso sans
     * que rien d'autre ne change.
     */
    public function testALongNameStillFitsOnTwoPages(): void
    {
        self::bootKernel();
        $renderer = static::getContainer()->get(MembershipCardRenderer::class);

        $pdf = $renderer->render($this->application(
            'Van Der Meersch-Lefebvre',
            'Jean-Baptiste Alexandre',
        ));

        self::assertSame(2, $this->pageCount($pdf));
    }
}
