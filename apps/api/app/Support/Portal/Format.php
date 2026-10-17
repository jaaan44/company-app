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
     * presentation"): neutral, info, warning, danger, success or muted.
     * Warning (Phase 37B) is for a state someone has to act on that isn't
     * an error: a suspended account.
     */
    public static function tone(?string $status): string
    {
        return match ($status) {
            'in_progress', 'active', 'planned', 'submitted', 'pending' => 'info',
            'suspended' => 'warning',
            'blocked', 'rejected', 'overdue' => 'danger',
            'completed', 'approved', 'reviewed', 'resolved', 'published' => 'success',
            'cancelled', 'inactive', 'on_hold', 'closed', 'separated', 'archived' => 'muted',
            default => 'neutral',
        };
    }

    /** A staff record's employment status (Phase 37B: one label everywhere). */
    public static function staffStatus(?string $value): string
    {
        return match ($value) {
            'active' => 'Active', 'inactive' => 'Inactive', 'separated' => 'Separated',
            default => self::humanize($value, 'Unknown'),
        };
    }

    /** A sign-in account's status. */
    public static function accountStatus(?string $value): string
    {
        return match ($value) {
            'active' => 'Active', 'suspended' => 'Suspended', 'inactive' => 'Inactive',
            default => self::humanize($value, 'Unknown'),
        };
    }

    /** An announcement's status. */
    public static function announcementStatus(?string $value): string
    {
        return match ($value) {
            'draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived',
            default => self::humanize($value, 'Unknown'),
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

    /**
     * A moment in company time, relative to the company day: "Today
     * 09:12", "Yesterday 18:40", "Mon 12 Oct 09:12", or in another year
     * "12 Oct 2025 09:12".
     */
    public static function companyTime(string $iso, string $companyDate, string $utcOffset): string
    {
        $at = CarbonImmutable::parse($iso)->setTimezone($utcOffset);
        $date = $at->toDateString();
        $today = CarbonImmutable::parse($companyDate);

        return match ($date) {
            $companyDate => 'Today '.$at->format('H:i'),
            $today->subDay()->toDateString() => 'Yesterday '.$at->format('H:i'),
            default => ($at->year === $today->year ? self::dayMonth($at) : self::date($date)).' '.$at->format('H:i'),
        };
    }

    /** "1 day", "3 days". */
    public static function days(int $n): string
    {
        return $n === 1 ? '1 day' : $n.' days';
    }

    /** "Tue 20 Oct 2026", or "Tue 20 Oct – Thu 22 Oct 2026". */
    public static function leaveDates(string $start, string $end): string
    {
        $s = CarbonImmutable::parse($start);
        $e = CarbonImmutable::parse($end);
        if ($start === $end) {
            return self::dayMonth($s).' '.$s->format('Y');
        }

        return $s->year === $e->year
            ? self::dayMonth($s).' – '.self::dayMonth($e).' '.$e->format('Y')
            : self::dayMonth($s).' '.$s->format('Y').' – '.self::dayMonth($e).' '.$e->format('Y');
    }

    /** "3 calendar days (weekends included)". */
    public static function calendarDays(int $days): string
    {
        return ($days === 1 ? '1 calendar day' : $days.' calendar days').' (weekends included)';
    }

    /**
     * A balance's headline: "10 days left of 15", or "Unpaid — no
     * balance".
     *
     * @param  array<string, mixed>  $balance
     */
    public static function balanceHeadline(array $balance): string
    {
        $remaining = $balance['remaining_days'] ?? null;
        $allocated = $balance['allocated_days'] ?? null;
        if (($balance['is_paid'] ?? false) !== true || $remaining === null || $allocated === null) {
            return 'Unpaid — no balance';
        }

        return self::days((int) $remaining).' left of '.$allocated;
    }

    public static function leaveStatus(?string $value): string
    {
        return match ($value) {
            'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled',
            default => 'Unknown',
        };
    }

    public static function leaveAction(?string $value): string
    {
        return match ($value) {
            'submitted' => 'Requested', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled',
            default => 'Updated',
        };
    }

    public static function operationalStatus(?string $value): string
    {
        return match ($value) {
            'available' => 'Available', 'busy' => 'Busy', 'in_meeting' => 'In a meeting',
            'in_field' => 'In the field', 'off_duty' => 'Off duty',
            default => 'Unknown status',
        };
    }

    /**
     * An inbox row's time (Phase 32 R-3), company time: "09:12" today,
     * "Yesterday", "Mon 12 Oct", or "12 Oct 2025" in another year.
     */
    public static function inboxTime(string $iso, string $companyDate, string $utcOffset): string
    {
        $at = CarbonImmutable::parse($iso)->setTimezone($utcOffset);
        $today = CarbonImmutable::parse($companyDate);

        return match (true) {
            $at->toDateString() === $companyDate => $at->format('H:i'),
            $at->toDateString() === $today->subDay()->toDateString() => 'Yesterday',
            $at->year === $today->year => self::dayMonth($at),
            default => (string) self::date($at->toDateString()),
        };
    }

    /** A chat's day separator (R-4): "Today", "Yesterday", "Mon 12 Oct", or "12 Oct 2025". */
    public static function chatDay(string $date, string $companyDate): string
    {
        $d = CarbonImmutable::parse($date);
        $today = CarbonImmutable::parse($companyDate);

        return match (true) {
            $date === $companyDate => 'Today',
            $date === $today->subDay()->toDateString() => 'Yesterday',
            $d->year === $today->year => self::dayMonth($d),
            default => (string) self::date($date),
        };
    }

    /** "12 Oct 2026 09:12" in company time (an announcement's dates, R-7). */
    public static function dateTime(string $iso, string $utcOffset): string
    {
        $at = CarbonImmutable::parse($iso)->setTimezone($utcOffset);

        return self::date($at->toDateString()).' '.$at->format('H:i');
    }

    /**
     * A conversation's name (R-3): the group name, the project name, or —
     * for a direct chat — everyone but me.
     *
     * @param  array<string, mixed>  $conversation
     */
    public static function conversationTitle(array $conversation, ?string $myStaffId): string
    {
        return match ($conversation['type'] ?? null) {
            'group' => $conversation['name'] ?? 'Group',
            'project' => $conversation['project']['name'] ?? $conversation['name'] ?? 'Project chat',
            default => (function () use ($conversation, $myStaffId): string {
                $others = collect($conversation['members'] ?? [])
                    ->pluck('staff')
                    ->reject(fn ($s) => ($s['public_id'] ?? null) === $myStaffId)
                    ->pluck('name');

                return $others->isEmpty() ? ($conversation['name'] ?? 'Conversation') : $others->implode(', ');
            })(),
        };
    }

    /**
     * Group and project chats name each sender (R-4).
     *
     * @param  array<string, mixed>  $conversation
     */
    public static function showsSenderNames(array $conversation): bool
    {
        return in_array($conversation['type'] ?? null, ['group', 'project'], true);
    }

    /**
     * The inbox row's preview (R-3): "You: …" for mine, "Grace: …" in a
     * group, the text alone in a direct chat; "No messages yet".
     *
     * @param  array<string, mixed>  $conversation
     */
    public static function lastMessagePreview(array $conversation, ?string $myStaffId): string
    {
        $m = $conversation['last_message'] ?? null;
        if ($m === null) {
            return 'No messages yet';
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $m['body']));
        if (($m['sender']['public_id'] ?? null) === $myStaffId) {
            return 'You: '.$text;
        }

        return self::showsSenderNames($conversation)
            ? explode(' ', (string) $m['sender']['name'])[0].': '.$text
            : $text;
    }

    /** "Wed 14 Oct 2026" for an API date (a service date, Phase 33). */
    public static function reportDate(?string $apiDate): ?string
    {
        return $apiDate === null || $apiDate === '' ? null : CarbonImmutable::parse($apiDate)->format('D j M Y');
    }

    /** "Wed 14 Oct 2026 09:30" in company time (an incident's time, a history step). */
    public static function reportTime(string $iso, string $utcOffset): string
    {
        return CarbonImmutable::parse($iso)->setTimezone($utcOffset)->format('D j M Y H:i');
    }

    /** "512 KB" or "2.4 MB", as on mobile. */
    public static function fileSize(int $bytes): string
    {
        return $bytes < 1024 * 1024
            ? ((int) ceil($bytes / 1024)).' KB'
            : number_format($bytes / (1024 * 1024), 1).' MB';
    }

    public static function serviceStatus(?string $value): string
    {
        return match ($value) {
            'draft' => 'Draft', 'submitted' => 'Submitted', 'reviewed' => 'Reviewed', 'rejected' => 'Rejected',
            default => 'Unknown',
        };
    }

    public static function incidentStatus(?string $value): string
    {
        return match ($value) {
            'reported' => 'Reported', 'under_investigation' => 'Under investigation', 'resolved' => 'Resolved', 'closed' => 'Closed',
            default => 'Unknown',
        };
    }

    public static function incidentType(?string $value): string
    {
        return match ($value) {
            'workplace_safety' => 'Workplace safety', 'client_site' => 'Client site', 'operational' => 'Operational',
            'property_equipment_damage' => 'Property or equipment damage', 'it_security' => 'IT security', 'other' => 'Other',
            default => 'Unknown type',
        };
    }

    public static function severity(?string $value): string
    {
        return match ($value) {
            'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical',
            default => 'Unknown',
        };
    }

    /** A report status's chip tone (Phase 33 R-4/R-6): reviewed, resolved and closed are good; rejected is bad. */
    public static function reportTone(?string $status): string
    {
        return match ($status) {
            'submitted', 'reported', 'under_investigation' => 'info',
            'reviewed', 'resolved', 'closed' => 'success',
            'rejected' => 'danger',
            default => 'neutral',
        };
    }

    /** High and Critical severities are shown as danger. */
    public static function severityTone(?string $severity): string
    {
        return in_array($severity, ['high', 'critical'], true) ? 'danger' : 'neutral';
    }

    /** An audit log action, readable: "role.permissions_updated" → "Role: permissions updated" (Phase 37B). */
    public static function auditAction(string $action): string
    {
        [$area, $event] = array_pad(explode('.', $action, 2), 2, '');

        return $event === '' ? self::humanize($area, $action) : self::humanize($area, $area).': '.str_replace('_', ' ', $event);
    }

    /** A history step's action: "return_to_draft" → "Return to draft". */
    public static function reportAction(?string $action): string
    {
        $words = str_replace('_', ' ', (string) $action);

        return $words === '' ? 'Updated' : ucfirst($words);
    }

    private static function humanize(?string $value, string $fallback): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return ucfirst(str_replace('_', ' ', $value));
    }
}
