<?php

use App\Enums\AuditSource;
use App\Http\Controllers\Portal\ReportFileController;
use App\Services\Audit\AuditLogger;
use App\Support\Admin\Sections;
use App\Support\Audit\AuditActions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Every portal and Backoffice `{publicId}` is a public ULID-style id
// (DEC-017): letters and digits only, so nothing else ever reaches an
// in-process API path.
Route::pattern('publicId', '[0-9A-Za-z]{1,64}');

// The site root opens the Staff Web Portal (Phase 34A, DEC-063 W-2);
// guests are sent on to the login page by its `auth` middleware.
Route::redirect('/', '/app');

Route::middleware('guest')->group(function (): void {
    Route::get('login', fn () => view('auth.login'))->name('login');
});

// Choose your password (Phase 36A, DEC-066 M-4): the only page an account
// created or reset by an Administrator reaches until it has chosen its own
// password (EnsureAccountIsActive sends it here).
Route::middleware(['auth', 'account.active'])->get('password', fn () => Auth::user()?->must_change_password
    ? view('auth.choose-password')
    : redirect()->route('portal.home'))->name('password.choose');

// Admin Backoffice (Phase 35B/35C, DEC-065): Administrators only
// (`admin.access`). `/home`, the former placeholder, now leads to it.
Route::redirect('home', '/admin')->name('home');

Route::middleware(['auth', 'account.active', 'can:admin.access'])->prefix('admin')->name('admin.')->group(function (): void {
    $page = fn (string $title, string $component, array $params = []) => view('admin.page', ['title' => $title, 'livewire' => $component, 'params' => $params]);
    Route::get('/', fn () => $page('Dashboard', 'admin.dashboard'))->name('dashboard');

    // Phase 35C: Administration.
    Route::middleware('can:authorization.manage')->group(function () use ($page): void {
        Route::get('administration/roles', fn () => $page('Roles & permissions', 'admin.roles'))->name('roles');
        Route::get('administration/users', fn () => $page('Users and roles', 'admin.users'))->name('users');
        Route::get('administration/users/new', fn () => $page('New sign-in account', 'admin.account-form', ['staff' => request()->query('staff')]))->name('users.new'); // Phase 36A
    });

    // Phase 36A: People.
    Route::get('people/staff', fn () => $page('Staff records', 'admin.staff-list'))->middleware('can:staff.view')->name('staff');
    Route::get('people/staff/new', fn () => $page('New staff record', 'admin.staff-form'))->middleware('can:staff.create')->name('staff.new');
    Route::get('people/staff/{publicId}', fn (string $publicId) => $page('Staff record', 'admin.staff-record', ['publicId' => $publicId]))->middleware('can:staff.view')->name('staff.show');
    Route::get('people/staff/{publicId}/edit', fn (string $publicId) => $page('Edit staff record', 'admin.staff-form', ['publicId' => $publicId]))->middleware('can:staff.update')->name('staff.edit');
    Route::get('people/organization', fn () => $page('Departments, teams and positions', 'admin.organization'))->middleware('can:organization.view')->name('organization');
    Route::get('administration/audit-log', fn () => $page('Audit log', 'admin.audit-log'))->name('audit-log');

    Route::get('{section}', fn (string $section) => view('admin.section', ['key' => $section]))
        ->whereIn('section', array_keys(Sections::ALL))->name('section');
});

// Staff Web Portal (Phase 34A, DEC-063): every active account, whatever
// its role. Each screen is a Livewire component that reads and writes
// only through the existing /api/v1 (App\Services\Portal\InternalApi),
// so the API's permissions and rules decide what each person may see.

