<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Foundational authorization system data (Phase 5 — Roles & Permissions,
 * DEC-028): the V1 role catalog and the minimal permission catalog that
 * proves the authorization mechanism. Deliberately separate from
 * AdminUserSeeder — this is safe, deterministic system data intended to
 * run in every environment (local, testing, staging, production), never
 * gated behind an environment check and never containing credentials.
 *
 * Idempotent throughout (firstOrCreate/updateOrCreate matched on the
 * unique 'name' column) — safe to run repeatedly, and safe regardless of
 * whether the 'administrator' row was already created defensively by the
 * 2026_09_11_050003_add_role_id_to_users_table migration's is_admin
 * backfill.
 *
 * Administrator deliberately receives no explicit permission rows here —
 * it holds every ability via the centralized Gate::before override in
 * App\Providers\AppServiceProvider::boot(), not by attaching every
 * permission to it (see DEC-028). Manager and Staff intentionally start
 * with no permissions in V1 (CLAUDE.md §10) — later phases attach real
 * permissions as their modules are built.
 *
 * Phase 6 (Organization Structure) adds `organization.view` /
 * `organization.manage` and attaches `organization.view` to Manager and
 * Staff — viewing the company's Department/Team/Position structure is
 * low-sensitivity, broadly useful company metadata, unlike
 * `organization.manage` (create/update/delete), which remains
 * Administrator-only via the centralized override, same as every other
 * `*.manage` permission so far.
 *
 * Phase 7 (Staff) adds `staff.view` / `staff.manage` following the exact
 * same pattern: the Staff Directory is a company-wide feature, so
 * `staff.view` is attached to Manager and Staff; `staff.manage`
 * (create/update/delete/status changes) remains Administrator-only.
 *
 * Phase 8 (Clients & Contacts) adds `clients.view` / `clients.manage`,
 * again the same pattern: the Client/Contact directory is company-wide,
 * so `clients.view` is attached to Manager and Staff; `clients.manage`
 * remains Administrator-only. Contacts share these permissions — there is
 * no separate `contacts.*` pair (see DEC-031).
 *
 * Phase 9 (Staff Status & Location Check-in) adds four permissions
 * (DEC-032): `staff-status.view` (Manager and Staff — an operational
 * status word is low-sensitivity, company-wide information, same pattern
 * as staff.view) and `staff-status.manage` (Administrator-only — setting/
 * correcting another staff member's status on their behalf). Location is
 * materially more sensitive: `location.view` is attached to Manager
 * *only* (not Staff), and is further scoped in CheckInController to a
 * Manager's own direct reports — never company-wide precise-location
 * visibility for Manager/Staff. `location.manage` (deleting/correcting a
 * historical check-in) remains Administrator-only.
 *
 * Phase 10 (Projects & Project Membership) adds `projects.view` /
 * `projects.manage`. Unlike every `*.view` permission before it,
 * `projects.view` is attached to **Manager only** (not Staff) — Projects
 * are membership-scoped business information, not company-wide directory
 * data like Staff/Clients/Organization; an ordinary Staff member instead
 * sees only Projects where they hold a Project Membership, enforced
 * in-controller (ProjectController/ProjectMembershipController), not via
 * this permission. `projects.manage` (Project CRUD and all Project
 * Membership writes) remains Administrator-only, same pattern as every
 * other `*.manage` permission.
 *
 * Phase 11 (Tasks) adds `tasks.view` / `tasks.manage`, following
 * `projects.view`'s precedent exactly: `tasks.view` is attached to
 * **Manager only** (not Staff) — Tasks may carry the same
 * client-engagement sensitivity as their Project. `tasks.manage`
 * (Administrator-only) covers unrestricted Task CRUD; a Project Lead's
 * Task-management authority (scoped to their own Project) and a Task
 * assignee's status-only self-service are both enforced in-controller
 * (TaskController's AuthorizesTaskAccess), not via a permission — no
 * `tasks.assign`/`tasks.complete`/`tasks.delete` granular permissions
 * were introduced.
 *
 * Phase 12 (Work Logs) adds `work-logs.view` / `work-logs.manage`.
 * Unlike `tasks.view`/`projects.view`, `work-logs.view` is attached to
 * **Manager only** and is further scoped in WorkLogController to the
 * Manager's own direct reports (Staff.manager_id) — mirroring
 * `location.view`'s Phase 9 precedent, not a company-wide grant, since
 * logged work duration/timing is treated as materially more sensitive
 * than the Staff/Task/Project directories. `work-logs.manage`
 * (Administrator-only) covers create-for-others/edit-any/delete-any; a
 * Project Lead's read-only visibility into their led Projects' Work Logs
 * is a row-level, in-controller check (AuthorizesWorkLogVisibility), not
 * a permission — no Project Lead or Manager write authority exists in
 * this module at all (deliberately stricter than Tasks). Self-service
 * (`/me/work-logs`) needs no permission, mirroring Phase 9's domain-check
 * pattern.
 *
 * Phase 13 (Leave Management) adds four permissions. `leave-types.view`
 * follows `organization.view`/`staff.view`'s company-wide pattern
 * (Manager and Staff both — a Staff member must browse active Leave
 * Types to submit a request); `leave-types.manage` is Administrator-only.
 * `leave-requests.view` follows `work-logs.view`'s Phase 12 pattern
 * exactly: **Manager only** (not Staff — leave reasons/dates are
 * materially more sensitive than a company directory), further scoped
 * in LeaveRequestController/LeaveBalanceController to the Manager's own
 * direct reports, and also the permission checked (alongside the
 * manager-of-record relationship) for approve/reject authority.
 * `leave-requests.manage` is Administrator-only (create a request on a
 * Staff member's behalf; Administrator-cancel). Self-service
 * (`/me/leave-requests`, `/me/leave-balances`) needs no permission,
 * mirroring Phase 9/12's domain-check pattern.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        Role::query()->firstOrCreate(
            ['name' => Role::ADMINISTRATOR],
            ['label' => 'Administrator'],
        );

        $manager = Role::query()->firstOrCreate(
            ['name' => Role::MANAGER],
            ['label' => 'Manager'],
        );

        $staff = Role::query()->firstOrCreate(
            ['name' => Role::STAFF],
            ['label' => 'Staff'],
        );

        Permission::query()->firstOrCreate(
            ['name' => 'admin.access'],
            ['label' => 'Access the Admin Backoffice'],
        );

        Permission::query()->firstOrCreate(
            ['name' => 'authorization.manage'],
            ['label' => "Manage users' roles and permissions"],
        );

        $organizationView = Permission::query()->firstOrCreate(
            ['name' => 'organization.view'],
            ['label' => 'View departments, teams, and positions'],
        );

        Permission::query()->firstOrCreate(
            ['name' => 'organization.manage'],
            ['label' => 'Create, update, and delete departments, teams, and positions'],
        );

        $staffView = Permission::query()->firstOrCreate(
            ['name' => 'staff.view'],
            ['label' => 'View the staff directory'],
        );

        Permission::query()->firstOrCreate(
            ['name' => 'staff.manage'],
            ['label' => 'Create, update, and delete staff records'],
        );

        $clientsView = Permission::query()->firstOrCreate(
            ['name' => 'clients.view'],
            ['label' => 'View clients and contacts'],
        );

        Permission::query()->firstOrCreate(
            ['name' => 'clients.manage'],
            ['label' => 'Create, update, and delete clients and contacts'],
        );

        $staffStatusView = Permission::query()->firstOrCreate(
            ['name' => 'staff-status.view'],
            ['label' => "View staff members' operational status"],
        );

        Permission::query()->firstOrCreate(
            ['name' => 'staff-status.manage'],
            ['label' => "Set or correct another staff member's operational status"],
        );

        $locationView = Permission::query()->firstOrCreate(
            ['name' => 'location.view'],
            ['label' => "View staff members' location check-ins"],
        );

        Permission::query()->firstOrCreate(
            ['name' => 'location.manage'],
            ['label' => 'Delete or correct a historical location check-in'],
        );

        $projectsView = Permission::query()->firstOrCreate(
            ['name' => 'projects.view'],
            ['label' => 'View all projects'],
        );

        Permission::query()->firstOrCreate(
            ['name' => 'projects.manage'],
            ['label' => 'Create, update, and delete projects and project memberships'],
        );

        $tasksView = Permission::query()->firstOrCreate(
            ['name' => 'tasks.view'],
            ['label' => 'View all tasks'],
        );

        Permission::query()->firstOrCreate(
            ['name' => 'tasks.manage'],
            ['label' => 'Create, update, and delete any task'],
        );

        $workLogsView = Permission::query()->firstOrCreate(
            ['name' => 'work-logs.view'],
            ['label' => "View direct reports' work logs"],
        );

        Permission::query()->firstOrCreate(
            ['name' => 'work-logs.manage'],
            ['label' => 'Create, correct, and delete any work log'],
        );

        $leaveTypesView = Permission::query()->firstOrCreate(
            ['name' => 'leave-types.view'],
            ['label' => 'View leave types'],
        );

        Permission::query()->firstOrCreate(
            ['name' => 'leave-types.manage'],
            ['label' => 'Create, update, and delete leave types'],
        );

        $leaveRequestsView = Permission::query()->firstOrCreate(
            ['name' => 'leave-requests.view'],
            ['label' => "View direct reports' leave requests and balances"],
        );

        Permission::query()->firstOrCreate(
            ['name' => 'leave-requests.manage'],
            ['label' => 'Create a leave request on behalf of a staff member, cancel any request, and manage leave balances'],
        );

        $manager->permissions()->syncWithoutDetaching([
            $organizationView->id, $staffView->id, $clientsView->id, $staffStatusView->id, $locationView->id, $projectsView->id, $tasksView->id, $workLogsView->id, $leaveTypesView->id, $leaveRequestsView->id,
        ]);
        $staff->permissions()->syncWithoutDetaching([
            $organizationView->id, $staffView->id, $clientsView->id, $staffStatusView->id, $leaveTypesView->id,
        ]);

        $this->command?->info('Role/permission catalog ready (Administrator, Manager, Staff).');
    }
}
