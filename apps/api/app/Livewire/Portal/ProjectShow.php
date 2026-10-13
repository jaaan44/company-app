<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Portal project page (Phase 34B): the mobile project detail (Phase 29C)
 * — `GET /projects/{id}`, its first 50 members and first 50 milestones.
 */
class ProjectShow extends Component
{
    use CallsInternalApi;

    public const DETAIL_PAGE_SIZE = 50;

    #[Locked]
    public string $publicId = '';

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $project = null;
        $members = null;
        $milestones = null;
        $path = '/projects/'.rawurlencode($this->publicId);

        try {
            $project = $this->api()->get($path)['data'] ?? null;
            $members = $this->api()->get($path.'/members', ['per_page' => self::DETAIL_PAGE_SIZE, 'page' => 1]);
            $milestones = $this->api()->get($path.'/milestones', ['per_page' => self::DETAIL_PAGE_SIZE, 'page' => 1]);
        } catch (PortalApiException $e) {
            $project = null;
            if ($e->isNotFound()) {
                $this->errorMessage = 'This project no longer exists.';
            } else {
                $this->failed($e, 'Something went wrong loading this project.', forbidden: "You don't have access to this project.");
            }
        }

        return view('livewire.portal.project-show', [
            'project' => $project,
            'members' => $members['data'] ?? [],
            'membersTotal' => $members['meta']['total'] ?? 0,
            'milestones' => $milestones['data'] ?? [],
            'milestonesTotal' => $milestones['meta']['total'] ?? 0,
        ]);
    }
}
