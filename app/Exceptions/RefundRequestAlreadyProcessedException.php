<?php

namespace App\Exceptions;

use App\Enums\RefundRequestStatus;

class RefundRequestAlreadyProcessedException extends \RuntimeException
{
    public function __construct(
        public readonly int $refundRequestId,
        public readonly RefundRequestStatus $currentStatus,
    ) {
        parent::__construct(
            "Refund request #{$refundRequestId} is already {$currentStatus->value}."
        );
    }
}
