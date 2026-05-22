<?php

namespace App\Actions\Wallet;

use App\Enums\RefundRequestStatus;
use App\Enums\TransactionType;
use App\Exceptions\InvalidAmountException;
use App\Exceptions\RefundExceedsDebitException;
use App\Models\RefundRequest;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class CreateRefundRequest
{
    public function execute(
        WalletTransaction $debit,
        int $amountMinor,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): RefundRequest {
        if ($amountMinor <= 0) {
            throw new InvalidAmountException('Amount must be greater than zero.');
        }

        if ($idempotencyKey !== null) {
            $existing = RefundRequest::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($debit, $amountMinor, $reason, $idempotencyKey): RefundRequest {
            $lockedDebit = WalletTransaction::query()->lockForUpdate()->findOrFail($debit->id);

            if ($lockedDebit->type !== TransactionType::Debit) {
                throw new InvalidAmountException('Refund request can only be created for debit transactions.');
            }

            // PostgreSQL does not allow FOR UPDATE with aggregate functions, so we
            // lock the rows via get() and sum amounts in PHP via Collection::sum().
            $confirmedRefunds = WalletTransaction::query()
                ->select(['id', 'amount'])
                ->where('reference_type', WalletTransaction::class)
                ->where('reference_id', $lockedDebit->id)
                ->where('type', TransactionType::Refund)
                ->lockForUpdate()
                ->get()
                ->sum('amount');

            $pendingRequests = RefundRequest::query()
                ->select(['id', 'amount'])
                ->where('wallet_transaction_id', $lockedDebit->id)
                ->where('status', RefundRequestStatus::Pending)
                ->lockForUpdate()
                ->get()
                ->sum('amount');

            $committed = $confirmedRefunds + $pendingRequests;

            if ($committed + $amountMinor > $lockedDebit->amount) {
                throw new RefundExceedsDebitException(
                    $lockedDebit->id,
                    $amountMinor,
                    $committed,
                    $lockedDebit->amount,
                );
            }

            return RefundRequest::create([
                'wallet_transaction_id' => $lockedDebit->id,
                'currency' => $lockedDebit->currency,
                'amount' => $amountMinor,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }
}
