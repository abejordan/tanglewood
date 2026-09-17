<?php

namespace App\Tags;

use Carbon\Carbon;
use Statamic\Facades\Entry;
use Statamic\Tags\Tags;

class EventsUpcoming extends Tags
{
    protected static $handle = 'events_upcoming';

    public function index()
    {
        $limit = (int) $this->params->get('limit', 100);
        $windowDays = (int) $this->params->get('window_days', 365);

        $now = Carbon::now();
        $todayStart = $now->copy()->startOfDay();
        $windowEnd = $now->copy()->addDays($windowDays)->endOfDay();

        $entries = Entry::query()
            ->where('collection', 'events')
            ->where('published', true)
            ->get();

        $occurrences = [];

        foreach ($entries as $entry) {
            // Events is a dated collection; the entry's date lives in the filename, not the "date" frontmatter field.
            $start = $this->parseDate($entry->date());
            if (!$start) {
                continue;
            }

            $end = $this->parseDate($entry->value('end'));
            $recurrence = $entry->value('recurrence') ?: 'none';
            $interval = max(1, (int) ($entry->value('recurrence_interval') ?: 1));
            $until = $this->parseDate($entry->value('recurrence_until'));
            if ($until) {
                $until = $until->endOfDay();
            }

            if ($recurrence === 'none') {
                if ($start->gte($todayStart) && $start->lte($windowEnd)) {
                    $occurrences[] = $this->buildOccurrence($entry, $start, $end, false);
                }
                continue;
            }

            $duration = ($end && $end->gt($start)) ? $end->getTimestamp() - $start->getTimestamp() : null;

            $cursor = $start->copy();
            $guard = 0;
            while ($cursor->lt($todayStart) && $guard < 2000) {
                $cursor = $this->advance($cursor, $recurrence, $interval);
                $guard++;
            }

            // Only surface the next upcoming occurrence; the card's "Repeats" badge conveys the ongoing pattern.
            if ($cursor->lte($windowEnd) && (!$until || $cursor->lte($until))) {
                $occurrenceEnd = $duration !== null
                    ? $cursor->copy()->addSeconds($duration)
                    : null;

                $occurrences[] = $this->buildOccurrence($entry, $cursor->copy(), $occurrenceEnd, true);
            }
        }

        usort($occurrences, fn ($a, $b) => $a['sort_ts'] <=> $b['sort_ts']);

        $occurrences = array_slice($occurrences, 0, $limit);

        if (empty($occurrences)) {
            return $this->parse(['no_results' => true]);
        }

        return $this->parseLoop($occurrences);
    }

    protected function advance(Carbon $cursor, string $recurrence, int $interval): Carbon
    {
        return match ($recurrence) {
            'weekly' => $cursor->copy()->addWeeks($interval),
            'monthly' => $cursor->copy()->addMonthsNoOverflow($interval),
            'yearly' => $cursor->copy()->addYears($interval),
            default => $cursor->copy()->addWeek(),
        };
    }

    protected function parseDate($value): ?Carbon
    {
        if (!$value) {
            return null;
        }
        // Normalize every date into the app timezone so display formatting matches what editors enter in the CP.
        $tz = config('app.timezone') ?: 'UTC';
        if ($value instanceof Carbon) {
            return $value->copy()->setTimezone($tz);
        }
        try {
            return Carbon::parse((string) $value)->setTimezone($tz);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function buildOccurrence($entry, Carbon $start, ?Carbon $end, bool $isRecurring): array
    {
        $data = $entry->toAugmentedArray();

        $data['date'] = $start;
        $data['end'] = $end;
        $data['is_recurring'] = $isRecurring;
        $data['recurrence_label'] = $isRecurring
            ? $this->recurrenceLabel($entry->value('recurrence'), (int) ($entry->value('recurrence_interval') ?: 1))
            : null;
        $data['sort_ts'] = $start->getTimestamp();

        $title = (string) $entry->value('title');
        $location = (string) ($entry->value('location') ?: '');
        $description = trim(strip_tags((string) ($entry->value('description') ?: '')));
        $isAllDay = $start->format('H:i:s') === '00:00:00';

        $data['gcal_url'] = $this->googleCalendarUrl($title, $description, $location, $start, $end, $isAllDay);
        $data['ics_url'] = $this->icsDataUri($entry, $title, $description, $location, $start, $end, $isAllDay);

        return $data;
    }

    protected function googleCalendarUrl(string $title, string $description, string $location, Carbon $start, ?Carbon $end, bool $isAllDay): string
    {
        if ($isAllDay) {
            $endDate = $end && $end->gt($start) ? $end->copy() : $start->copy();
            // Google Calendar treats the end date as exclusive for all-day events.
            $dates = $start->format('Ymd') . '/' . $endDate->addDay()->format('Ymd');
        } else {
            $endDt = $end && $end->gt($start) ? $end : $start->copy()->addHour();
            $dates = $start->format('Ymd\THis') . '/' . $endDt->format('Ymd\THis');
        }

        $params = http_build_query([
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => $dates,
            'details' => $description,
            'location' => $location,
        ]);

        return 'https://www.google.com/calendar/render?' . $params;
    }

    protected function icsDataUri($entry, string $title, string $description, string $location, Carbon $start, ?Carbon $end, bool $isAllDay): string
    {
        $escape = fn ($v) => addcslashes(str_replace(["\r\n", "\r", "\n"], '\\n', (string) $v), ",;\\");

        $uid = ($entry->id() ?? 'event') . '-' . $start->getTimestamp() . '@tanglewood';
        $dtstamp = Carbon::now('UTC')->format('Ymd\THis\Z');

        if ($isAllDay) {
            $endDate = $end && $end->gt($start) ? $end->copy() : $start->copy();
            $dtStart = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
            $dtEnd = 'DTEND;VALUE=DATE:' . $endDate->addDay()->format('Ymd');
        } else {
            $endDt = $end && $end->gt($start) ? $end : $start->copy()->addHour();
            $dtStart = 'DTSTART:' . $start->format('Ymd\THis');
            $dtEnd = 'DTEND:' . $endDt->format('Ymd\THis');
        }

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Tanglewood//Events//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $dtstamp,
            $dtStart,
            $dtEnd,
            'SUMMARY:' . $escape($title),
        ];
        if ($description !== '') {
            $lines[] = 'DESCRIPTION:' . $escape($description);
        }
        if ($location !== '') {
            $lines[] = 'LOCATION:' . $escape($location);
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        $ics = implode("\r\n", $lines) . "\r\n";

        return 'data:text/calendar;charset=utf-8,' . rawurlencode($ics);
    }

    protected function recurrenceLabel(?string $recurrence, int $interval): string
    {
        return match ($recurrence) {
            'weekly' => $interval === 1 ? 'Repeats weekly' : "Repeats every {$interval} weeks",
            'monthly' => $interval === 1 ? 'Repeats monthly' : "Repeats every {$interval} months",
            'yearly' => $interval === 1 ? 'Repeats yearly' : "Repeats every {$interval} years",
            default => 'Recurring event',
        };
    }
}
