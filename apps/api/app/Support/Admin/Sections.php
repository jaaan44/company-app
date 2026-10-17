<?php

namespace App\Support\Admin;

use App\Models\User;

/**
 * The Admin Backoffice's sections (Phase 35B, DEC-065), in the order of
 * 06_UI_UX_GUIDELINES.md: one list for the navigation and the section
 * pages. A section shows only to someone holding one of its permissions
 * (06: permission-aware navigation). An item with a `route` is a live
 * screen; one without says which phase brings it.
 */
final class Sections
{
    /**
     * key => [title, icon, permissions (any of), summary, items: list<[label, description, route name|[route name, params]|null, coming|null]>]
     *
     * @var array<string, array{title: string, icon: string, permissions: list<string>, summary: string, items: list<array{0: string, 1: string, 2: string|array{0: string, 1: array<string, string>}|null, 3: ?string}>}>
     */
    public const ALL = [
        'people' => [
            'title' => 'People',
            'icon' => 'directory',
            'permissions' => ['staff.view', 'staff.create', 'staff.update', 'staff.delete', 'organization.view', 'organization.manage', 'staff-status.manage', 'location.view', 'location.manage'],
            'summary' => 'Staff records and how the company is organised.',
            'items' => [
                // Phase 37B: status and check-ins live on the staff record, so they are part of this item, not two more links to it.
                ['Staff records', 'Add, edit and remove staff, give them sign-in accounts, correct their status and review their check-ins.', 'admin.staff', null],
                ['Departments, teams and positions', 'The company structure.', 'admin.organization', null],
            ],
        ],
        'clients' => [
            'title' => 'Clients',
            'icon' => 'clients',
            'permissions' => ['clients.view', 'clients.create', 'clients.update', 'clients.delete'],
            'summary' => 'The company\'s clients and their contacts.',
            'items' => [
                ['Clients and contacts', 'Add, edit and remove clients and their contacts.', 'portal.clients', null],
            ],
        ],
        'work' => [
            'title' => 'Work',
            'icon' => 'tasks',
            'permissions' => ['projects.view', 'projects.create', 'projects.update', 'projects.delete', 'projects.members', 'tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete', 'work-logs.view', 'work-logs.manage'],
            'summary' => 'Projects, tasks, logged work and the schedule.',
            'items' => [
                ['Projects', 'Projects, their members and milestones.', ['portal.projects', ['scope' => 'all']], null],
                ['Tasks', 'Add, assign, edit and remove tasks.', ['portal.tasks', ['scope' => 'all']], null],
                ['Work logs', 'Review, correct and remove logged work.', 'admin.work-logs', null],
                ['Schedule', 'Everyone\'s schedule entries.', 'admin.schedule', null],
            ],
        ],
        'hr' => [
            'title' => 'HR',
            'icon' => 'leave',
            'permissions' => ['leave-types.view', 'leave-types.manage', 'leave-requests.view', 'leave-requests.manage'],
            'summary' => 'Leave requests, leave types and balances.',
            'items' => [
                ['Leave requests', 'All requests, and requests on someone\'s behalf.', 'admin.leave-requests', null],
                ['Leave types', 'The kinds of leave, paid or unpaid.', 'admin.leave-types', null],
                ['Leave balances', 'Each person\'s allowance and what is used.', 'admin.leave-balances', null],
            ],
        ],
        'operations' => [
            'title' => 'Operations',
            'icon' => 'reports',
            'permissions' => ['admin.access'],
            'summary' => 'Service reports and incidents.',
            'items' => [
                ['Service reports', 'Review and reject submitted service reports.', 'admin.service-reports', null],
                ['Incidents', 'Assign, investigate, resolve and close incidents.', 'admin.incidents', null],
            ],
        ],
        'communication' => [
            'title' => 'Communication',
            'icon' => 'announcements',
            'permissions' => ['announcements.view-all', 'announcements.create', 'announcements.publish', 'announcements.delete'],
            'summary' => 'Company announcements.',
            'items' => [
                ['Announcements', 'Write, publish, archive and remove announcements.', 'portal.announcements.manage', null],
            ],
        ],
        'reports' => [
            'title' => 'Reports',
            'icon' => 'chart',
            'permissions' => ['admin.access'],
            'summary' => 'Reports with CSV export.',
            'items' => [
                ['Staff', 'The staff directory report.', ['admin.reports.show', ['report' => 'staff']], null],
                ['Projects', 'Projects by status and client.', ['admin.reports.show', ['report' => 'projects']], null],
                ['Tasks', 'Tasks by status, priority and overdue.', ['admin.reports.show', ['report' => 'tasks']], null],
                ['Work logs', 'Time logged, by person and project.', ['admin.reports.show', ['report' => 'work-logs']], null],
                ['Leave', 'Leave taken and requested.', ['admin.reports.show', ['report' => 'leave']], null],
                ['Service reports', 'Service reports by status and date.', ['admin.reports.show', ['report' => 'service-reports']], null],
                ['Incidents', 'Incidents by status, severity and date.', ['admin.reports.show', ['report' => 'incidents']], null],
            ],
        ],
        'administration' => [
            'title' => 'Administration',
            'icon' => 'admin',
            'permissions' => ['authorization.manage'],
            'summary' => 'Who can do what, and a record of changes.',
            'items' => [
                ['Roles & permissions', 'Choose what Managers and Staff can view, add, edit and delete.', 'admin.roles', null],
                ['Users and roles', 'Create sign-in accounts, change roles, reset passwords and suspend accounts.', 'admin.users', null],
                ['Audit log', 'Every recorded change: who, what and when.', 'admin.audit-log', null],
            ],
        ],
    ];

    /**
     * The sections this user may see, in order.
     *
     * @return array<string, array{title: string, icon: string, permissions: list<string>, summary: string, items: list<array{0: string, 1: string, 2: string|array{0: string, 1: array<string, string>}|null, 3: ?string}>}>
     */
    public static function visibleTo(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_filter(self::ALL, fn (array $section) => collect($section['permissions'])->contains(fn (string $permission) => $user->can($permission)));
    }
}
