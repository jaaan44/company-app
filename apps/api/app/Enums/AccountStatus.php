<?php

namespace App\Enums;

/**
 * Authentication account state (Phase 4). Deliberately minimal — see
 * docs/05_SECURITY_MODEL.md §Account States and DEC-022.
 */
enum AccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Inactive = 'inactive';

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }
}
