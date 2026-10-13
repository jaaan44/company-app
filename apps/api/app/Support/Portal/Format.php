<?php

namespace App\Support\Portal;

use Carbon\CarbonImmutable;

/**
 * Display helpers for the Staff Web Portal (Phase 34A). Ports of the
 * mobile app's `home_formatting.dart` and people labels, so a value reads
 * the same in both clients. Every date and time is shown in the company
 * timezone from the API's `company_day.utc_offset` (DEC-052), never the
 * server's or the browser's.
 */
final class Format
{
    /** "Wed 24 Sep". */
    public static function dayMonth(CarbonImmutable $companyDate): string
    {
        return $companyDate->format('D j M');
    }

    /** "22 Sep 2026" for an API `YYYY-MM-DD` date, or null. */
    public static function date(?string $apiDate): ?string
    {
        return $apiDate === null || $apiDate === '' ? null : CarbonImmutable::parse($apiDate)->format('j M Y');
    }

    /**
     * "All day", "09:00 – 10:30", or for a multi-day entry
     * "From Tue 23 Sep – 10:30" / "09:00 – until Thu 25 Sep".
     *
     * @param  array<string, mixed>  $item  a `/me/home` Today item
     * @param  array<string, mixed>  $day  the `/me/home` `company_day`
     */
    public static function todayTime(array $item, array $day): string
    {
        if (($item['is_all_day'] ?? false) === true) {
            return 'All day';
        }

        $offset = (string) $day['utc_offset'];
        $start = CarbonImmutable::parse((string) $item['starts_at'])->setTimezone($offset);
        $end = CarbonImmutable::parse((string) $item['ends_at'])->setTimezone($offset);
        $dayStart = CarbonImmutable::parse((string) $day['starts_at']);
        $dayEnd = CarbonImmutable::parse((string) $day['ends_at']);

        $from = $start->lt($dayStart) ? 'From '.self::dayMonth($start) : $start->format('H:i');
        $until = $end->gt($dayEnd) ? 'until '.self::dayMonth($end) : $end->format('H:i');

        return $from.' – '.$until;
    }

    /** @param  array<string, mixed>  $item */
    public static function todayLabel(array $item): string
    {
        return ($item['source_type'] ?? null) === 'task'
            ? 'Task due · '.self::taskStatus($item['task_status'] ?? null)
            : self::activityType($item['activity_type'] ?? null);
    }

    public static function activityType(?string $value): string
    {
        return match ($value) {
            'meeting' => 'Meeting',
            'client_visit' => 'Client visit',
            'service_appointment' => 'Service appointment',
            'company_event' => 'Company event',
            'training' => 'Training',
            'internal_activity' => 'Internal activity',
            'other' => 'Other',
            default => self::humanize($value, 'Scheduled'),
        };
    }

    public static function taskStatus(?string $value): string
    {
        return match ($value) {
            'todo' => 'To do',
            'in_progress' => 'In progress',
            'blocked' => 'Blocked',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => self::humanize($value, 'Task'),
        };
    }

    public static function role(?string $value): ?string
    {
        return match ($value) {
            'administrator' => 'Administrator',
            'manager' => 'Manager',
            'staff' => 'Staff',
            null, '' => null,
            default => self::humanize($value, ''),
        };
    }

