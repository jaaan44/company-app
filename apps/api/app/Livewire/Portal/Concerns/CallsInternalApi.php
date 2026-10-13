<?php

namespace App\Livewire\Portal\Concerns;

use App\Services\Portal\InternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Support\Facades\Auth;

/**
 * Shared by every Staff Web Portal component (Phase 34A): the internal
 * API client, and one place that turns a failed call into what the page
 * shows — the same outcomes as the mobile app.
 *
 * - 401: the session is gone; sign out and go to the login page.
 * - 403: on a `/me/*` page, the no-profile state (`$noProfile`); on any
 *   other page, its own forbidden message.
 * - Anything else: the page's error message with "Try again".
 */
trait CallsInternalApi
{
    public bool $noProfile = false;

    public ?string $errorMessage = null;

    protected function api(): InternalApi
    {
        return app(InternalApi::class);
    }

    /**
     * Records the failure on the component. Returns false when the page
     * is leaving (session expired), so callers stop there.
     */
    protected function failed(PortalApiException $e, string $fallback, ?string $forbidden = null): bool
    {
        if ($e->isSessionExpired()) {
            Auth::guard('web')->logout();
            session()->invalidate();
            session()->regenerateToken();
            session()->flash('status', 'Your session has ended. Please sign in again.');
            $this->redirect(route('login'));

            return false;
        }

        if ($e->isForbidden()) {
            if ($forbidden === null) {
                $this->noProfile = true;
            } else {
                $this->errorMessage = $forbidden;
            }

            return true;
        }

        $this->errorMessage = $fallback;

        return true;
    }
}
