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

    private static function humanize(?string $value, string $fallback): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return ucfirst(str_replace('_', ' ', $value));
    }
}
