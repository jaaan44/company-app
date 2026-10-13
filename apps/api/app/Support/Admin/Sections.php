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
     * key => [title, icon, permissions (any of), summary, items: list<[label, description, route|null, coming|null]>]
     *
     * @var array<string, array{title: string, icon: string, permissions: list<string>, summary: string, items: list<array{0: string, 1: string, 2: ?string, 3: ?string}>}>
     */
    public const ALL = [
        'people' => [
            'title' => 'People',
            'icon' => 'directory',
            'permissions' => ['staff.view', 'staff.create', 'staff.update', 'staff.delete', 'organization.view', 'organization.manage', 'staff-status.manage', 'location.view', 'location.manage'],
            'summary' => 'Staff records and how the company is organised.',
            'items' => [
                ['Staff records', 'Add, edit and remove staff, and link their sign-in accounts.', null, 'Phase 36'],
                ['Departments, teams and positions', 'The company structure.', null, 'Phase 36'],
                ['Staff status', 'Correct a staff member\'s status.', null, 'Phase 36'],
                ['Location check-ins', 'Review and correct check-ins.', null, 'Phase 36'],
            ],
        ],
        'clients' => [
            'title' => 'Clients',
            'icon' => 'clients',
            'permissions' => ['clients.view', 'clients.create', 'clients.update', 'clients.delete'],
            'summary' => 'The company\'s clients and their contacts.',
            'items' => [
                ['Clients and contacts', 'Add, edit and remove clients and their contacts.', null, 'Phase 36'],
            ],
        ],
        'work' => [
            'title' => 'Work',
            'icon' => 'tasks',
            'permissions' => ['projects.view', 'projects.create', 'projects.update', 'projects.delete', 'projects.members', 'tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete', 'work-logs.view', 'work-logs.manage'],
            'summary' => 'Projects, tasks, logged work and the schedule.',
            'items' => [
                ['Projects', 'Projects, their members and milestones.', null, 'Phase 36'],
                ['Tasks', 'Add, assign, edit and remove tasks.', null, 'Phase 36'],
                ['Work logs', 'Review and correct logged work.', null, 'Phase 36'],
                ['Schedule', 'Everyone\'s schedule entries.', null, 'Phase 36'],
            ],
        ],
        'hr' => [
            'title' => 'HR',
            'icon' => 'leave',
            'permissions' => ['leave-types.view', 'leave-types.manage', 'leave-requests.view', 'leave-requests.manage'],
            'summary' => 'Leave requests, leave types and balances.',
            'items' => [
                ['Leave requests', 'All requests, and requests on someone\'s behalf.', null, 'Phase 36'],
                ['Leave types', 'The kinds of leave and their rules.', null, 'Phase 36'],
                ['Leave balances', 'Each person\'s allowance and what is used.', null, 'Phase 36'],
            ],
        ],
        'operations' => [
            'title' => 'Operations',
            'icon' => 'reports',
            'permissions' => ['admin.access'],
            'summary' => 'Service reports and incidents.',
            'items' => [
                ['Service reports', 'Review, approve and reject service reports.', null, 'Phase 36'],
                ['Incidents', 'Assign, investigate, resolve and close incidents.', null, 'Phase 36'],
            ],
        ],
        'communication' => [
            'title' => 'Communication',
            'icon' => 'announcements',
            'permissions' => ['announcements.view-all', 'announcements.create', 'announcements.publish', 'announcements.delete'],
            'summary' => 'Company announcements.',
            'items' => [
                ['Announcements', 'Write, publish, archive and remove announcements.', null, 'Phase 36'],
            ],
        ],
        'reports' => [
            'title' => 'Reports',
            'icon' => 'chart',
            'permissions' => ['admin.access'],
            'summary' => 'Reports with CSV export.',
            'items' => [
                ['Staff', 'The staff directory report.', null, 'Phase 36'],
                ['Projects and tasks', 'Project and task reports.', null, 'Phase 36'],
                ['Work logs', 'Time logged, by person and project.', null, 'Phase 36'],
                ['Leave', 'Leave taken and requested.', null, 'Phase 36'],
                ['Service reports and incidents', 'Operations reports.', null, 'Phase 36'],
            ],
        ],
        'administration' => [
            'title' => 'Administration',
            'icon' => 'admin',
            'permissions' => ['authorization.manage'],
            'summary' => 'Who can do what, and a record of changes.',
            'items' => [
                ['Roles & permissions', 'Choose what Managers and Staff can view, add, edit and delete.', 'admin.roles', null],
                ['Users and roles', 'Change which role a person\'s account has.', 'admin.users', null],
                ['Audit log', 'Every recorded change: who, what and when.', 'admin.audit-log', null],
            ],
        ],
    ];

    /**
     * The sections this user may see, in order.
     *
     * @return array<string, array{title: string, icon: string, permissions: list<string>, summary: string, items: list<array{0: string, 1: string, 2: ?string, 3: ?string}>}>
     */
    public static function visibleTo(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_filter(self::ALL, fn (array $section) => collect($section['permissions'])->contains(fn (string $permission) => $user->can($permission)));
    }
}
