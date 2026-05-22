<?php

namespace App\Enums;

enum TopUpRequestStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => in_array($target, [self::Confirmed, self::Rejected], true),
            self::Confirmed, self::Rejected => false,
        };
    }
}
