<?php

namespace App\Service;

use App\Entity\MembershipApplication;
use App\Enum\MembershipType;
use App\Repository\MembershipApplicationRepository;

/**
 * Qui recoit un message adresse aux adherents d'une saison.
 *
 * Les regles, dans l'ordre :
 *  - seuls comptent les bulletins valides de la saison, ni annules, ni en
 *    attente, ni anonymises : on n'ecrit pas a quelqu'un dont l'adhesion n'est
 *    pas actee, ni a quelqu'un qui a demande l'effacement de ses donnees ;
 *  - une personne n'est comptee qu'une fois, meme avec deux bulletins dans la
 *    saison (passage de consommateur a annuel) : l'annuel prime ;
 *  - qui s'est desinscrit n'est jamais ecrit, quelle que soit la saison ;
 *  - on ecrit a l'adresse du bulletin, et au responsable legal d'un mineur —
 *    comme les autres courriels du site ;
 *  - une adresse ne recoit qu'un message : un foyer qui partage une boite
 *    recoit un courriel, pas un par enfant.
 */
final readonly class MailingAudience
{
    public function __construct(
        private MembershipApplicationRepository $applications,
    ) {
    }

    public function resolve(int $season, bool $annual, bool $consumers): MailingAudienceResult
    {
        // Un representant par personne, choisi avant tout filtre : une personne
        // passee de consommateur a annuel dans la saison est un adherent annuel,
        // pas un consommateur qu'on retrouverait en cochant « consommateurs ».
        /** @var array<int, MembershipApplication> $representatives */
        $representatives = [];
        foreach ($this->applications->findValidatedInSeason($season) as $application) {
            $memberId = $application->getMember()?->getId();
            if (null === $memberId) {
                continue;
            }
            $known = $representatives[$memberId] ?? null;
            if (null === $known || (MembershipType::Consumer === $known->getMembershipType() && MembershipType::Consumer !== $application->getMembershipType())) {
                $representatives[$memberId] = $application;
            }
        }

        $recipients = [];
        $taken = [];
        $members = $withoutAddress = $optedOut = $shared = 0;

        foreach ($representatives as $application) {
            $isConsumer = MembershipType::Consumer === $application->getMembershipType();
            if ($isConsumer ? !$consumers : !$annual) {
                continue;
            }

            ++$members;
            $member = $application->getMember();
            if (null === $member) {
                continue;
            }
            if ($member->isOptedOutOfMailings()) {
                ++$optedOut;

                continue;
            }

            $addresses = $this->addressesOf($application);
            if ([] === $addresses) {
                ++$withoutAddress;

                continue;
            }

            $reached = false;
            foreach ($addresses as $address) {
                $key = mb_strtolower($address);
                if (isset($taken[$key])) {
                    continue;
                }
                $taken[$key] = true;
                $recipients[] = new MailingRecipient($member, $application, $address);
                $reached = true;
            }
            if (!$reached) {
                ++$shared;
            }
        }

        return new MailingAudienceResult($recipients, $members, $withoutAddress, $optedOut, $shared);
    }

    /**
     * @return list<string>
     */
    private function addressesOf(MembershipApplication $application): array
    {
        $addresses = [];
        foreach ([$application->getEmail(), $application->isMinor() ? $application->getGuardianEmail() : null] as $address) {
            $address = trim((string) $address);
            if ('' !== $address && false !== filter_var($address, \FILTER_VALIDATE_EMAIL)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
