<?php

namespace App\Actions\Wallet;

use App\Enums\TransactionType;
use App\Models\TopUpRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class ConfirmTopUpRequest
{
    public function execute(TopUpRequest $request, User $admin): TopUpRequest
    {
        return DB::transaction(function () use ($request, $admin): TopUpRequest {
            $lockedRequest = TopUpRequest::query()->lockForUpdate()->findOrFail($request->id);

            $lockedRequest->confirm($admin);

            $wallet = Wallet::query()->lockForUpdate()->findOrFail($lockedRequest->wallet_id);

            $gross = $lockedRequest->amount;
            $commission = $wallet->calculateCommission($gross);
            $net = $gross - $commission;

            $lockedRequest->commission_amount = $commission;
            $lockedRequest->save();

            $wallet->balance += $net;
            $wallet->save();

            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'currency' => $wallet->currency,
                'type' => TransactionType::TopUp,
                'amount' => $net,
                'commission_amount' => $commission,
                'balance_after' => $wallet->balance,
                'reference_id' => $lockedRequest->id,
                'reference_type' => $lockedRequest->getMorphClass(),
                'description' => $lockedRequest->comment,
                'created_by' => $admin->id,
            ]);

            return $lockedRequest;
        });
    }
}
