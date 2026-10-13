<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Portal colleague page (Phase 34A): the mobile staff detail (Phase 28)
 * from `GET /staff/{publicId}`. Read-only, copy-only contact details.
 */
class StaffShow extends Component
{
    use CallsInternalApi;

    #[Locked]
    public string $publicId = '';

    public bool $notFound = false;

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $this->notFound = false;
        $staff = null;

        try {
            $staff = $this->api()->get('/staff/'.rawurlencode($this->publicId))['data'] ?? null;
        } catch (PortalApiException $e) {
            if ($e->isNotFound()) {
                $this->notFound = true;
            } else {
                $this->failed(
                    $e,
                    'Something went wrong loading this person.',
                    forbidden: 'You don\'t have access to the staff directory.',
                );
            }
        }

        return view('livewire.portal.staff-show', ['staff' => $staff]);
    }
}
