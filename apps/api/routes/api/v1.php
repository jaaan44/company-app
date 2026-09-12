<?php

use App\Http\Controllers\Api\V1\Announcements\AnnouncementController;
use App\Http\Controllers\Api\V1\Announcements\MyAnnouncementController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Clients\ClientController;
use App\Http\Controllers\Api\V1\Clients\ContactController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Leave\LeaveBalanceController;
use App\Http\Controllers\Api\V1\Leave\LeaveRequestController;
use App\Http\Controllers\Api\V1\Leave\LeaveTypeController;
use App\Http\Controllers\Api\V1\Leave\MyLeaveRequestController;
use App\Http\Controllers\Api\V1\Messaging\ConversationController;
use App\Http\Controllers\Api\V1\Messaging\ConversationMemberController;
use App\Http\Controllers\Api\V1\Messaging\MessageController;
use App\Http\Controllers\Api\V1\Messaging\ProjectConversationController;
use App\Http\Controllers\Api\V1\Notifications\NotificationController;
use App\Http\Controllers\Api\V1\Organization\DepartmentController;
use App\Http\Controllers\Api\V1\Organization\PositionController;
use App\Http\Controllers\Api\V1\Organization\TeamController;
use App\Http\Controllers\Api\V1\Projects\ProjectController;
use App\Http\Controllers\Api\V1\Projects\ProjectMembershipController;
use App\Http\Controllers\Api\V1\Staff\StaffController;
use App\Http\Controllers\Api\V1\StaffOperations\CheckInController;
use App\Http\Controllers\Api\V1\StaffOperations\OperationalStatusController;
use App\Http\Controllers\Api\V1\Tasks\TaskController;
use App\Http\Controllers\Api\V1\WorkLogs\MyWorkLogController;
use App\Http\Controllers\Api\V1\WorkLogs\WorkLogController;
use Illuminate\Support\Facades\Route;

// v1 API routes. A future breaking version adds routes/api/v2.php and a
// parallel Route::prefix('v2') group in routes/api.php — this file is
// never duplicated or reused across versions.

Route::get('health', HealthController::class)->name('health');

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('me', [AuthController::class, 'me'])
            ->middleware('account.active')
            ->name('me');
    });
});

// Organization Structure (Phase 6): Departments, Teams, Positions. Reads
// require 'organization.view' (granted to Administrator/Manager/Staff);
// writes require 'organization.manage' (Administrator only, via the
// centralized Gate::before override — DEC-028). Route-model-bound by
// public_id (DEC-017), never the internal numeric id.
Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
    Route::middleware('can:organization.view')->group(function (): void {
        Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::get('departments/{department:public_id}', [DepartmentController::class, 'show'])->name('departments.show');

        Route::get('teams', [TeamController::class, 'index'])->name('teams.index');
        Route::get('teams/{team:public_id}', [TeamController::class, 'show'])->name('teams.show');

        Route::get('positions', [PositionController::class, 'index'])->name('positions.index');
        Route::get('positions/{position:public_id}', [PositionController::class, 'show'])->name('positions.show');
    });

    Route::middleware('can:organization.manage')->group(function (): void {
        Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::match(['put', 'patch'], 'departments/{department:public_id}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::delete('departments/{department:public_id}', [DepartmentController::class, 'destroy'])->name('departments.destroy');

        Route::post('teams', [TeamController::class, 'store'])->name('teams.store');
        Route::match(['put', 'patch'], 'teams/{team:public_id}', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('teams/{team:public_id}', [TeamController::class, 'destroy'])->name('teams.destroy');

        Route::post('positions', [PositionController::class, 'store'])->name('positions.store');
        Route::match(['put', 'patch'], 'positions/{position:public_id}', [PositionController::class, 'update'])->name('positions.update');
        Route::delete('positions/{position:public_id}', [PositionController::class, 'destroy'])->name('positions.destroy');
    });
});

