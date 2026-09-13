<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Attachment Storage Disk
    |--------------------------------------------------------------------------
    |
    | Phase 18 (Service Reports, DEC-041) introduces the first file-upload
    | capability in this application; Phase 19 (Incident Reports,
    | DEC-042) is the second authorized consumer of the same
    | infrastructure. Every attachment is stored through Laravel's
    | filesystem abstraction on this single named disk — never
    | referenced directly as 'local'/'s3' elsewhere in application code
    | (see App\Support\Attachments\AttachmentDisk, the single point of
    | access, mirroring App\Support\CompanyTimezone's Phase 17 precedent).
    |
    | V1/local/test default: the framework's own private 'local' disk
    | (config/filesystems.php — storage_path('app/private'), not
    | web-served, `serve` disabled for this purpose). Files are never
    | directly public — every download passes through an authenticated,
    | authorized application route
    | (ServiceReportAttachmentController::download()), never a public URL
    | (05_SECURITY_MODEL.md's File Uploads section).
    |
    | Production target remains S3-compatible object storage
    | (02_ARCHITECTURE.md §7's long-open object storage question) —
    | switching this single value to an 's3'-driver disk (already defined
    | in config/filesystems.php) is the entire migration; no Service
    | Report/Attachment code references a disk name directly.
    |
    */

    'disk' => env('ATTACHMENTS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Upload Limits
    |--------------------------------------------------------------------------
    |
    | A conservative V1 allowlist appropriate for service-report evidence
    | (photos of completed work, a signed paper form scanned to PDF) —
    | not a general-purpose document management system. Enforced by
    | StoreServiceReportAttachmentRequest via Laravel's built-in 'mimes'
    | rule (content-sniffed via fileinfo, not merely the file extension)
    | plus an explicit MIME allowlist re-check in
    | ServiceReportAttachmentController for defense in depth. Antivirus/
    | malware scanning is explicitly not a V1 commitment
    | (05_SECURITY_MODEL.md).
    |
    */

    'max_size_kb' => (int) env('ATTACHMENTS_MAX_SIZE_KB', 10240), // 10 MB

    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf'],

    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'application/pdf',
    ],

];
