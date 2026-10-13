<?php

namespace App\Services\Portal;

use RuntimeException;

/**
 * A non-2xx answer from an in-process API call (InternalApi). Mirrors the
 * mobile `ApiClient`'s outcomes (Phase 27, DEC-052): 401 ends the
 * session, 403 is forbidden (or the no-profile state on `/me/*`), 404 is
 * gone, 409 and 422 carry the server's own words, 413 is a too-large
 * upload, anything else is a server failure.
 */
final class PortalApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $status);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(int $status, array $body): self
    {
        $message = is_string($body['message'] ?? null) ? $body['message'] : '';
        $errors = [];
        foreach (is_array($body['errors'] ?? null) ? $body['errors'] : [] as $field => $messages) {
            $errors[(string) $field] = array_values(array_filter((array) $messages, 'is_string'));
        }

        return new self($status, $message, $errors);
    }

    public function isSessionExpired(): bool
    {
        return $this->status === 401;
    }

    public function isForbidden(): bool
    {
        return $this->status === 403;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /** The first message for a field, or null. */
    public function firstErrorFor(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }
}
