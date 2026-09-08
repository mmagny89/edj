<?php

namespace App\Service;

use App\Dto\SeasonGenerationRequest;
use App\Entity\Event;
use App\Enum\EventType;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cree en une fois les dates recurrentes d'une saison : les soirees du mardi
 * et les apres-midi du 3e samedi.
 *
 * Chaque date creee reste un evenement ordinaire, modifiable ou supprimable
 * individuellement — une soiree annulee se retire, un horaire exceptionnel se
 * corrige, sans toucher aux autres.
 */
final readonly class SeasonGenerator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EventRepository $events,
    ) {
    }

    /**
     * @return array{created: int, skipped: int}
     */
    public function generate(SeasonGenerationRequest $request): array
    {
        $from = $request->from;
        $to = $request->to;

        if (null === $from || null === $to) {
            return ['created' => 0, 'skipped' => 0];
        }

        // Relancer la generation sur une periode deja couverte ne doit rien
        // dupliquer : c'est le cas normal quand on prolonge une saison de
        // quelques mois. Une date deja presente est comptee comme ignoree,
        // et le message le dit — sans quoi un second clic paraitrait sans
        // effet.
        $existing = $this->events->existingKeys($from, $to);

        $created = 0;
        $skipped = 0;

        foreach ($this->dates($request, $from, $to) as [$type, $date]) {
            $key = $type->value.'|'.$date->format('Y-m-d');

            if (isset($existing[$key])) {
                ++$skipped;

                continue;
            }

            $existing[$key] = true;
            $this->entityManager->persist($this->buildEvent($type, $date));
            ++$created;
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @return iterable<array{0: EventType, 1: \DateTimeImmutable}>
     */
    private function dates(SeasonGenerationRequest $request, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        if ($request->weekly) {
            // "tuesday this week" plutot qu'un calcul d'offset : la borne de
            // depart peut tomber n'importe quel jour, y compris un mardi,
            // qu'il faut alors inclure.
            $tuesday = 2 === (int) $from->format('N')
                ? $from
                : $from->modify('next tuesday');

            for ($date = $tuesday; $date <= $to; $date = $date->modify('+7 days')) {
                yield [EventType::Weekly, $date];
            }
        }

        if (!$request->monthly) {
            return;
        }

        $month = $from->modify('first day of this month');
        $lastMonth = $to->modify('first day of this month');

        for (; $month <= $lastMonth; $month = $month->modify('+1 month')) {
            $thirdSaturday = $month->modify('third saturday of this month');

            if ($thirdSaturday >= $from && $thirdSaturday <= $to) {
                yield [EventType::Monthly, $thirdSaturday];
            }
        }
    }

    private function buildEvent(EventType $type, \DateTimeImmutable $date): Event
    {
        return (new Event())
            ->setType($type)
            ->setStartsAt($date)
            ->setTitle($type->defaultTitle() ?? '')
            ->setTimeLabel($type->defaultTimeLabel() ?? '')
            ->setLocation(self::DEFAULT_LOCATION);
    }

    /**
     * Meme valeur que le defaut du formulaire de saisie : les dates generees
     * et les dates saisies a la main doivent se ressembler.
     */
    private const DEFAULT_LOCATION = 'Maison des Associations, Joigny';
}
