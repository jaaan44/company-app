<?php

namespace App\Http\Requests\IncidentReports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Uploads a single file attachment to an Incident Report (Phase 19, the
 * second authorized consumer of the shared attachment infrastructure,
 * DEC-042). Mirrors StoreServiceReportAttachmentRequest exactly (Phase
 * 18) — the same allowlist/size cap, the same "authority and state live
 * in the controller" split.
 */
class StoreIncidentReportAttachmentRequest extends FormRequest
{
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
            'file' => [
                'required',
                'file',
                'max:'.config('attachments.max_size_kb'),
                'mimes:'.implode(',', config('attachments.allowed_extensions')),
            ],
        ];
    }
}
