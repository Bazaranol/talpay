<?php

namespace App\Actions\Wallet;

use App\Enums\TransactionType;
use App\Exceptions\InvalidAmountException;
use App\Exceptions\RefundExceedsDebitException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class RefundDebit
{
    public function execute(
        WalletTransaction $debit,
        int $refundAmountMinor,
        User $admin,
        ?string $description = null,
    ): WalletTransaction {
        return DB::transaction(function () use ($debit, $refundAmountMinor, $admin, $description): WalletTransaction {
            if ($refundAmountMinor <= 0) {
                throw new InvalidAmountException('Amount must be greater than zero.');
            }

            $lockedDebit = WalletTransaction::query()->lockForUpdate()->findOrFail($debit->id);

            if ($lockedDebit->type !== TransactionType::Debit) {
                throw new InvalidAmountException('Refund can only be applied to debit transactions.');
            }

            // PostgreSQL does not allow FOR UPDATE with aggregate functions, so we
            // lock the rows via get() and sum amounts in PHP via Collection::sum().
            $alreadyRefunded = WalletTransaction::query()
                ->select(['id', 'amount'])
                ->where('reference_type', WalletTransaction::class)
                ->where('reference_id', $lockedDebit->id)
                ->where('type', TransactionType::Refund)
                ->lockForUpdate()
                ->get()
                ->sum('amount');

            if ($alreadyRefunded + $refundAmountMinor > $lockedDebit->amount) {
                throw new RefundExceedsDebitException(
                    $lockedDebit->id,
                    $refundAmountMinor,
                    $alreadyRefunded,
                    $lockedDebit->amount,
                );
            }

            $wallet = Wallet::query()->lockForUpdate()->findOrFail($lockedDebit->wallet_id);

            $wallet->balance += $refundAmountMinor;
            $wallet->save();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'currency' => $wallet->currency,
                'type' => TransactionType::Refund,
                'amount' => $refundAmountMinor,
                'balance_after' => $wallet->balance,
                'reference_id' => $lockedDebit->id,
                'reference_type' => $lockedDebit->getMorphClass(),
                'description' => $description,
                'created_by' => $admin->id,
            ]);
        });
    }
}