// Staff (Phase 7): the company personnel directory. Reads require
// 'staff.view' (granted to Administrator/Manager/Staff); writes require
// 'staff.manage' (Administrator only, via the centralized Gate::before
// override — DEC-028). Route-model-bound by public_id (DEC-017), never
// the internal numeric id. Status changes (including offboarding) go
// through the same update endpoint as Organization Structure's own
// status field — no separate action route.
Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
    Route::middleware('can:staff.view')->group(function (): void {
        Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
        Route::get('staff/{staff:public_id}', [StaffController::class, 'show'])->name('staff.show');
    });

    Route::middleware('can:staff.manage')->group(function (): void {
        Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
        Route::match(['put', 'patch'], 'staff/{staff:public_id}', [StaffController::class, 'update'])->name('staff.update');
        Route::delete('staff/{staff:public_id}', [StaffController::class, 'destroy'])->name('staff.destroy');
    });
});

// Clients & Contacts (Phase 8): the customer-data foundation later
// modules (Projects, Tasks, Work Logs, Messaging, reporting) reference.
// Reads require 'clients.view' (granted to Administrator/Manager/Staff);
// writes require 'clients.manage' (Administrator only, via the
// centralized Gate::before override — DEC-028). Contacts share Client's
// permissions — no separate 'contacts.*' pair. Both are flat top-level
// resources (Contact filterable by ?client=<public_id>), route-model-bound
// by public_id (DEC-017), never the internal numeric id. Status changes
// go through the same update endpoint as every other field.
Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
    Route::middleware('can:clients.view')->group(function (): void {
        Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('clients/{client:public_id}', [ClientController::class, 'show'])->name('clients.show');

        Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
        Route::get('contacts/{contact:public_id}', [ContactController::class, 'show'])->name('contacts.show');
    });

    Route::middleware('can:clients.manage')->group(function (): void {
        Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
        Route::match(['put', 'patch'], 'clients/{client:public_id}', [ClientController::class, 'update'])->name('clients.update');
        Route::delete('clients/{client:public_id}', [ClientController::class, 'destroy'])->name('clients.destroy');

        Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
        Route::match(['put', 'patch'], 'contacts/{contact:public_id}', [ContactController::class, 'update'])->name('contacts.update');
        Route::delete('contacts/{contact:public_id}', [ContactController::class, 'destroy'])->name('contacts.destroy');
    });
});

// Staff Operational Status & Location Check-in (Phase 9): lightweight
// operational visibility, deliberately separate from Staff.status
// (employment lifecycle, Phase 7), attendance, and continuous tracking —
// see DEC-005/DEC-032. Self-service ("me") routes require the
// authenticated user to have a linked Staff record (enforced in the
// controller, a domain check — not a permission) and need no permission
// beyond auth:sanctum + account.active, mirroring GET /api/v1/auth/me.
Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
    Route::get('me/status', [OperationalStatusController::class, 'myIndex'])->name('me.status.index');
    Route::post('me/status', [OperationalStatusController::class, 'myStore'])->name('me.status.store');

    Route::get('me/check-ins', [CheckInController::class, 'myIndex'])->name('me.checkins.index');
    Route::post('me/check-ins', [CheckInController::class, 'myStore'])->name('me.checkins.store');

    // Viewing another staff member's operational status is company-wide,
    // low-sensitivity information (same grantees as staff.view/
    // organization.view/clients.view). Setting/correcting another staff
    // member's status on their behalf is Administrator-only.
    Route::middleware('can:staff-status.view')->group(function (): void {
        Route::get('staff/{staff:public_id}/status', [OperationalStatusController::class, 'staffIndex'])->name('staff.status.index');
    });

    Route::middleware('can:staff-status.manage')->group(function (): void {
        Route::post('staff/{staff:public_id}/status', [OperationalStatusController::class, 'staffStore'])->name('staff.status.store');
    });

    // Viewing another staff member's check-in history/current location is
    // materially more sensitive than operational status: `location.view`
    // is granted to Manager (not Staff), and CheckInController further
    // scopes a Manager to their own direct reports only (Staff.manager_id)
    // — Administrator is unscoped via the centralized Gate::before
    // override. Deleting/correcting a specific check-in is
    // Administrator-only (`location.manage`).
    Route::middleware('can:location.view')->group(function (): void {
        Route::get('staff/{staff:public_id}/check-ins', [CheckInController::class, 'staffIndex'])->name('staff.checkins.index');
    });

    Route::middleware('can:location.manage')->group(function (): void {
        Route::delete('check-ins/{checkIn:public_id}', [CheckInController::class, 'destroy'])->name('checkins.destroy');
    });
});

