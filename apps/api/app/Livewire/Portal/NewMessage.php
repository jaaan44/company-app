<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal "New message" (Phase 34D): the mobile colleague picker (Phase
 * 32, R-3) — the active staff directory (`GET /staff`, 25 per page,
 * searchable), without myself; choosing someone find-or-creates our
 * direct chat (`POST /conversations/direct`) and opens it.
 */
class NewMessage extends Component
{
    use CallsInternalApi;

    public const PAGE_SIZE = 25;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 1)]
    public int $page = 1;

    public ?string $startError = null;

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function start(string $staffId): void
    {
        $this->startError = null;
        if (preg_match('/^[0-9A-Za-z]{1,64}$/', $staffId) !== 1) {
            return;
        }

        try {
            $conversation = $this->api()->send('POST', '/conversations/direct', json: ['staff_id' => $staffId])['data'] ?? null;
            if (isset($conversation['public_id'])) {
                $this->redirect(route('portal.chat', $conversation['public_id']), navigate: true);
            }
        } catch (PortalApiException $e) {
            if ($e->isSessionExpired()) {
                $this->failed($e, '');

                return;
            }
            $this->startError = in_array($e->status, [403, 422], true) && $e->getMessage() !== ''
                ? $e->getMessage()
                : "Couldn't open the conversation. Please try again.";
        }
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $this->noProfile = false;
        $result = null;
        $myStaffId = null;

        try {
            $myStaffId = $this->api()->get('/me/profile')['data']['staff']['public_id'] ?? null;
            if ($myStaffId === null) {
                $this->noProfile = true;
            } else {
                $search = trim($this->search);
                $result = $this->api()->get('/staff', [
                    'status' => 'active',
                    'per_page' => self::PAGE_SIZE,
                    'page' => max(1, $this->page),
                    'q' => $search === '' ? null : $search,
                ]);
            }
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading the directory.');
        }

        return view('livewire.portal.new-message', [
            'people' => collect($result['data'] ?? [])->reject(fn ($s) => $s['public_id'] === $myStaffId)->values()->all(),
            'meta' => $result['meta'] ?? null,
        ]);
    }
}
