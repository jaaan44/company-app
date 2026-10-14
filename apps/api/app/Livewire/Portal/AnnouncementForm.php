<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * New announcement / Edit draft (Phase 35D): POST and PATCH
 * /api/v1/announcements (`announcements.create`). Saved as a draft;
 * publishing is a separate, confirmed step on its page.
 */
class AnnouncementForm extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    #[Locked]
    public ?string $publicId = null;

    #[Locked]
    public bool $notFound = false;

    #[Locked]
    public bool $notDraft = false;

    public string $title = '';

    public string $body = '';

    public string $audience = 'company_wide';

    /** @var list<string> */
    public array $departments = [];

    /** @var list<string> */
    public array $teams = [];

    public function mount(?string $publicId = null): void
    {
        if ($publicId === null) {
            return;
        }
        $this->publicId = $publicId;
        try {
            $a = $this->api()->get('/announcements/'.rawurlencode($publicId))['data'];
            $this->notDraft = $a['status'] !== 'draft';
            $this->title = (string) $a['title'];
            $this->body = (string) $a['body'];
            $this->audience = (string) $a['audience_type'];
            $this->departments = array_column($a['departments'] ?? [], 'public_id');
            $this->teams = array_column($a['teams'] ?? [], 'public_id');
        } catch (PortalApiException $e) {
            if ($e->isNotFound()) {
                $this->notFound = true;
            } else {
                $this->failed($e, 'Something went wrong loading the announcement.', "You don't have access to manage announcements.");
            }
        }
    }

    public function save(): void
    {
        $this->generalError = null;
        $this->resetErrorBag();
        $title = trim($this->title);
        $body = trim($this->body);
        if ($title === '') {
            $this->addError('title', 'Give the announcement a title.');
        } elseif (mb_strlen($title) > 200) {
            $this->addError('title', 'Use at most 200 characters.');
        }
        if ($body === '') {
            $this->addError('body', 'Write the announcement.');
        } elseif (mb_strlen($body) > 10000) {
            $this->addError('body', 'Use at most 10000 characters.');
        }
        $scoped = $this->audience === 'scoped';
        $departments = array_values(array_filter(array_map('strval', $this->departments), fn ($id) => preg_match('/^[0-9A-Za-z]{1,64}$/', $id)));
        $teams = array_values(array_filter(array_map('strval', $this->teams), fn ($id) => preg_match('/^[0-9A-Za-z]{1,64}$/', $id)));
        if ($scoped && $departments === [] && $teams === []) {
            $this->addError('audience', 'Choose at least one department or team.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $data = [
            'title' => $title,
            'body' => $body,
            'audience_type' => $scoped ? 'scoped' : 'company_wide',
            'department_ids' => $scoped ? $departments : [],
            'team_ids' => $scoped ? $teams : [],
        ];
        try {
            if ($this->publicId === null) {
                $a = $this->api()->send('POST', '/announcements', json: $data)['data'];
                session()->flash('status', 'Draft saved. Publish it when it is ready.');
            } else {
                $a = $this->api()->send('PATCH', '/announcements/'.rawurlencode($this->publicId), json: $data)['data'];
                session()->flash('status', 'Changes saved.');
            }
            $this->redirect(route('portal.announcements.manage.show', $a['public_id']), navigate: true);
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [
                'title' => 'title', 'body' => 'body', 'audience_type' => 'audience', 'department_ids' => 'audience', 'team_ids' => 'audience',
            ], "Couldn't save the announcement. Please try again.");
        }
    }

    public function render(): View
    {
        $departments = [];
        $teams = [];
        if (! $this->notFound && ! $this->notDraft && $this->errorMessage === null) {
            try {
                $departments = $this->api()->get('/departments', ['per_page' => 100])['data'] ?? [];
                $teams = $this->api()->get('/teams', ['per_page' => 100])['data'] ?? [];
            } catch (PortalApiException $e) {
                if ($e->isSessionExpired()) {
                    $this->failed($e, '');
                }
                // Without access to the organisation, only "Everyone" can be chosen.
            }
        }

        return view('livewire.portal.announcement-form', ['allDepartments' => $departments, 'allTeams' => $teams]);
    }
}
