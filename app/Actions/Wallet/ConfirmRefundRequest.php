<?php

namespace App\Actions\Wallet;

use App\Models\RefundRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class ConfirmRefundRequest
{
    public function execute(RefundRequest $request, User $admin): RefundRequest
    {
        return DB::transaction(function () use ($request, $admin): RefundRequest {
            // Lock order: RefundRequest → WalletTransaction (debit) → Wallet (inside RefundDebit)
            $lockedRequest = RefundRequest::query()->lockForUpdate()->findOrFail($request->id);

            $lockedRequest->confirm($admin);

            $debit = WalletTransaction::query()->findOrFail($lockedRequest->wallet_transaction_id);

            (new RefundDebit)->execute(
                $debit,
                $lockedRequest->amount,
                $admin,
                $lockedRequest->reason,
            );

            return $lockedRequest;
        });
    }
}
