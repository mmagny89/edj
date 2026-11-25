<?php

namespace App\Service;

use App\Entity\Event;

/**
 * Exporte un evenement vers l'agenda d'un visiteur : fichier iCalendar (.ics)
 * ou lien « ajouter » de Google Agenda.
 *
 * L'horaire de l'evenement est un libelle libre (« 18h30 - 00h », « Soiree »).
 * Quand il contient une ou deux heures, l'evenement est place a l'heure ; sinon,
 * et pour tout evenement sur plusieurs jours, il est ajoute « toute la journee »
 * avec le libelle repris dans la description.
 */
final class EventCalendarExporter
{
    private const TIMEZONE = 'Europe/Paris';
    private const DEFAULT_LOCATION = 'Maison des Associations, 89300 Joigny';

    /**
     * Duree retenue quand le libelle ne donne que l'heure de debut.
     */
    private const DEFAULT_DURATION = '+2 hours';

    public function toIcs(Event $event, string $url): string
    {
        $window = $this->window($event);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Envie de Jouer//Calendrier//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:event-'.$event->getId().'@enviedejouer89.fr',
            'DTSTAMP:'.gmdate('Ymd\THis\Z'),
        ];

        if ($window['allDay']) {
            $lines[] = 'DTSTART;VALUE=DATE:'.$window['start']->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$window['end']->format('Ymd');
        } else {
            $lines[] = 'DTSTART:'.$this->utc($window['start']);
            $lines[] = 'DTEND:'.$this->utc($window['end']);
        }

        $lines[] = 'SUMMARY:'.$this->escape((string) $event->getTitle());
        $lines[] = 'LOCATION:'.$this->escape($this->location($event));
        $lines[] = 'DESCRIPTION:'.$this->escape($this->description($event, $url));
        $lines[] = 'URL:'.$url;
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    public function googleUrl(Event $event, string $url): string
    {
        $window = $this->window($event);

        $dates = $window['allDay']
            ? $window['start']->format('Ymd').'/'.$window['end']->format('Ymd')
            : $this->utc($window['start']).'/'.$this->utc($window['end']);

        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => (string) $event->getTitle(),
            'dates' => $dates,
            'location' => $this->location($event),
            'details' => $this->description($event, $url),
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable, allDay: bool}
     */
    private function window(Event $event): array
    {
        $first = $event->getStartsAt() ?? Day::today();
        $last = $event->lastDay() ?? $first;
        $zone = new \DateTimeZone(self::TIMEZONE);

        if ($first == $last) {
            $times = $this->times((string) $event->getTimeLabel());

            if ([] !== $times) {
                $day = $first->format('Y-m-d');
                $start = new \DateTimeImmutable($day.' '.$times[0], $zone);
                $end = isset($times[1])
                    ? new \DateTimeImmutable($day.' '.$times[1], $zone)
                    : $start->modify(self::DEFAULT_DURATION);

                // « 18h30 - 00h » : la fin tombe apres minuit.
                if ($end <= $start) {
                    $end = $end->modify('+1 day');
                }

                return ['start' => $start, 'end' => $end, 'allDay' => false];
            }
        }

        // Fin exclusive pour un evenement sur la journee entiere.
        return [
            'start' => $first,
            'end' => $last->modify('+1 day'),
            'allDay' => true,
        ];
    }

    /**
     * Heures lues dans le libelle (« 18h30 - 00h » donne 18:30 puis 00:00).
     *
     * @return list<string> au plus deux heures, au format « HH:MM »
     */
    private function times(string $label): array
    {
        if (!preg_match_all('/\b(\d{1,2})\s*h\s*(\d{2})?(?!\d)/iu', $label, $matches, \PREG_SET_ORDER)) {
            return [];
        }

        $times = [];
        foreach (\array_slice($matches, 0, 2) as $match) {
            $hour = (int) $match[1];
            $minute = (int) ($match[2] ?? 0);

            if ($hour > 23 || $minute > 59) {
                return [];
            }

            $times[] = \sprintf('%02d:%02d', $hour, $minute);
        }

        return $times;
    }

    private function location(Event $event): string
    {
        return $event->getLocation() ?: self::DEFAULT_LOCATION;
    }

    private function description(Event $event, string $url): string
    {
        $parts = [];

        if ($event->getDescription()) {
            $parts[] = $event->getDescription();
        }

        $parts[] = 'Horaire : '.$event->getTimeLabel();
        $parts[] = $url;

        return implode("\n", $parts);
    }

    private function utc(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private function escape(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $text);
    }

    /**
     * RFC 5545 : 75 octets au plus par ligne, suite precedee d'un espace, sans
     * couper un caractere multi-octets.
     */
    private function fold(string $line): string
    {
        if (\strlen($line) <= 75) {
            return $line;
        }

        $chunks = [];
        $current = '';
        $limit = 75;

        foreach (mb_str_split($line) as $char) {
            if (\strlen($current) + \strlen($char) > $limit) {
                $chunks[] = $current;
                $current = '';
                $limit = 74;
            }
            $current .= $char;
        }
        $chunks[] = $current;

        return implode("\r\n ", $chunks);
    }
}
