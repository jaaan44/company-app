<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Portal "My profile" (Phase 34A): the mobile profile (Phase 28) from
 * `GET /me/profile`. Read-only; changes go through an administrator.
 */
class Profile extends Component
{
    use CallsInternalApi;

    public function render(): View
    {
        $this->errorMessage = null;
        $profile = null;

        try {
            $profile = $this->api()->get('/me/profile')['data'] ?? null;
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading your profile.');
        }

        return view('livewire.portal.profile', ['profile' => $profile]);
    }
}
