<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WalletTransaction;

class WalletTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WalletTransaction $transaction): bool
    {
        return $user->isAdmin() || $transaction->wallet->user_id === $user->id;
    }
}