// Projects & Project Membership (Phase 10): the foundation later modules
// (Tasks, Work Logs, project activity, reporting) will reference.
// Deliberately NOT gated by a bare 'can:projects.view' route middleware
// on the read routes below — visibility is scoped in-controller
// (AuthorizesProjectVisibility): an Administrator/Manager ('projects.view')
// sees every Project; an ordinary Staff member sees only Projects where
// they hold a Project Membership. Writes (Project CRUD and all Project
// Membership changes) require 'projects.manage' (Administrator-only, via
// the centralized Gate::before override — DEC-028) — no project-lead
// self-management carve-out. Route-model-bound by public_id (DEC-017);
// membership rows are addressed by their member's Staff public_id within
// the nested collection, not an independent membership public_id.
Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/{project:public_id}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('projects/{project:public_id}/members', [ProjectMembershipController::class, 'index'])->name('projects.members.index');

    Route::middleware('can:projects.manage')->group(function (): void {
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::match(['put', 'patch'], 'projects/{project:public_id}', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('projects/{project:public_id}', [ProjectController::class, 'destroy'])->name('projects.destroy');

        Route::post('projects/{project:public_id}/members', [ProjectMembershipController::class, 'store'])->name('projects.members.store');

        // withoutScopedBindings(): explicit `:public_id` binding fields on
        // two consecutive Eloquent route parameters otherwise make Laravel
        // try to resolve {staff} via a guessed relationship on Project
        // (e.g. Project::staff()/staffs(), which doesn't exist) instead of
        // resolving Staff directly — membership is verified explicitly in
        // ProjectMembershipController::update()/destroy() instead.
        Route::match(['put', 'patch'], 'projects/{project:public_id}/members/{staff:public_id}', [ProjectMembershipController::class, 'update'])
            ->name('projects.members.update')->withoutScopedBindings();
        Route::delete('projects/{project:public_id}/members/{staff:public_id}', [ProjectMembershipController::class, 'destroy'])
            ->name('projects.members.destroy')->withoutScopedBindings();
    });
});

