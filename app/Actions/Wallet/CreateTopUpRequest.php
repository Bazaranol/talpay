<?php

namespace App\Actions\Wallet;

use App\Exceptions\InvalidAmountException;
use App\Models\TopUpRequest;
use App\Models\Wallet;

class CreateTopUpRequest
{
    public function execute(
        Wallet $wallet,
        int $amountMinor,
        ?string $comment = null,
        ?string $idempotencyKey = null,
    ): TopUpRequest {
        if ($amountMinor <= 0) {
            throw new InvalidAmountException('Amount must be greater than zero.');
        }

        if ($idempotencyKey !== null) {
            $existing = TopUpRequest::where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return TopUpRequest::create([
            'wallet_id' => $wallet->id,
            'currency' => $wallet->currency,
            'amount' => $amountMinor,
            'comment' => $comment,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