    /**
     * "Position · Department", either part alone, or null.
     *
     * @param  array<string, mixed>  $staff
     */
    public static function roleLine(array $staff): ?string
    {
        $parts = array_filter([
            $staff['position']['title'] ?? null,
            $staff['department']['name'] ?? null,
        ], fn ($part) => is_string($part) && $part !== '');

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * "First Last" when it differs from the display name, else null.
     *
     * @param  array<string, mixed>  $staff
     */
    public static function distinctFullName(array $staff): ?string
    {
        $full = trim(($staff['first_name'] ?? '').' '.($staff['last_name'] ?? ''));

        return $full !== '' && $full !== ($staff['display_name'] ?? null) ? $full : null;
    }

    /** "3 Sep", or "3 Sep 2027" when not in the company year. */
    public static function shortDate(string $apiDate, ?string $companyDate): string
    {
        $date = CarbonImmutable::parse($apiDate);

        return $companyDate !== null && substr($companyDate, 0, 4) === $date->format('Y')
            ? $date->format('j M')
            : $date->format('j M Y');
    }

    /**
     * A task's due state against the company day (mobile `dueState`):
     * 'none', 'not_due', 'due_today' or 'overdue'. A closed task is never
     * due; the list's own flags win when present.
     *
     * @param  array<string, mixed>  $task
     */
    public static function taskDueState(array $task, ?string $companyDate): string
    {
        $due = $task['due_date'] ?? null;
        if ($due === null) {
            return 'none';
        }
        if (in_array($task['status'] ?? null, ['completed', 'cancelled'], true)) {
            return 'not_due';
        }
        if (isset($task['is_overdue'], $task['is_due_today'])) {
            return $task['is_overdue'] ? 'overdue' : ($task['is_due_today'] ? 'due_today' : 'not_due');
        }
        if ($companyDate === null) {
            return 'not_due';
        }

        return $due < $companyDate ? 'overdue' : ($due === $companyDate ? 'due_today' : 'not_due');
    }

    /**
     * The list's due label: "Due today", "Overdue · 3 Sep", "Due 12 Oct",
     * or null without a due date.
     *
     * @param  array<string, mixed>  $task
     */
    public static function taskDueLabel(array $task, ?string $companyDate): ?string
    {
        $due = $task['due_date'] ?? null;
        if ($due === null) {
            return null;
        }
        $short = self::shortDate($due, $companyDate);

        return match (self::taskDueState($task, $companyDate)) {
            'due_today' => 'Due today',
            'overdue' => 'Overdue · '.$short,
            default => 'Due '.$short,
        };
    }

    /** "1 h 30 min", "2 h", "45 min". */
    public static function duration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h === 0) {
            return $m.' min';
        }

