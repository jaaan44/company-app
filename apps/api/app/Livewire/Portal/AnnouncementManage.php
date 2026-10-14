<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use App\Support\CompanyTimezone;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One announcement on the manage side (Phase 35D): Edit and Delete while
 * a draft, Publish a draft, Archive a published one. Each is confirmed,
 * and the API's status rules decide (its 409 shows as a message).
 */
class AnnouncementManage extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    #[Locked]
    public string $publicId = '';

    public ?string $savedMessage = null;

    public int $toastNonce = 0;

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
    }

    private function path(string $suffix = ''): string
    {
        return '/announcements/'.rawurlencode($this->publicId).$suffix;
    }

    public function publish(): void
    {
        $this->act('POST', '/publish', 'Announcement published. Staff can see it now.');
    }

    public function archive(): void
    {
        $this->act('POST', '/archive', 'Announcement archived.');
    }

    public function delete(): void
    {
        $this->generalError = null;
        try {
            $this->api()->send('DELETE', $this->path());
            session()->flash('status', 'Draft deleted.');
            $this->redirect(route('portal.announcements.manage'), navigate: true);
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't delete the announcement. Please try again.");
        }
    }

    private function act(string $method, string $suffix, string $done): void
    {
        $this->generalError = null;
        try {
            $this->api()->send($method, $this->path($suffix));
            $this->savedMessage = $done;
            $this->toastNonce++;
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't do that. Please try again.");
        }
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $item = null;
        try {
            $item = $this->api()->get($this->path())['data'] ?? null;
        } catch (PortalApiException $e) {
            if ($e->isNotFound()) {
                $this->errorMessage = 'This announcement no longer exists.';
            } else {
                $this->failed($e, 'Something went wrong loading the announcement.', "You don't have access to manage announcements.");
            }
        }

        return view('livewire.portal.announcement-manage', [
            'item' => $item,
            'utcOffset' => now(CompanyTimezone::value())->format('P'),
            'mayEdit' => $this->may('announcements.create'),
            'mayPublish' => $this->may('announcements.publish'),
            'mayDelete' => $this->may('announcements.delete'),
        ]);
    }
}
