<?php

namespace App\Support\Portal;

use App\Services\Portal\InternalApi;
use App\Services\Portal\PortalApiException;

/**
 * Counts the staff portal's navigation shows (Phase 37B, DEC-068). Read
 * through the API like every portal screen; a person the API refuses (no
 * staff profile) simply gets no badge.
 */
final class Badges
{
    private function __construct() {}

    /** Unread messages for the Messages tab, or null to show none. */
    public static function unreadMessages(): ?int
    {
        try {
            $count = app(InternalApi::class)->get('/conversations/unread-count')['data']['unread_count'] ?? null;
        } catch (PortalApiException) {
            return null;
        }

        return is_int($count) && $count > 0 ? $count : null;
    }
}
