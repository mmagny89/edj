<?php

namespace App\Service;

use App\Entity\Member;
use App\Entity\MembershipApplication;

/**
 * Une adresse a laquelle un message part, et la personne au nom de qui : ses
 * variables (prenom, numero...) viennent de son bulletin, et son lien de
 * desinscription de son adherent.
 */
final readonly class MailingRecipient
{
    public function __construct(
        public Member $member,
        public MembershipApplication $application,
        public string $address,
    ) {
    }
}
