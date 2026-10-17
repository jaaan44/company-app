<?php

namespace App\Support\Admin;

use App\Support\Portal\Format;

/**
 * The Backoffice reports (Phase 36D): the seven `/api/v1/reports/*`
 * endpoints (Phase 20), each with the filters this screen offers and the
 * columns it shows. The API decides what rows a person may see, writes
 * the CSV, and audits every export (`report.exported`).
 */
final class Reports
{
    private function __construct() {}

    /** Status options shared by several reports. */
    private const LEAVE_STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    /**
     * key => [title, API path, filters (name => options|'date'|'bool'), columns (label => fn(row): ?string),
     * sorts (column label => the API's `sort` key; Phase 37B)]
     *
     * @return array<string, array{title: string, path: string, filters: array<string, array<string, string>|string>, columns: array<string, callable(array<string, mixed>, string): ?string>, sorts: array<string, string>}>
     */
    public static function all(): array
    {
        return [
            'staff' => [
                'title' => 'Staff',
                'path' => '/reports/staff',
                'sorts' => ['Name' => 'name', 'Employee no.' => 'employee_number'],
                'filters' => ['status' => ['active' => 'Active', 'inactive' => 'Inactive', 'separated' => 'Separated']],
                'columns' => [
                    'Name' => fn (array $r) => $r['display_name'],
                    'Employee no.' => fn (array $r) => $r['employee_number'],
                    'Department' => fn (array $r) => $r['department']['name'] ?? null,
                    'Position' => fn (array $r) => $r['position']['title'] ?? null,
                    'Status' => fn (array $r) => Format::staffStatus($r['status']),
                ],
            ],
            'projects' => [
                'title' => 'Projects',
                'path' => '/reports/projects',
                'sorts' => ['Project' => 'name', 'Target end' => 'target_end_date'],
                'filters' => ['status' => ['planned' => 'Planned', 'active' => 'Active', 'on_hold' => 'On hold', 'completed' => 'Completed', 'cancelled' => 'Cancelled']],
                'columns' => [
                    'Project' => fn (array $r) => $r['name'],
                    'Client' => fn (array $r) => $r['client']['name'] ?? null,
                    'Status' => fn (array $r) => Format::projectStatus($r['status']),
                    'Members' => fn (array $r) => isset($r['members_count']) ? (string) $r['members_count'] : null,
                    'Target end' => fn (array $r) => Format::date($r['target_end_date'] ?? null),
                ],
            ],
            'tasks' => [
                'title' => 'Tasks',
                'path' => '/reports/tasks',
                'sorts' => ['Task' => 'title', 'Due' => 'due_date'],
                'filters' => [
                    'status' => ['todo' => 'To do', 'in_progress' => 'In progress', 'blocked' => 'Blocked', 'completed' => 'Completed', 'cancelled' => 'Cancelled'],
                    'priority' => ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'],
                    'overdue' => 'bool',
                ],
                'columns' => [
                    'Task' => fn (array $r) => $r['title'],
                    'Project' => fn (array $r) => $r['project']['name'] ?? null,
                    'Assigned to' => fn (array $r) => $r['assignee']['display_name'] ?? null,
                    'Status' => fn (array $r) => Format::taskStatus($r['status']),
                    'Priority' => fn (array $r) => Format::priority($r['priority']),
                    'Due' => fn (array $r) => Format::date($r['due_date'] ?? null),
                ],
            ],
            'work-logs' => [
                'title' => 'Work logs',
                'path' => '/reports/work-logs',
                'sorts' => ['Date' => 'work_date', 'Time' => 'duration_minutes'],
                'filters' => ['from' => 'date', 'to' => 'date'],
                'columns' => [
                    'Date' => fn (array $r) => Format::date($r['work_date']),
                    'Person' => fn (array $r) => $r['staff']['display_name'] ?? null,
                    'Project' => fn (array $r) => $r['project']['name'] ?? null,
                    'Task' => fn (array $r) => $r['task']['title'] ?? null,
                    'Time' => fn (array $r) => Format::duration((int) $r['duration_minutes']),
                ],
            ],
            'leave' => [
                'title' => 'Leave',
                'path' => '/reports/leave-requests',
                'sorts' => ['Dates' => 'start_date', 'Days' => 'total_days'],
                'filters' => ['from' => 'date', 'to' => 'date', 'status' => self::LEAVE_STATUSES],
                'columns' => [
                    'Person' => fn (array $r) => $r['staff']['display_name'] ?? null,
                    'Type' => fn (array $r) => $r['leave_type']['name'] ?? null,
                    'Dates' => fn (array $r) => Format::leaveDates($r['start_date'], $r['end_date']),
                    'Days' => fn (array $r) => (string) $r['total_days'],
                    'Status' => fn (array $r) => Format::leaveStatus($r['status']),
                ],
            ],
            'service-reports' => [
                'title' => 'Service reports',
                'path' => '/reports/service-reports',
                'sorts' => ['Date' => 'service_date'],
                'filters' => ['from' => 'date', 'to' => 'date', 'status' => ['draft' => 'Draft', 'submitted' => 'Submitted', 'reviewed' => 'Reviewed', 'rejected' => 'Rejected']],
                'columns' => [
                    'Date' => fn (array $r) => Format::date($r['service_date']),
                    'Client' => fn (array $r) => $r['client']['name'] ?? null,
                    'Written by' => fn (array $r) => $r['creator']['display_name'] ?? null,
                    'Status' => fn (array $r) => Format::serviceStatus($r['status']),
                ],
            ],
            'incidents' => [
                'title' => 'Incidents',
                'path' => '/reports/incident-reports',
                'sorts' => ['When' => 'occurred_at'],
                'filters' => [
                    'from' => 'date', 'to' => 'date',
                    'status' => ['reported' => 'Reported', 'under_investigation' => 'Under investigation', 'resolved' => 'Resolved', 'closed' => 'Closed'],
                    'severity' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'],
                ],
                'columns' => [
                    'When' => fn (array $r, string $tz) => Format::dateTime($r['occurred_at'], $tz),
                    'Type' => fn (array $r) => Format::incidentType($r['incident_type']),
                    'Severity' => fn (array $r) => Format::severity($r['severity']),
                    'Reported by' => fn (array $r) => $r['reporter']['display_name'] ?? null,
                    'Status' => fn (array $r) => Format::incidentStatus($r['status']),
                ],
            ],
        ];
    }

    /**
     * The query the API accepts for a report, from raw filter values:
     * unknown or malformed values are dropped.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function query(string $key, array $values): array
    {
        $query = [];
        foreach (self::all()[$key]['filters'] ?? [] as $name => $options) {
            $value = $values[$name] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }
            $ok = match (true) {
                $options === 'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
                $options === 'bool' => $value === '1',
                default => array_key_exists($value, $options),
            };
            if ($ok) {
                $query[$name] = $value;
            }
        }

        // Phase 37B: a column the report lets you sort by, ascending or ("-") descending.
        $sort = $values['sort'] ?? null;
        if (is_string($sort) && in_array(ltrim($sort, '-'), self::all()[$key]['sorts'] ?? [], true)) {
            $query['sort'] = $sort;
        }

        return $query;
    }
}
