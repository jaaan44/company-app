<?php

namespace App\Http\Requests\Scheduling;

use App\Enums\ScheduleEntryActivityType;
use App\Models\ScheduleEntry;
use App\Support\Scheduling\ScheduleEntryTiming;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateScheduleEntryRequest extends FormRequest
{
    /**
     * Manage authority (creator, Project Lead of the linked Project, or
     * Administrator) depends on the resolved entry, so it happens in
     * ScheduleEntryController after validation — mirroring
     * UpdateTaskRequest.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A Schedule Entry's Project link is fixed at creation,
            // mirroring Task's identical immutability (docs/phases/
            // V1_PHASE_11_DEFINITION.md precedent) — moving an entry
            // between Projects would re-open the "which Project Lead's
            // authority applies" question mid-request; reject explicitly
            // rather than silently ignoring it.
            'project_id' => ['prohibited'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'activity_type' => ['sometimes', new Enum(ScheduleEntryActivityType::class)],
            'is_all_day' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'required', 'string'],
            'ends_at' => ['sometimes', 'required', 'string'],
            'participant_staff_ids' => ['sometimes', 'array'],
            'participant_staff_ids.*' => ['string', 'distinct', Rule::exists('staff', 'public_id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateTiming($validator);
        });
    }

    /**
     * Compares the *effective* is_all_day/starts_at/ends_at — whichever
     * this request supplies, falling back to the existing record's value
     * for whichever it doesn't — mirroring UpdateProjectRequest's
     * identical partial-update reasoning, so that e.g. updating only
     * `ends_at` on an existing all-day entry is still validated as an
     * all-day date, not a full datetime.
     */
    private function validateTiming(Validator $validator): void
    {
        /** @var ScheduleEntry $entry */
        $entry = $this->route('scheduleEntry');

        $isAllDay = $this->has('is_all_day') ? $this->boolean('is_all_day') : $entry->is_all_day;

        $startsInput = $this->filled('starts_at')
            ? $this->input('starts_at')
            : ($isAllDay ? $entry->starts_at->toDateString() : $entry->starts_at->toIso8601String());

        $endsInput = $this->filled('ends_at')
            ? $this->input('ends_at')
            : ($isAllDay ? $entry->ends_at->toDateString() : $entry->ends_at->toIso8601String());

        $resolved = ScheduleEntryTiming::resolve($startsInput, $endsInput, $isAllDay);

        if ($resolved === null) {
            $format = $isAllDay ? 'a date (Y-m-d)' : 'a valid date-time';
            $validator->errors()->add('starts_at', "starts_at and ends_at must each be {$format}.");

            return;
        }

        if ($resolved['ends_at']->lt($resolved['starts_at'])) {
            $validator->errors()->add('ends_at', 'The end must be on or after the start.');
        }
    }
}
