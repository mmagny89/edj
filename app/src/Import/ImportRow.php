<?php

namespace App\Import;

use App\Entity\MembershipApplication;
use App\Enum\PaymentMethod;

/**
 * Une ligne du fichier, prete a etre importee, avec ce que l'analyse a releve :
 * le bulletin qu'elle donnerait, l'etat prevu, et les points a verifier.
 */
final class ImportRow
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly int $line,
        public readonly MembershipApplication $application,
        public readonly bool $validated,
        public readonly ?PaymentMethod $payment,
        public readonly array $warnings = [],
    ) {
    }
}
