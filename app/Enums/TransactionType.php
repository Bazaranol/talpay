<?php

namespace App\Enums;

enum TransactionType: string
{
    case TopUp = 'top_up';
    case Debit = 'debit';
    case Refund = 'refund';
}
