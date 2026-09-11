<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Clients\ClientController;
use App\Http\Controllers\Api\V1\Clients\ContactController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Organization\DepartmentController;
use App\Http\Controllers\Api\V1\Organization\PositionController;
use App\Http\Controllers\Api\V1\Organization\TeamController;
use App\Http\Controllers\Api\V1\Projects\ProjectController;
use App\Http\Controllers\Api\V1\Projects\ProjectMembershipController;
use App\Http\Controllers\Api\V1\Staff\StaffController;
use App\Http\Controllers\Api\V1\StaffOperations\CheckInController;
use App\Http\Controllers\Api\V1\StaffOperations\OperationalStatusController;
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
