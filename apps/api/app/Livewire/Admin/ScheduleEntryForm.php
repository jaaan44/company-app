<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\PicksStaff;
use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use App\Support\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * New / Edit schedule entry (Phase 36C), through /api/v1/schedule-entries:
 * title, activity, all day or times (entered in company time), project
 * (new entries only; the API fixes it), people, description, and Delete.
 * The API decides who may create (its creator is a staff profile, so an
 * account without one is refused with the API's message), edit or
 * delete.
 */
class ScheduleEntryForm extends Component
{
    use CallsInternalApi;
    use PicksStaff;
    use WritesThroughApi;

    public const TYPES = [
        'meeting' => 'Meeting', 'client_visit' => 'Client visit', 'service_appointment' => 'Service appointment',
        'company_event' => 'Company event', 'training' => 'Training', 'internal_activity' => 'Internal activity', 'other' => 'Other',
    ];

    #[Locked]
    public ?string $publicId = null;

    #[Locked]
    public bool $notFound = false;

    public string $title = '';

    public string $activityType = 'meeting';

    public bool $allDay = false;

    public string $startDate = '';

    public string $startTime = '09:00';

    public string $endDate = '';

    public string $endTime = '10:00';

    public string $projectId = '';

    public string $projectName = '';

    public string $description = '';

    /** @var array<string, string> participant public id => name */
    public array $participants = [];

    public function mount(?string $publicId = null): void
    {
        $tz = CompanyTimezone::value();
        $this->startDate = $this->endDate = now($tz)->toDateString();
        if ($publicId === null) {
            return;
        }
        $this->publicId = $publicId;
        try {
            $e = $this->api()->get('/schedule-entries/'.rawurlencode($publicId))['data'];
        } catch (PortalApiException $ex) {
            $ex->isNotFound() ? $this->notFound = true : $this->failed($ex, 'Something went wrong loading the entry.', "You don't have access to this entry.");

            return;
        }
        $start = CarbonImmutable::parse($e['starts_at'])->setTimezone($tz);
        $end = CarbonImmutable::parse($e['ends_at'])->setTimezone($tz);
        $this->title = (string) $e['title'];
        $this->activityType = (string) $e['activity_type'];
        $this->allDay = (bool) $e['is_all_day'];
        [$this->startDate, $this->startTime] = [$start->toDateString(), $start->format('H:i')];
        [$this->endDate, $this->endTime] = [$end->toDateString(), $end->format('H:i')];
        $this->projectName = (string) ($e['project']['name'] ?? '');
        $this->description = (string) ($e['description'] ?? '');
        foreach ($e['participants'] ?? [] as $person) {
            $this->participants[$person['public_id']] = $person['display_name'];
        }
    }

    protected function staffChosen(): void
    {
        if ($this->staffId !== '') {
            $this->participants[$this->staffId] = $this->staffName;
        }
        $this->staffId = $this->staffName = '';
    }

    public function removeParticipant(string $publicId): void
    {
        unset($this->participants[$publicId]);
    }

    public function save(): void
    {
        $this->generalError = null;
        $this->resetErrorBag();
        $title = trim($this->title);
        if ($title === '' || mb_strlen($title) > 255) {
            $this->addError('title', 'Give the entry a title (at most 255 characters).');
        }
        $tz = CompanyTimezone::value();
        try {
            $starts = $this->allDay ? $this->startDate : CarbonImmutable::parse($this->startDate.' '.$this->startTime, $tz)->toIso8601String();
            $ends = $this->allDay ? $this->endDate : CarbonImmutable::parse($this->endDate.' '.$this->endTime, $tz)->toIso8601String();
        } catch (\Throwable) {
            $this->addError('startDate', 'Give a valid start and end.');

            return;
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $body = [
            'title' => $title,
            'activity_type' => array_key_exists($this->activityType, self::TYPES) ? $this->activityType : 'other',
            'is_all_day' => $this->allDay,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'description' => trim($this->description) === '' ? null : trim($this->description),
            'participant_staff_ids' => array_keys($this->participants),
        ];
        if ($this->publicId === null) {
            $body['project_id'] = $this->projectId === '' ? null : $this->projectId;
        }
        try {
            if ($this->publicId === null) {
                $this->api()->send('POST', '/schedule-entries', json: $body);
                session()->flash('status', 'Schedule entry added.');
            } else {
                $this->api()->send('PATCH', '/schedule-entries/'.rawurlencode($this->publicId), json: $body);
                session()->flash('status', 'Changes saved.');
            }
            $this->redirect(route('admin.schedule'), navigate: true);
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [
                'title' => 'title', 'activity_type' => 'activityType', 'starts_at' => 'startDate', 'ends_at' => 'endDate',
                'description' => 'description', 'project_id' => 'projectId', 'participant_staff_ids' => 'staffSearch',
            ], "Couldn't save the entry. Please try again.");
        }
    }

    public function delete(): void
    {
        if ($this->publicId === null) {
            return;
        }
        $this->generalError = null;
        try {
            $this->api()->send('DELETE', '/schedule-entries/'.rawurlencode($this->publicId));
            session()->flash('status', 'Schedule entry deleted.');
            $this->redirect(route('admin.schedule'), navigate: true);
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't delete the entry. Please try again.");
        }
    }

    public function render(): View
    {
        $projects = [];
        if ($this->publicId === null && ! $this->notFound && $this->errorMessage === null) {
            try {
                $projects = $this->api()->get('/projects', ['per_page' => 100])['data'] ?? [];
            } catch (PortalApiException) {
                $projects = [];
            }
        }

        return view('livewire.admin.schedule-entry-form', ['projects' => $projects, 'people' => $this->staffMatches()]);
    }
}
