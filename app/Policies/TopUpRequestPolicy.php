<?php

namespace App\Policies;

use App\Models\TopUpRequest;
use App\Models\User;

class TopUpRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TopUpRequest $request): bool
    {
        return $user->isAdmin() || $request->wallet->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isClient();
    }
}