        return $m === 0 ? $h.' h' : $h.' h '.$m.' min';
    }

    /**
     * A day heading: "Today", "Yesterday" (and "Tomorrow" when asked),
     * otherwise "Wed 7 Oct", with the year when not the company year.
     */
    public static function dayLabel(string $date, ?string $companyDate, bool $withTomorrow = false): string
    {
        if ($companyDate !== null) {
            $today = CarbonImmutable::parse($companyDate);
            if ($date === $companyDate) {
                return 'Today';
            }
            if ($date === $today->subDay()->toDateString()) {
                return 'Yesterday';
            }
            if ($withTomorrow && $date === $today->addDay()->toDateString()) {
                return 'Tomorrow';
            }
        }
        $d = CarbonImmutable::parse($date);

        return $companyDate === null || substr($companyDate, 0, 4) === $d->format('Y')
            ? self::dayMonth($d)
            : self::dayMonth($d).' '.$d->format('Y');
    }

    /** "14 – 20 Oct", or "28 Oct – 3 Nov" across months. */
    public static function weekRange(string $first, string $last): string
    {
        $a = CarbonImmutable::parse($first);
        $b = CarbonImmutable::parse($last);

        return $a->month === $b->month
            ? $a->format('j').' – '.$b->format('j M')
            : $a->format('j M').' – '.$b->format('j M');
    }

    /**
     * An item's time on one company day ("All day", "09:00 – 10:30", or
     * "From Tue 13 Oct – 10:30" / "09:00 – until Thu 15 Oct").
     *
     * @param  array<string, mixed>  $item
     */
    public static function itemTimeOn(array $item, string $date, string $utcOffset): string
    {
        if (($item['is_all_day'] ?? false) === true) {
            return 'All day';
        }
        [$dayStart, $dayEnd] = self::dayBounds($date, $utcOffset);
        $start = CarbonImmutable::parse((string) $item['starts_at']);
        $end = CarbonImmutable::parse((string) $item['ends_at']);
        $from = $start->lt($dayStart) ? 'From '.self::dayMonth($start->setTimezone($utcOffset)) : $start->setTimezone($utcOffset)->format('H:i');
        $until = $end->gt($dayEnd) ? 'until '.self::dayMonth($end->setTimezone($utcOffset)) : $end->setTimezone($utcOffset)->format('H:i');

        return $from.' – '.$until;
    }

    /**
     * A company date's UTC bounds: [start, next day's start).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function dayBounds(string $date, string $utcOffset): array
    {
        $start = CarbonImmutable::parse($date.' 00:00:00', $utcOffset)->utc();

        return [$start, $start->addDay()];
    }

    /**
     * Whether an item covers any part of a company day (mobile
     * `overlaps`): an item ending exactly at the day's start belongs to
     * the day before only; a zero-length item at the start belongs here.
     *
     * @param  array<string, mixed>  $item
     */
    public static function overlapsDay(array $item, string $date, string $utcOffset): bool
    {
        [$dayStart, $dayEnd] = self::dayBounds($date, $utcOffset);
        $start = CarbonImmutable::parse((string) $item['starts_at']);
        $end = CarbonImmutable::parse((string) $item['ends_at']);

        return $start->lt($dayEnd) && ($end->gt($dayStart) || ! $start->lt($dayStart));
    }

    /** @param  array<string, mixed>  $item */
    public static function scheduleLabel(array $item): string
    {
        return match ($item['source_type'] ?? null) {
            'task' => 'Task · '.self::taskStatus($item['task_status'] ?? null),
            'leave' => 'Leave',
            default => self::activityType($item['activity_type'] ?? null),
        };
    }

    /**
     * A schedule entry's "When" in company time: "Wed 14 Oct · 09:00 –
     * 10:30", "Wed 14 Oct · All day", or across days with both dates.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function entryWhen(array $entry, string $utcOffset): string
    {
        $start = CarbonImmutable::parse((string) $entry['starts_at'])->setTimezone($utcOffset);
        $end = CarbonImmutable::parse((string) $entry['ends_at'])->setTimezone($utcOffset);
        $sameDay = $start->toDateString() === $end->toDateString();

        if (($entry['is_all_day'] ?? false) === true) {
            return $sameDay
                ? self::dayMonth($start).' · All day'
                : self::dayMonth($start).' – '.self::dayMonth($end).' · All day';
        }

        return $sameDay
            ? self::dayMonth($start).' · '.$start->format('H:i').' – '.$end->format('H:i')
            : self::dayMonth($start).' '.$start->format('H:i').' – '.self::dayMonth($end).' '.$end->format('H:i');
    }

    public static function priority(?string $value): string
    {
        return match ($value) {
            'low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent',
            default => self::humanize($value, 'Normal'),
        };
    }

    public static function projectStatus(?string $value): string
    {
        return match ($value) {
            'planned' => 'Planned', 'active' => 'Active', 'on_hold' => 'On hold',
            'completed' => 'Completed', 'cancelled' => 'Cancelled',
            default => self::humanize($value, 'Unknown'),
        };
    }

    public static function milestoneStatus(?string $value): string
    {
        return match ($value) {
            'pending' => 'Pending', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
            default => self::humanize($value, 'Unknown'),
        };
    }

    public static function projectRole(?string $value): string
    {
        return match ($value) {
            'project_lead' => 'Project lead', 'member' => 'Member',
            default => self::humanize($value, 'Member'),
        };
    }

    /**
     * A status's chip tone, shared by every status concept (06 "Status
     * presentation"): neutral, info, danger, success or muted.
     */
    public static function tone(?string $status): string
    {
        return match ($status) {
            'in_progress', 'active', 'planned', 'submitted', 'pending' => 'info',
            'blocked', 'rejected', 'overdue' => 'danger',
            'completed', 'approved', 'reviewed', 'resolved' => 'success',
            'cancelled', 'inactive', 'on_hold', 'closed' => 'muted',
            default => 'neutral',
        };
    }

    /**
     * A client's address as display lines: the street lines, "city,
     * state postal", the country; blank parts dropped.
     *
     * @param  array<string, mixed>  $client
     * @return list<string>
     */
    public static function addressLines(array $client): array
    {
        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $statePostal = implode(' ', array_filter([$clean($client['state_province'] ?? null), $clean($client['postal_code'] ?? null)]));
        $locality = implode(', ', array_filter([$clean($client['city'] ?? null), $statePostal !== '' ? $statePostal : null]));

        return array_values(array_filter([
            $clean($client['address_line1'] ?? null),
            $clean($client['address_line2'] ?? null),
            $locality !== '' ? $locality : null,
            $clean($client['country'] ?? null),
        ]));
    }

    private static function humanize(?string $value, string $fallback): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return ucfirst(str_replace('_', ' ', $value));
    }
}
