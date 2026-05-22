<?php

namespace App\Exceptions;

use App\Enums\TopUpRequestStatus;
use RuntimeException;

class TopUpAlreadyProcessedException extends RuntimeException
{
    public function __construct(
        public readonly int $requestId,
        public readonly TopUpRequestStatus $currentStatus,
    ) {
        parent::__construct(
            "Top-up request #{$requestId} is already {$currentStatus->value} and cannot be processed again."
        );
    }
}
