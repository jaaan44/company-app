<?php

namespace App\Http\Requests\Scheduling;

use App\Enums\ScheduleEntryActivityType;
use App\Support\Scheduling\ScheduleEntryTiming;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreScheduleEntryRequest extends FormRequest
{
    /**
     * Authorization (creator identity is always the authenticated
     * User's own linked Staff record — see RequiresLinkedStaff) happens
     * in ScheduleEntryController, mirroring every other Form Request in
     * this codebase.
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'activity_type' => ['required', new Enum(ScheduleEntryActivityType::class)],
            'is_all_day' => ['sometimes', 'boolean'],
            // Format depends on is_all_day (a plain Y-m-d date for an
            // all-day entry, a full ISO-8601 datetime otherwise) — see
            // validateTiming() below and App\Support\Scheduling\
            // ScheduleEntryTiming, the single shared implementation.
            'starts_at' => ['required', 'string'],
            'ends_at' => ['required', 'string'],
            'project_id' => ['nullable', 'string', Rule::exists('projects', 'public_id')],
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

    private function validateTiming(Validator $validator): void
    {
        if (! $this->filled('starts_at') || ! $this->filled('ends_at')) {
            return;
        }

        $resolved = ScheduleEntryTiming::resolve(
            $this->input('starts_at'),
            $this->input('ends_at'),
            $this->boolean('is_all_day'),
        );

        if ($resolved === null) {
            $format = $this->boolean('is_all_day') ? 'a date (Y-m-d)' : 'a valid date-time';
            $validator->errors()->add('starts_at', "starts_at and ends_at must each be {$format}.");

            return;
        }

        if ($resolved['ends_at']->lt($resolved['starts_at'])) {
            $validator->errors()->add('ends_at', 'The end must be on or after the start.');
        }
    }
}
