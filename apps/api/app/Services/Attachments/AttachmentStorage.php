<?php

namespace App\Services\Attachments;

use App\Support\Attachments\AttachmentDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Physically stores and removes attachment files (Phase 18 — Service
 * Reports, DEC-041) — the one place that generates a storage path and
 * writes/deletes bytes through App\Support\Attachments\AttachmentDisk.
 * Never trusts an uploaded file's original filename as the stored
 * filename (a client-supplied name could collide, contain path-traversal
 * characters, or leak into a predictable path) — every stored file gets
 * a fresh ULID-based name, with the original name preserved only as
 * display metadata on the Attachment row.
 */
final class AttachmentStorage
{
    /**
     * Stores $file under a directory scoped to the owning record's
     * public_id and returns the relative path actually written — the
     * caller persists this on the Attachment row, never the original
     * filename. $ownerTypeSegment namespaces the owning module's own
     * directory ('service-reports' by default, unchanged from Phase 18;
     * Phase 19 passes 'incident-reports') — every existing Service
     * Report attachment call site and stored path is unaffected.
     */
    public function store(UploadedFile $file, string $ownerDirectory, string $ownerTypeSegment = 'service-reports'): string
    {
        $filename = (string) Str::ulid();

        if ($extension = $file->getClientOriginalExtension()) {
            $filename .= '.'.Str::lower($extension);
        }

        $directory = "{$ownerTypeSegment}/{$ownerDirectory}";

        $path = $file->storeAs($directory, $filename, ['disk' => AttachmentDisk::name()]);

        if ($path === false) {
            abort(500, 'The attachment could not be stored.');
        }

        return $path;
    }

    /**
     * Best-effort physical file deletion. Deleting an already-missing
     * file is a no-op (Laravel's local/S3 drivers both return false
     * rather than throwing) — never blocks removal of the owning
     * Attachment/Service Report record merely because the underlying
     * file was already gone.
     */
    public function delete(string $path): void
    {
        AttachmentDisk::filesystem()->delete($path);
    }
}