// Tasks (Phase 11): built on top of Projects & Project Membership.
// Optionally belongs to a Project (DEC-006 — independent tasks are
// supported). A flat top-level resource, filterable by ?project=,
// rather than nested under /projects/{project}/tasks — a Task is not
// inherently contextual to a Project the way Project Membership is.
// Reads, creates, and updates are deliberately NOT gated by a bare
// 'can:tasks.manage'/'can:tasks.view' route middleware — visibility and
// management authority are both scoped in-controller
// (AuthorizesTaskAccess): 'tasks.view' (Administrator/Manager) sees
// every Task; otherwise a linked Staff record scopes visibility to
// Projects they're a member of plus Tasks assigned to them.
// 'tasks.manage' (Administrator-only) may create/update/delete any
// Task; a Project Lead may create/update Tasks scoped to their own
// Project; a Task's assignee may update only its own `status` field.
// DELETE remains a plain 'can:tasks.manage' route (Administrator-only,
// and only while a Task is still in its initial 'todo' state — see
// TaskController::destroy).
Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::get('tasks/{task:public_id}', [TaskController::class, 'show'])->name('tasks.show');
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::match(['put', 'patch'], 'tasks/{task:public_id}', [TaskController::class, 'update'])->name('tasks.update');

    Route::middleware('can:tasks.manage')->group(function (): void {
        Route::delete('tasks/{task:public_id}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    });
});

// Work Logs (Phase 12): historical records of work performed by Staff,
// built on top of Staff/Projects/Project Membership/Tasks — not payroll,
// attendance, billing, or a timesheet system (see
// docs/phases/V1_PHASE_12_DEFINITION.md). Self-service ("me") routes
// require only a linked Staff record (a domain check, mirroring
// GET /api/v1/auth/me and Phase 9's /me/status, /me/check-ins) — the
// performer identity is always server-derived, never a client-supplied
// staff_id. The top-level /work-logs surface is the supervisory/
// administrative one: GET/index/show are scoped in-controller
// (AuthorizesWorkLogVisibility) — Administrator sees all, a Manager
// holding `work-logs.view` sees only their own direct reports' logs
// (mirroring Phase 9's location.view precedent, not Phase 10/11's
// company-wide Manager grant), and a Project Lead sees (read-only) logs
// within Projects they lead. All writes on /work-logs (create-for-
// others, edit-any, delete-any) require `work-logs.manage`
// (Administrator-only) — no Project Lead/Manager write authority exists
// anywhere in this module, a deliberately stricter boundary than Tasks.
Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
    Route::get('me/work-logs', [MyWorkLogController::class, 'myIndex'])->name('me.work-logs.index');
    Route::post('me/work-logs', [MyWorkLogController::class, 'myStore'])->name('me.work-logs.store');
    Route::match(['put', 'patch'], 'me/work-logs/{workLog:public_id}', [MyWorkLogController::class, 'myUpdate'])->name('me.work-logs.update');
    Route::delete('me/work-logs/{workLog:public_id}', [MyWorkLogController::class, 'myDestroy'])->name('me.work-logs.destroy');

    Route::get('work-logs', [WorkLogController::class, 'index'])->name('work-logs.index');
    Route::get('work-logs/{workLog:public_id}', [WorkLogController::class, 'show'])->name('work-logs.show');

    Route::middleware('can:work-logs.manage')->group(function (): void {
        Route::post('work-logs', [WorkLogController::class, 'store'])->name('work-logs.store');
        Route::match(['put', 'patch'], 'work-logs/{workLog:public_id}', [WorkLogController::class, 'update'])->name('work-logs.update');
        Route::delete('work-logs/{workLog:public_id}', [WorkLogController::class, 'destroy'])->name('work-logs.destroy');
    });
});

