<?php

use App\Enums\AuditSource;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// The site root opens the Staff Web Portal (Phase 34A, DEC-063 W-2);
// guests are sent on to the login page by its `auth` middleware.
Route::redirect('/', '/app');

Route::middleware('guest')->group(function (): void {
    Route::get('login', fn () => view('auth.login'))->name('login');
});

Route::middleware(['auth', 'account.active', 'can:admin.access'])->group(function (): void {
    Route::get('home', fn () => view('home'))->name('home');
});

// Staff Web Portal (Phase 34A, DEC-063): every active account, whatever
// its role. Each screen is a Livewire component that reads and writes
// only through the existing /api/v1 (App\Services\Portal\InternalApi),
// so the API's permissions and rules decide what each person may see.
Route::middleware(['auth', 'account.active'])->prefix('app')->name('portal.')->group(function (): void {
    Route::view('/', 'portal.home')->name('home');
    Route::view('profile', 'portal.profile')->name('profile');
    Route::view('staff', 'portal.directory')->name('directory');
    Route::get('staff/{publicId}', fn (string $publicId) => view('portal.staff', ['publicId' => $publicId]))
        ->where('publicId', '[0-9A-Za-z]{1,64}')
        ->name('staff');
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
