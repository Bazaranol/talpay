<?php

namespace App\Actions\Wallet;

use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RejectRefundRequest
{
    public function execute(RefundRequest $request, User $admin, ?string $reason = null): RefundRequest
    {
        return DB::transaction(function () use ($request, $admin, $reason): RefundRequest {
            $locked = RefundRequest::query()->lockForUpdate()->findOrFail($request->id);
            $locked->reject($admin, $reason);

            return $locked;
        });
    }
}