// Leave Management (Phase 13): Leave Types (configurable master data),
// Leave Requests (a pending/approved/rejected/cancelled lifecycle with
// explicit action endpoints, never a generic status PATCH — see
// docs/phases/V1_PHASE_13_DEFINITION.md), and derived Leave Balances.
// Not payroll, attendance, or Work Logs. Leave Type reads require
// `leave-types.view` (Administrator/Manager/Staff — company-wide, a
// Staff member must browse active types to submit a request); writes
// require `leave-types.manage` (Administrator-only). Self-service
// (`/me/leave-requests`, `/me/leave-balances`) needs no permission
// beyond a linked Staff record, mirroring Phase 9/12's `/me/...`
// precedent. The supervisory `/leave-requests` surface's reads are
// scoped in-controller (AuthorizesLeaveRequestVisibility, mirroring
// Phase 12's work-logs.view shape) rather than a bare `can:` middleware;
// `store`/`cancel` require `leave-requests.manage`; `approve`/`reject`
// carry no permission middleware at all — authority (Administrator, or
// the requester's current direct Manager) is resolved entirely in
// LeaveRequestController, the same row-level write-authority shape
// Phase 11/12 established for Project Lead/Work Log authority.
Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
    Route::middleware('can:leave-types.view')->group(function (): void {
        Route::get('leave-types', [LeaveTypeController::class, 'index'])->name('leave-types.index');
        Route::get('leave-types/{leaveType:public_id}', [LeaveTypeController::class, 'show'])->name('leave-types.show');
    });

    Route::middleware('can:leave-types.manage')->group(function (): void {
        Route::post('leave-types', [LeaveTypeController::class, 'store'])->name('leave-types.store');
        Route::match(['put', 'patch'], 'leave-types/{leaveType:public_id}', [LeaveTypeController::class, 'update'])->name('leave-types.update');
        Route::delete('leave-types/{leaveType:public_id}', [LeaveTypeController::class, 'destroy'])->name('leave-types.destroy');
    });

    Route::get('me/leave-requests', [MyLeaveRequestController::class, 'myIndex'])->name('me.leave-requests.index');
    Route::post('me/leave-requests', [MyLeaveRequestController::class, 'myStore'])->name('me.leave-requests.store');
    Route::get('me/leave-requests/{leaveRequest:public_id}', [MyLeaveRequestController::class, 'myShow'])->name('me.leave-requests.show');
    Route::post('me/leave-requests/{leaveRequest:public_id}/cancel', [MyLeaveRequestController::class, 'myCancel'])->name('me.leave-requests.cancel');

    Route::get('me/leave-balances', [LeaveBalanceController::class, 'myIndex'])->name('me.leave-balances.index');

    Route::get('leave-requests', [LeaveRequestController::class, 'index'])->name('leave-requests.index');
    Route::get('leave-requests/{leaveRequest:public_id}', [LeaveRequestController::class, 'show'])->name('leave-requests.show');
    Route::post('leave-requests/{leaveRequest:public_id}/approve', [LeaveRequestController::class, 'approve'])->name('leave-requests.approve');
    Route::post('leave-requests/{leaveRequest:public_id}/reject', [LeaveRequestController::class, 'reject'])->name('leave-requests.reject');

    Route::middleware('can:leave-requests.manage')->group(function (): void {
        Route::post('leave-requests', [LeaveRequestController::class, 'store'])->name('leave-requests.store');
        Route::post('leave-requests/{leaveRequest:public_id}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave-requests.cancel');
    });

    Route::get('staff/{staff:public_id}/leave-balances', [LeaveBalanceController::class, 'staffIndex'])->name('staff.leave-balances.index');

    Route::middleware('can:leave-requests.manage')->group(function (): void {
        Route::post('staff/{staff:public_id}/leave-balances', [LeaveBalanceController::class, 'staffStore'])->name('staff.leave-balances.store');
    });
});

// Announcements (Phase 14): internal broadcast content — not messaging,
// chat, comments, or a notification feed (see docs/phases/
// V1_PHASE_14_DEFINITION.md). Self-service ("me") routes need only a
// linked Staff record, no permission — mirroring Phase 9/12/13's
// `/me/...` precedent; only ever return `published` (non-archived)
// announcements within the requester's own audience (company-wide, or
// their current Department/Team). The entire management surface requires
// `announcements.manage` (Administrator-only, via the centralized
// Gate::before override) — unlike every prior module, there is no
// companion `announcements.view` for Manager/Staff. Lifecycle transitions
// (publish/archive) are explicit action endpoints, never a generic status
// PATCH.
Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
    Route::get('me/announcements', [MyAnnouncementController::class, 'myIndex'])->name('me.announcements.index');
    Route::get('me/announcements/{announcement:public_id}', [MyAnnouncementController::class, 'myShow'])->name('me.announcements.show');
    Route::post('me/announcements/{announcement:public_id}/acknowledge', [MyAnnouncementController::class, 'myAcknowledge'])->name('me.announcements.acknowledge');

    Route::middleware('can:announcements.manage')->group(function (): void {
        Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('announcements/{announcement:public_id}', [AnnouncementController::class, 'show'])->name('announcements.show');
        Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
        Route::match(['put', 'patch'], 'announcements/{announcement:public_id}', [AnnouncementController::class, 'update'])->name('announcements.update');
        Route::delete('announcements/{announcement:public_id}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');
        Route::post('announcements/{announcement:public_id}/publish', [AnnouncementController::class, 'publish'])->name('announcements.publish');
        Route::post('announcements/{announcement:public_id}/archive', [AnnouncementController::class, 'archive'])->name('announcements.archive');
    });
});

