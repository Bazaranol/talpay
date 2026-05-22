<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Wallet;

class WalletPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Wallet $wallet): bool
    {
        return $user->isAdmin() || $wallet->user_id === $user->id;
    }
}