Route::middleware(['auth', 'account.active'])->prefix('app')->name('portal.')->group(function (): void {
    Route::view('/', 'portal.home')->name('home');
    Route::view('more', 'portal.more')->name('more'); // Phase 34F (F-1)
    Route::view('profile', 'portal.profile')->name('profile');
    Route::get('profile/password', fn () => view('portal.page', ['title' => 'Change password', 'livewire' => 'account.change-password', 'params' => []]))->name('password'); // Phase 36A
    Route::view('staff', 'portal.directory')->name('directory');
    Route::get('staff/{publicId}', fn (string $publicId) => view('portal.staff', ['publicId' => $publicId]))->name('staff');

    // Phase 34B — Work.
    $page = fn (string $title, string $component, array $params = []) => view('portal.page', ['title' => $title, 'livewire' => $component, 'params' => $params]);
    Route::get('tasks', fn () => $page('Tasks', 'portal.tasks'))->name('tasks');
    Route::get('tasks/new', fn () => $page('New task', 'portal.task-form', ['forProject' => request()->query('project')]))->name('tasks.new'); // Phase 35D
    Route::get('tasks/{publicId}', fn (string $publicId) => $page('Task', 'portal.task-show', ['publicId' => $publicId]))->name('task');
    Route::get('tasks/{publicId}/edit', fn (string $publicId) => $page('Edit task', 'portal.task-form', ['publicId' => $publicId]))->name('tasks.edit');
    Route::get('work-logs', fn () => $page('My work logs', 'portal.work-logs'))->name('work-logs');
    Route::get('work-logs/new', fn () => $page('Log work', 'portal.work-log-form', ['task' => request()->query('task')]))->name('work-logs.new');
    Route::get('work-logs/{publicId}/edit', fn (string $publicId) => $page('Edit work log', 'portal.work-log-form', ['publicId' => $publicId]))->name('work-logs.edit');
    Route::get('projects', fn () => $page('Projects', 'portal.projects'))->name('projects');
    Route::get('projects/new', fn () => $page('New project', 'portal.project-form'))->name('projects.new'); // Phase 35D
    Route::get('projects/{publicId}', fn (string $publicId) => $page('Project', 'portal.project-show', ['publicId' => $publicId]))->name('project');
    Route::get('projects/{publicId}/edit', fn (string $publicId) => $page('Edit project', 'portal.project-form', ['publicId' => $publicId]))->name('projects.edit');
    Route::get('clients', fn () => $page('Clients', 'portal.clients'))->name('clients');
    Route::get('clients/new', fn () => $page('New client', 'portal.client-form'))->name('clients.new'); // Phase 35D
    Route::get('clients/{publicId}', fn (string $publicId) => $page('Client', 'portal.client-show', ['publicId' => $publicId]))->name('client');
    Route::get('clients/{publicId}/edit', fn (string $publicId) => $page('Edit client', 'portal.client-form', ['publicId' => $publicId]))->name('clients.edit');
    Route::get('clients/{publicId}/contacts/new', fn (string $publicId) => $page('New contact', 'portal.contact-form', ['clientId' => $publicId]))->name('contacts.new');
    Route::get('clients/{publicId}/contacts/{contactId}/edit', fn (string $publicId, string $contactId) => $page('Edit contact', 'portal.contact-form', ['clientId' => $publicId, 'publicId' => $contactId]))
        ->where('contactId', '[0-9A-Za-z]{1,64}')->name('contacts.edit');
    Route::get('schedule', fn () => $page('Schedule', 'portal.schedule'))->name('schedule');
    Route::get('schedule/entries/{publicId}', fn (string $publicId) => $page('Schedule', 'portal.schedule-entry-show', ['publicId' => $publicId]))->name('schedule-entry');

    // Phase 34C — HR & Operations.
    Route::get('leave', fn () => $page('Leave', 'portal.leave'))->name('leave');
    Route::get('leave/new', fn () => $page('Request leave', 'portal.leave-form'))->name('leave.new');
    Route::get('leave/requests/{publicId}', fn (string $publicId) => $page('Leave request', 'portal.leave-request-show', ['publicId' => $publicId]))->name('leave.request');
    Route::get('leave/team', fn () => $page('Team requests', 'portal.team-leave'))->name('leave.team');
    Route::get('leave/team/{publicId}', fn (string $publicId) => $page('Team request', 'portal.leave-request-show', ['publicId' => $publicId, 'viewer' => 'team']))->name('leave.team-request');
    Route::get('status', fn () => $page('My status', 'portal.my-status'))->name('status');
    Route::get('status/changes', fn () => $page('All status changes', 'portal.status-history'))->name('status.changes');
    Route::get('status/check-ins', fn () => $page('All check-ins', 'portal.status-history', ['kind' => 'check-ins']))->name('status.check-ins');

    // Phase 34D — Communication.
    Route::get('messages', fn () => $page('Messages', 'portal.messages'))->name('messages');
    Route::get('messages/new', fn () => $page('New message', 'portal.new-message'))->name('messages.new');
    Route::get('messages/{publicId}', fn (string $publicId) => $page('Conversation', 'portal.chat', ['publicId' => $publicId]))->name('chat');
    Route::get('notifications', fn () => $page('Notifications', 'portal.notifications'))->name('notifications');
    Route::get('announcements', fn () => $page('Announcements', 'portal.announcements'))->name('announcements');
    // Phase 35D: Announcements → Manage (registered before announcements/{publicId}).
    Route::get('announcements/manage', fn () => $page('Manage announcements', 'portal.announcements-manage'))->name('announcements.manage');
    Route::get('announcements/manage/new', fn () => $page('New announcement', 'portal.announcement-form'))->name('announcements.manage.new');
    Route::get('announcements/manage/{publicId}', fn (string $publicId) => $page('Announcement', 'portal.announcement-manage', ['publicId' => $publicId]))->name('announcements.manage.show');
    Route::get('announcements/manage/{publicId}/edit', fn (string $publicId) => $page('Edit draft', 'portal.announcement-form', ['publicId' => $publicId]))->name('announcements.manage.edit');
    Route::get('announcements/{publicId}', fn (string $publicId) => $page('Announcement', 'portal.announcement-show', ['publicId' => $publicId]))->name('announcement');

    // Phase 34E — Reports.
    Route::get('reports', fn () => $page('Reports', 'portal.reports'))->name('reports');
    Route::get('reports/service/new', fn () => $page('New service report', 'portal.service-report-form'))->name('service-report.new');
    Route::get('reports/service/{publicId}', fn (string $publicId) => $page('Service report', 'portal.report-show', ['publicId' => $publicId]))->name('service-report');
    Route::get('reports/service/{publicId}/edit', fn (string $publicId) => $page('Edit service report', 'portal.service-report-form', ['publicId' => $publicId]))->name('service-report.edit');
    Route::get('reports/incidents/new', fn () => $page('Report an incident', 'portal.incident-form'))->name('incident.new');
    Route::get('reports/incidents/{publicId}', fn (string $publicId) => $page('Incident', 'portal.report-show', ['publicId' => $publicId, 'kind' => 'incidents']))->name('incident');
    Route::get('reports/incidents/{publicId}/edit', fn (string $publicId) => $page('Edit incident', 'portal.incident-form', ['publicId' => $publicId]))->name('incident.edit');
    Route::get('reports/{kind}/{publicId}/files/{attachmentId}', ReportFileController::class)
        ->whereIn('kind', ['service', 'incidents'])->where('attachmentId', '[0-9A-Za-z]{1,64}')->name('report-file');
});

// Deliberately outside the account.active gate: revoking one's own session
// is always allowed, even for a now-suspended/inactive account. The actor
// is captured (Phase 21, DEC-044) before the session is invalidated,
// mirroring AuthController::logout()'s identical ordering.
Route::middleware('auth')->post('logout', function () {
    $user = Auth::guard('web')->user();

    if ($user !== null) {
        app(AuditLogger::class)->recordForRequest(
            request(),
            AuditActions::AUTH_LOGOUT,
            entityType: 'User',
            entityPublicId: $user->public_id,
            source: AuditSource::Web,
            actor: $user,
        );
    }

    Auth::guard('web')->logout();

    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');
