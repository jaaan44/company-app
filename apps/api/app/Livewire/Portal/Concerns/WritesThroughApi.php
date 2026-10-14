<?php

namespace App\Livewire\Portal\Concerns;

use App\Services\Portal\PortalApiException;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 35D: the add / edit / delete forms. Whether to *show* an action
 * follows the signed-in user's permissions (`may()`), but the API decides
 * every action: its 422 field messages land on the form's fields, and its
 * 403 / 404 / 409 messages show above the form.
 */
trait WritesThroughApi
{
    public ?string $generalError = null;

    protected function may(string $permission): bool
    {
        return Auth::user()?->can($permission) ?? false;
    }

    /**
     * @param  array<string, string>  $fields  API field => component property
     */
    protected function applyWriteFailure(PortalApiException $e, array $fields, string $fallback): void
    {
        if ($e->isSessionExpired()) {
            $this->failed($e, '');

            return;
        }

        if ($e->status === 422 && $e->errors !== []) {
            $unmatched = [];
            foreach ($e->errors as $field => $messages) {
                $property = $fields[preg_replace('/\.\d+$/', '', $field)] ?? null;
                if ($property !== null) {
                    $this->addError($property, $messages[0] ?? $fallback);
                } else {
                    $unmatched[] = $messages[0] ?? '';
                }
            }
            if (array_filter($unmatched) !== []) {
                $this->generalError = implode(' ', array_filter($unmatched));
            }

            return;
        }

        $this->generalError = match (true) {
            $e->isNotFound() => 'This is no longer available.',
            $e->getMessage() !== '' && ($e->isForbidden() || $e->status === 409 || $e->status === 422) => $e->getMessage(),
            $e->isForbidden() => "You don't have permission to do this.",
            default => $fallback,
        };
    }
}
