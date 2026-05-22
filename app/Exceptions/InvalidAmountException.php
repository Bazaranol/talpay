<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidAmountException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct("Invalid amount: {$reason}");
    }
}
