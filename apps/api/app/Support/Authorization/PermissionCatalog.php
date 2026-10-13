<?php

namespace App\Support\Authorization;

use App\Models\Role;

/**
 * The permission catalog as the Roles & permissions screen shows it
 * (Phase 35C, DEC-065): which permissions belong to which module, which
 * one is each module's "view" (every write needs it, P-3), what is
 * locked to Administrator, which roles can be edited, and the shipped
 * defaults ("Reset to defaults", equal to RolePermissionSeeder's grants).
 *
 * The labels themselves live on the `permissions` rows. Every permission
 * the seeder creates must appear here exactly once (pinned by a test), so
 * a new permission can't be added without deciding where it is shown.
 */
final class PermissionCatalog
{
    /** Never tickable for another role: entering the Backoffice, and this screen itself. */
    public const LOCKED = ['admin.access', 'authorization.manage'];

    /** The roles whose permissions an Administrator may change. Administrator always has everything. */
    public const EDITABLE_ROLES = [Role::MANAGER, Role::STAFF];

    /**
     * Module title => [view permission, the other permissions, a note on rules that aren't permissions].
     *
     * @var array<string, array{view: string, writes: list<string>, note: ?string}>
     */
    public const MODULES = [
        'Organization' => [
            'view' => 'organization.view',
            'writes' => ['organization.manage'],
            'note' => null,
        ],
        'Staff' => [
            'view' => 'staff.view',
            'writes' => ['staff.create', 'staff.update', 'staff.delete'],
            'note' => null,
        ],
        'Staff status' => [
            'view' => 'staff-status.view',
            'writes' => ['staff-status.manage'],
            'note' => 'Everyone can always set their own status.',
        ],
        'Location check-ins' => [
            'view' => 'location.view',
            'writes' => ['location.manage'],
            'note' => "View shows only a person's direct reports. Only Administrators see everyone's check-ins.",
        ],
        'Clients' => [
            'view' => 'clients.view',
            'writes' => ['clients.create', 'clients.update', 'clients.delete'],
            'note' => null,
        ],
        'Projects' => [
            'view' => 'projects.view',
            'writes' => ['projects.create', 'projects.update', 'projects.delete', 'projects.members'],
            'note' => 'Everyone always sees the projects they are a member of. A project lead can always manage their project\'s milestones.',
        ],
        'Tasks' => [
            'view' => 'tasks.view',
            'writes' => ['tasks.create', 'tasks.update', 'tasks.delete'],
            'note' => 'A project lead can always add and edit their project\'s tasks. An assignee can always change their task\'s status.',
        ],
        'Work logs' => [
            'view' => 'work-logs.view',
            'writes' => ['work-logs.manage'],
            'note' => "View shows only a person's direct reports. A project lead sees their project's logs. Only Administrators see everyone's. Everyone manages their own logs.",
        ],
        'Leave types' => [
            'view' => 'leave-types.view',
            'writes' => ['leave-types.manage'],
            'note' => null,
        ],
        'Leave requests' => [
            'view' => 'leave-requests.view',
            'writes' => ['leave-requests.manage'],
            'note' => "View shows, and lets a manager decide, only a person's direct reports' requests. Only Administrators see everyone's. Everyone manages their own requests.",
        ],
        'Announcements' => [
            'view' => 'announcements.view-all',
            'writes' => ['announcements.create', 'announcements.publish', 'announcements.delete'],
            'note' => 'Everyone always sees the announcements addressed to them.',
        ],
    ];

    /** Shown once on the screen: rules that stay Administrator-only (P-4). */
    public const ADMINISTRATOR_ONLY = 'Only Administrators can see every schedule entry, every service and incident report, and the audit log.';

    /**
     * The shipped grants, as RolePermissionSeeder attaches them.
     *
     * @var array<string, list<string>>
     */
    public const DEFAULTS = [
        Role::MANAGER => [
            'organization.view', 'staff.view', 'clients.view', 'staff-status.view', 'location.view',
            'projects.view', 'tasks.view', 'work-logs.view', 'leave-types.view', 'leave-requests.view',
        ],
        Role::STAFF => [
            'organization.view', 'staff.view', 'clients.view', 'staff-status.view', 'leave-types.view',
        ],
    ];

    /**
     * Every permission an editable role may hold.
     *
     * @return list<string>
     */
    public static function grantable(): array
    {
        $names = [];
        foreach (self::MODULES as $module) {
            $names[] = $module['view'];
            array_push($names, ...$module['writes']);
        }

        return $names;
    }

    /**
     * The view permission a write needs, or null for a view (or an unknown name).
     */
    public static function requiredView(string $permission): ?string
    {
        foreach (self::MODULES as $module) {
            if (in_array($permission, $module['writes'], true)) {
                return $module['view'];
            }
        }

        return null;
    }

    /**
     * The writes that depend on a view permission.
     *
     * @return list<string>
     */
    public static function dependents(string $view): array
    {
        foreach (self::MODULES as $module) {
            if ($module['view'] === $view) {
                return $module['writes'];
            }
        }

        return [];
    }

    /**
     * Keeps a set of grants consistent after one change (the screen's ticking rule):
     * a newly added write brings its view; a removed view takes its writes.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<string>
     */
    public static function normalize(array $before, array $after): array
    {
        $added = array_diff($after, $before);
        $removed = array_diff($before, $after);
        $result = $after;

        foreach ($removed as $name) {
            $result = array_diff($result, self::dependents($name));
        }
        foreach ($added as $name) {
            $view = self::requiredView($name);
            if ($view !== null && ! in_array($view, $result, true)) {
                $result[] = $view;
            }
        }

        return array_values(array_intersect(self::grantable(), array_unique($result)));
    }
}
