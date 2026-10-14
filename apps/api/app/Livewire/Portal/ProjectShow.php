<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
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
    use WritesThroughApi;

    public const DETAIL_PAGE_SIZE = 50;

    #[Locked]
    public string $publicId = '';

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
    }

    // ---- Phase 35D: managing the project (the API decides every action) ----

    public string $memberSearch = '';

    public string $newMemberId = '';

    public string $newMemberName = '';

    public string $newMemberRole = 'member';

    public string $milestoneTitle = '';

    public string $milestoneDue = '';

    public ?string $savedMessage = null;

    public int $toastNonce = 0;

    private function done(string $message): void
    {
        $this->generalError = null;
        $this->savedMessage = $message;
        $this->toastNonce++;
    }

    private function path(string $suffix = ''): string
    {
        return '/projects/'.rawurlencode($this->publicId).$suffix;
    }

    private static function validId(string $id): bool
    {
        return (bool) preg_match('/^[0-9A-Za-z]{1,64}$/', $id);
    }

    public function delete(): void
    {
        $this->generalError = null;
        try {
            $this->api()->send('DELETE', $this->path());
            session()->flash('status', 'Project deleted.');
            $this->redirect(route('portal.projects'), navigate: true);
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't delete the project. Please try again.");
        }
    }

    public function chooseMember(string $publicId, string $name): void
    {
        $this->newMemberId = $publicId;
        $this->newMemberName = $name;
        $this->memberSearch = '';
    }

    public function addMember(): void
    {
        $this->generalError = null;
        $this->resetErrorBag();
        if ($this->newMemberId === '' || ! self::validId($this->newMemberId)) {
            $this->addError('memberSearch', 'Search for and choose a person.');

            return;
        }
        try {
            $this->api()->send('POST', $this->path('/members'), json: [
                'staff_id' => $this->newMemberId,
                'role' => $this->newMemberRole === 'project_lead' ? 'project_lead' : 'member',
            ]);
            $this->done($this->newMemberName.' added.');
            $this->newMemberId = '';
            $this->newMemberName = '';
            $this->newMemberRole = 'member';
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, ['staff_id' => 'memberSearch', 'role' => 'memberSearch'], "Couldn't add the member. Please try again.");
        }
    }

    public function setMemberRole(string $staffId, string $role): void
    {
        if (! self::validId($staffId) || ! in_array($role, ['member', 'project_lead'], true)) {
            return;
        }
        try {
            $this->api()->send('PATCH', $this->path('/members/'.$staffId), json: ['role' => $role]);
            $this->done('Role changed.');
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't change the role. Please try again.");
        }
    }

    public function removeMember(string $staffId): void
    {
        if (! self::validId($staffId)) {
            return;
        }
        try {
            $this->api()->send('DELETE', $this->path('/members/'.$staffId));
            $this->done('Member removed.');
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't remove the member. Please try again.");
        }
    }

    public function addMilestone(): void
    {
        $this->generalError = null;
        $this->resetErrorBag();
        if (trim($this->milestoneTitle) === '') {
            $this->addError('milestoneTitle', 'Give the milestone a title.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->milestoneDue)) {
            $this->addError('milestoneDue', 'Choose a due date.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        try {
            $this->api()->send('POST', $this->path('/milestones'), json: ['title' => trim($this->milestoneTitle), 'due_date' => $this->milestoneDue]);
            $this->done('Milestone added.');
            $this->milestoneTitle = '';
            $this->milestoneDue = '';
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, ['title' => 'milestoneTitle', 'due_date' => 'milestoneDue'], "Couldn't add the milestone. Please try again.");
        }
    }

    public function setMilestoneStatus(string $milestoneId, string $status): void
    {
        if (! self::validId($milestoneId) || ! in_array($status, ['pending', 'completed', 'cancelled'], true)) {
            return;
        }
        try {
            $this->api()->send('PATCH', $this->path('/milestones/'.$milestoneId), json: ['status' => $status]);
            $this->done('Milestone updated.');
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't update the milestone. Please try again.");
        }
    }

    public function removeMilestone(string $milestoneId): void
    {
        if (! self::validId($milestoneId)) {
            return;
        }
        try {
            $this->api()->send('DELETE', $this->path('/milestones/'.$milestoneId));
            $this->done('Milestone removed.');
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't remove the milestone. Please try again.");
        }
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

        $isLead = ($project['my_role'] ?? null) === 'project_lead';
        $people = [];
        if ($project !== null && $this->may('projects.members') && mb_strlen(trim($this->memberSearch)) >= 2) {
            try {
                $people = $this->api()->get('/staff', ['q' => trim($this->memberSearch), 'status' => 'active', 'per_page' => 8])['data'] ?? [];
            } catch (PortalApiException) {
                $people = [];
            }
        }

        return view('livewire.portal.project-show', [
            'mayEdit' => $project !== null && $this->may('projects.update'),
            'mayDelete' => $project !== null && $this->may('projects.delete'),
            'mayMembers' => $project !== null && $this->may('projects.members'),
            'mayMilestones' => $project !== null && ($this->may('projects.update') || $isLead),
            'mayAddTask' => $project !== null && ($this->may('tasks.create') || $isLead),
            'people' => $people,
            'project' => $project,
            'members' => $members['data'] ?? [],
            'membersTotal' => $members['meta']['total'] ?? 0,
            'milestones' => $milestones['data'] ?? [],
            'milestonesTotal' => $milestones['meta']['total'] ?? 0,
        ]);
    }
}
