<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Portal Home (Phase 34A): the mobile Home (Phase 27) from `GET /me/home`
 * — greeting, Today, Needs attention and Latest announcements. The API
 * data is fetched on every render and never kept in a public property,
 * so it is not echoed into the page's Livewire snapshot.
 */
class Home extends Component
{
    use CallsInternalApi;

    public function render(): View
    {
        $this->errorMessage = null;
        $home = null;

        try {
            $home = $this->api()->get('/me/home')['data'] ?? null;
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading your Home.');
        }

        return view('livewire.portal.home', ['home' => $home]);
    }
}
