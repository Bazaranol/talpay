<?php

namespace App\Exceptions;

use RuntimeException;

class WalletTransactionImmutableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Wallet transactions are append-only and cannot be modified.');
    }
}