// Notifications (Phase 15): in-app, user-scoped notification records fed
// by other modules' events — currently only Announcement publish (see
// AnnouncementController's NotifiesAnnouncementAudience). Recipient
// identity is the User account itself (DEC-038), not Staff — unlike
// every other /me/... surface in this API, no linked-Staff requirement is
// imposed here, only auth:sanctum + account.active. No permission is
// required or defined: a Notification is only ever readable by its own
// recipient (404, not 403, for anyone else's), and there is no
// create/update/delete API at all — Notifications are produced only by
// internal application code, never a client request. Literal routes
// (unread-count, read-all) are registered before the {public_id}-bound
// routes so they aren't swallowed by route-model binding.
Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
    Route::get('me/notifications', [NotificationController::class, 'myIndex'])->name('me.notifications.index');
    Route::get('me/notifications/unread-count', [NotificationController::class, 'myUnreadCount'])->name('me.notifications.unread-count');
    Route::post('me/notifications/read-all', [NotificationController::class, 'myMarkAllRead'])->name('me.notifications.read-all');
    Route::get('me/notifications/{notification:public_id}', [NotificationController::class, 'myShow'])->name('me.notifications.show');
    Route::post('me/notifications/{notification:public_id}/read', [NotificationController::class, 'myMarkRead'])->name('me.notifications.read');
});

// Messaging (Phase 16): direct, group, and project conversations —
// deliberately lightweight (DEC-007), not a chat-platform. Every action
// requires the authenticated User to have a linked Staff record
// (AuthorizesConversationAccess) — participants are identified by Staff,
// not User. No permission gates any Messaging endpoint: visibility is
// membership-only, enforced entirely in-controller, so Administrator's
// usual Gate::before override never grants implicit read-all access (see
// 05_SECURITY_MODEL.md's Messaging Privacy section). A conversation the
// requester isn't currently a member of is 404, not 403. Literal routes
// (direct, group) are registered before the {conversation}-bound routes
// so they aren't swallowed by route-model binding, mirroring
// Notifications' identical precedent.
Route::middleware(['auth:sanctum', 'account.active'])->prefix('conversations')->name('conversations.')->group(function (): void {
    Route::get('/', [ConversationController::class, 'index'])->name('index');
    Route::post('direct', [ConversationController::class, 'storeDirect'])->name('store-direct');
    Route::post('group', [ConversationController::class, 'storeGroup'])->name('store-group');

    Route::get('{conversation:public_id}', [ConversationController::class, 'show'])->name('show');
    Route::post('{conversation:public_id}/read', [ConversationController::class, 'markRead'])->name('read');

    // Group conversation membership only — direct membership is fixed;
    // project conversation membership is managed only through Project
    // Membership (see ProjectMembershipController). withoutScopedBindings
    // mirrors Phase 10's identical two-consecutive-Eloquent-parameter
    // fix (Conversation has no staff() relation for Laravel to guess).
    Route::post('{conversation:public_id}/members', [ConversationMemberController::class, 'store'])->name('members.store');
    Route::delete('{conversation:public_id}/members/{staff:public_id}', [ConversationMemberController::class, 'destroy'])
        ->name('members.destroy')->withoutScopedBindings();

    Route::get('{conversation:public_id}/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::post('{conversation:public_id}/messages', [MessageController::class, 'store'])->name('messages.store');
});

// Project conversation lazy get-or-create entry point (Phase 16) — no
// conversation row exists for a Project until this is called for the
// first time by a current Project member; membership then derives
// exclusively from Project Membership. Visibility requires current
// Project membership, never merely `projects.view`.
Route::middleware(['auth:sanctum', 'account.active'])->group(function (): void {
    Route::post('projects/{project:public_id}/conversation', [ProjectConversationController::class, 'storeOrShow'])
        ->name('projects.conversation');
});
