<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal Notifications (Phase 34D): the mobile inbox (Phase 32, R-6) —
 * `GET /me/notifications`, newest first, 20 per page; unread ones bold
 * with a dot. Opening one marks it read (`POST …/{id}/read`; a failure
 * is quiet) and then opens its source: an announcement, or the
 * conversation. **Mark all read** (`POST /me/notifications/read-all`).
 * Notifications belong to the user account, so they work without a
 * staff profile (R-9).
 */
class Notifications extends Component
{
    use CallsInternalApi;

    public const PAGE_SIZE = 20;

    #[Url(except: 1)]
    public int $page = 1;

    public ?string $actionMessage = null;

    public ?string $doneMessage = null;

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function open(string $publicId): void
    {
        if (preg_match('/^[0-9A-Za-z]{1,64}$/', $publicId) !== 1) {
            return;
        }
        $path = '/me/notifications/'.rawurlencode($publicId);

        try {
            $notification = $this->api()->get($path)['data'] ?? null;
        } catch (PortalApiException $e) {
            if ($e->isSessionExpired()) {
                $this->failed($e, '');

                return;
            }
            $this->actionMessage = $e->isNotFound() ? 'This notification is no longer available.' : "Couldn't open it. Please try again.";

            return;
        }

        try {
            if (($notification['read_at'] ?? null) === null) {
                $this->api()->send('POST', $path.'/read');
            }
        } catch (PortalApiException $e) {
            if ($e->isSessionExpired()) {
                $this->failed($e, '');

                return;
            }
            // Quiet, as on mobile: the source still opens.
        }

        $source = $notification['source'] ?? null;
        $sourceId = (string) ($source['public_id'] ?? '');
        if (preg_match('/^[0-9A-Za-z]{1,64}$/', $sourceId) === 1) {
            match ($source['type'] ?? null) {
                'announcement' => $this->redirect(route('portal.announcement', $sourceId)),
                'conversation' => $this->redirect(route('portal.chat', $sourceId)),
                default => null,
            };
        }
    }

    public function markAllRead(): void
    {
        $this->actionMessage = null;
        $this->doneMessage = null;

        try {
            $this->api()->send('POST', '/me/notifications/read-all');
            $this->doneMessage = 'All marked read.';
        } catch (PortalApiException $e) {
            if ($e->isSessionExpired()) {
                $this->failed($e, '');

                return;
            }
            $this->actionMessage = "Couldn't mark them read. Please try again.";
        }
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $result = null;

        try {
            $result = $this->api()->get('/me/notifications', ['per_page' => self::PAGE_SIZE, 'page' => max(1, $this->page)]);
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading notifications.');
        }

        $notifications = $result['data'] ?? [];

        return view('livewire.portal.notifications', [
            'notifications' => $notifications,
            'meta' => $result['meta'] ?? null,
            'companyDay' => $result['meta']['company_day'] ?? null,
            'hasUnread' => collect($notifications)->contains(fn ($n) => $n['read_at'] === null),
        ]);
    }
}
