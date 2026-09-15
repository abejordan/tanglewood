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
            $start = $this->parseDate($entry->value('date'));
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

            $guard = 0;
            while ($cursor->lte($windowEnd) && (!$until || $cursor->lte($until)) && $guard < 500) {
                $occurrenceEnd = $duration !== null
                    ? $cursor->copy()->addSeconds($duration)
                    : null;

                $occurrences[] = $this->buildOccurrence($entry, $cursor->copy(), $occurrenceEnd, true);

                $cursor = $this->advance($cursor, $recurrence, $interval);
                $guard++;
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
        if ($value instanceof Carbon) {
            return $value->copy();
        }
        try {
            return Carbon::parse((string) $value);
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

        return $data;
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
