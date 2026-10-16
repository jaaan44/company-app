<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use App\Support\Http\PerPage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Portal chat (Phase 34D): the mobile conversation screen (Phase 32,
 * R-4/R-5) — `GET /conversations/{id}/messages`, 30 per page, shown oldest
 * to newest with day separators; **Load earlier messages**; a composer
 * whose Send is confirmed by the API (`POST …/messages`, 1–4,000
 * characters, trimmed; the text stays on failure). The page checks for
 * new messages every 15 s while open (`wire:poll`), and marks the chat
 * read (`POST …/read`) when it opens and whenever a newer message shows.
 */
class Chat extends Component
{
    use CallsInternalApi;

    public const PAGE_SIZE = 30;

    public const MAX_PAGES = 20;

    public const MAX_BODY = 4000;

    #[Locked]
    public string $publicId = '';

    #[Locked]
    public ?string $myStaffId = null;

    /** The newest message already marked read. */
    #[Locked]
    public ?string $readUpTo = null;

    #[Locked]
    public int $pages = 1;

    public string $body = '';

    public ?string $sendError = null;

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
        try {
            $this->myStaffId = $this->api()->get('/me/profile')['data']['staff']['public_id'] ?? null;
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading this conversation.');
        }
        if ($this->myStaffId === null && $this->errorMessage === null) {
            $this->noProfile = true;
        }
    }

    public function loadEarlier(): void
    {
        $this->pages = min(self::MAX_PAGES, $this->pages + 1);
    }

    public function send(): void
    {
        $this->sendError = null;
        $text = trim($this->body);
        if ($text === '') {
            return;
        }
        if (mb_strlen($text) > self::MAX_BODY) {
            $this->sendError = 'Use at most '.self::MAX_BODY.' characters.';

            return;
        }

        try {
            $this->api()->send('POST', $this->path().'/messages', json: ['body' => $text]);
            $this->body = '';
        } catch (PortalApiException $e) {
            if ($e->isSessionExpired()) {
                $this->failed($e, '');

                return;
            }
            $this->sendError = match (true) {
                $e->isNotFound() => 'This conversation is no longer available.',
                $e->status === 422 => $e->firstErrorFor('body') ?? "Couldn't send. Try again.",
                default => "Couldn't send. Try again.",
            };
        }
    }

    public function render(): View
    {
        $conversation = null;
        $messages = [];
        $meta = null;

        if (! $this->noProfile && $this->errorMessage === null) {
            try {
                $conversation = $this->api()->get($this->path())['data'] ?? null;
                // Newest first, PAGE_SIZE × pages of them, fetched 100 at a
                // time (the API's page-size cap since Phase 37).
                $wanted = self::PAGE_SIZE * $this->pages;
                $rows = [];
                for ($apiPage = 1; count($rows) < $wanted; $apiPage++) {
                    $page = $this->api()->get($this->path().'/messages', ['per_page' => min($wanted, PerPage::MAX), 'page' => $apiPage]);
                    $rows = array_merge($rows, $page['data'] ?? []);
                    $meta = $page['meta'] ?? null;
                    if ($apiPage >= ($meta['last_page'] ?? 1)) {
                        break;
                    }
                }
                $rows = array_slice($rows, 0, $wanted);
                $messages = array_reverse($rows);
                $newest = $rows[0]['public_id'] ?? null;
                if ($newest !== null && $newest !== $this->readUpTo) {
                    $this->api()->send('POST', $this->path().'/read');
                    $this->readUpTo = $newest;
                }
            } catch (PortalApiException $e) {
                $conversation = null;
                if ($e->isNotFound()) {
                    $this->errorMessage = 'This conversation is no longer available.';
                } else {
                    $this->failed($e, 'Something went wrong loading this conversation.', forbidden: "You don't have access to this conversation.");
                }
            }
        }

        return view('livewire.portal.chat', [
            'conversation' => $conversation,
            'messages' => $messages,
            'companyDay' => $meta['company_day'] ?? null,
            'hasEarlier' => $meta !== null && ($meta['total'] ?? 0) > count($messages) && $this->pages < self::MAX_PAGES,
        ]);
    }

    private function path(): string
    {
        return '/conversations/'.rawurlencode($this->publicId);
    }
}
