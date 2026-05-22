<?php

namespace App\Exceptions;

use RuntimeException;

class RefundExceedsDebitException extends RuntimeException
{
    public function __construct(
        public readonly int $debitTransactionId,
        public readonly int $requestedRefund,
        public readonly int $alreadyRefunded,
        public readonly int $debitAmount,
    ) {
        parent::__construct(
            "Refund of {$requestedRefund} exceeds debit #{$debitTransactionId}: debit amount is {$debitAmount}, already refunded {$alreadyRefunded}."
        );
    }
}
