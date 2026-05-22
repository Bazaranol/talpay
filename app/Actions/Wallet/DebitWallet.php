<?php

namespace App\Actions\Wallet;

use App\Enums\TransactionType;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\InvalidAmountException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class DebitWallet
{
    public function execute(
        Wallet $wallet,
        int $amountMinor,
        string $description,
        User $admin,
    ): WalletTransaction {
        return DB::transaction(function () use ($wallet, $amountMinor, $description, $admin) {
            if ($amountMinor <= 0) {
                throw new InvalidAmountException('Amount must be greater than zero.');
            }

            if (trim($description) === '') {
                throw new InvalidAmountException('Description cannot be empty.');
            }

            $lockedWallet = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);

            if ($lockedWallet->balance < $amountMinor) {
                throw new InsufficientFundsException(
                    $lockedWallet->id,
                    $amountMinor,
                    $lockedWallet->balance,
                );
            }

            $lockedWallet->balance -= $amountMinor;
            $lockedWallet->save();

            return WalletTransaction::create([
                'wallet_id' => $lockedWallet->id,
                'currency' => $lockedWallet->currency,
                'type' => TransactionType::Debit,
                'amount' => $amountMinor,
                'balance_after' => $lockedWallet->balance,
                'description' => $description,
                'created_by' => $admin->id,
            ]);
        });
    }
}
