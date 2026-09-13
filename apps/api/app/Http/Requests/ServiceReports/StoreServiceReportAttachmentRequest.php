<?php

namespace App\Http\Requests\ServiceReports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Uploads a single file attachment to a Service Report. Draft-only
 * mutation authority and the report's editability are enforced in
 * ServiceReportAttachmentController::store(), not here — this Form
 * Request validates only the uploaded file itself. The 'mimes' rule
 * content-sniffs the file (via PHP's fileinfo extension), it does not
 * merely trust the client-supplied extension or Content-Type header; see
 * config/attachments.php for the exact allowlist/size this mirrors and
 * ServiceReportAttachmentController for the additional defense-in-depth
 * MIME re-check.
 */
class StoreServiceReportAttachmentRequest extends FormRequest
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
