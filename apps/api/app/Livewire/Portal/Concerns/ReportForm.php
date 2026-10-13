<?php

namespace App\Livewire\Portal\Concerns;

use App\Services\Portal\PortalApiException;

/**
 * Shared by the two report forms (Phase 34E): the mobile form's text
 * checks (Phase 33, `_check`) and the mapping of the API's refusal onto
 * the same fields — `422` per field, anything else at the top.
 */
trait ReportForm
{
    public const MAX_LONG = 10000;

    public const MAX_TEXT = 5000;

    public const MAX_SHORT = 255;

    public ?string $generalError = null;

    protected function checkText(string $field, string $value, int $max, bool $required, string $requiredMessage): void
    {
        $text = trim($value);
        if ($required && $text === '') {
            $this->addError($field, $requiredMessage);
        } elseif (mb_strlen($text) > $max) {
            $this->addError($field, 'Use at most '.$max.' characters.');
        }
    }

    protected function nullable(string $value): ?string
    {
        $text = trim($value);

        return $text === '' ? null : $text;
    }

    /** @param  array<string, string>  $map  API field → form property */
    protected function refused(PortalApiException $e, array $map): void
    {
        if ($e->isSessionExpired()) {
            $this->failed($e, '');

            return;
        }
        if ($e->status === 422 && $e->errors !== []) {
            foreach ($e->errors as $field => $messages) {
                if ($messages === []) {
                    continue;
                }
                if (isset($map[$field])) {
                    $this->addError($map[$field], $messages[0]);
                } else {
                    $this->generalError ??= $messages[0];
                }
            }

            return;
        }
        $this->generalError = in_array($e->status, [403, 404, 409, 422], true) && $e->getMessage() !== ''
            ? $e->getMessage()
            : "Couldn't save. Please try again.";
    }
}
