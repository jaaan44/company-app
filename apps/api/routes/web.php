<?php

use App\Enums\AuditSource;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function (): void {
    Route::get('login', fn () => view('auth.login'))->name('login');
});

Route::middleware(['auth', 'account.active', 'can:admin.access'])->group(function (): void {
    Route::get('home', fn () => view('home'))->name('home');
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
            source: AuditSource::Admin,
            actor: $user,
        );
    }

    Auth::guard('web')->logout();

    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');
