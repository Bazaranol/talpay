<?php

namespace App\Policies;

use App\Models\RefundRequest;
use App\Models\User;

class RefundRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RefundRequest $refundRequest): bool
    {
        return $user->isAdmin() || $refundRequest->debit->wallet->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isClient();
    }

    public function update(User $user): bool
    {
        return $user->isAdmin();
    }
}
