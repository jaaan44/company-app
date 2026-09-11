<?php

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
// is always allowed, even for a now-suspended/inactive account.
Route::middleware('auth')->post('logout', function () {
    Auth::guard('web')->logout();

    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');
