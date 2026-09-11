<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Organization\DepartmentController;
use App\Http\Controllers\Api\V1\Organization\PositionController;
use App\Http\Controllers\Api\V1\Organization\TeamController;
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
