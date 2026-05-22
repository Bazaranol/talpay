<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientFundsException extends RuntimeException
{
    public function __construct(
        public readonly int $walletId,
        public readonly int $requestedAmount,
        public readonly int $availableBalance,
    ) {
        parent::__construct(
            "Wallet #{$walletId} has insufficient funds: requested {$requestedAmount}, available {$availableBalance}."
        );
    }
}
