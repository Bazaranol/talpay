<?php

use App\Actions\Wallet\CreateRefundRequest;
use App\Actions\Wallet\DebitWallet;
use App\Enums\RefundRequestStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidAmountException;
use App\Exceptions\RefundExceedsDebitException;
use App\Models\RefundRequest;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->wallet = Wallet::createForUser(User::factory()->create(['role' => UserRole::Client]));
    DB::table('wallets')->where('id', $this->wallet->id)->update(['balance' => 10_000]);
    $this->wallet->refresh();

    $this->debit = (new DebitWallet)->execute($this->wallet, 5_000, 'Test debit', $this->admin);
    $this->wallet->refresh();

    $this->action = new CreateRefundRequest;
});

it('creates a pending refund request for a debit transaction', function () {
    $request = $this->action->execute($this->debit, 2_000, 'Overcharge');

    expect($request)->not->toBeNull()
        ->and($request->wallet_transaction_id)->toBe($this->debit->id)
        ->and($request->amount)->toBe(2_000)
        ->and($request->reason)->toBe('Overcharge')
        ->and($request->status)->toBe(RefundRequestStatus::Pending)
        ->and($request->currency)->toBe($this->wallet->currency);
});

it('returns existing request on duplicate idempotency key', function () {
    $first = $this->action->execute($this->debit, 1_000, null, 'idem-key-1');
    $second = $this->action->execute($this->debit, 1_000, null, 'idem-key-1');

    expect($second->id)->toBe($first->id)
        ->and(RefundRequest::count())->toBe(1);
});

it('throws InvalidAmountException for zero or negative amount', function () {
    expect(fn () => $this->action->execute($this->debit, 0))
        ->toThrow(InvalidAmountException::class);

    expect(fn () => $this->action->execute($this->debit, -100))
        ->toThrow(InvalidAmountException::class);
});

it('throws RefundExceedsDebitException when requested amount exceeds debit', function () {
    expect(fn () => $this->action->execute($this->debit, 6_000))
        ->toThrow(RefundExceedsDebitException::class);

    expect(RefundRequest::count())->toBe(0);
});

it('throws RefundExceedsDebitException when pending requests already cover full amount', function () {
    $this->action->execute($this->debit, 3_000);
    $this->action->execute($this->debit, 2_000);

    expect(fn () => $this->action->execute($this->debit, 1))
        ->toThrow(RefundExceedsDebitException::class);

    expect(RefundRequest::count())->toBe(2);
});

it('throws InvalidAmountException when trying to create request for a non-debit transaction', function () {
    $topUpTx = \App\Models\WalletTransaction::where('type', \App\Enums\TransactionType::TopUp->value)->first();

    if ($topUpTx === null) {
        $req = (new \App\Actions\Wallet\CreateTopUpRequest)->execute($this->wallet, 1_000);
        (new \App\Actions\Wallet\ConfirmTopUpRequest)->execute($req, $this->admin);
        $topUpTx = \App\Models\WalletTransaction::where('type', \App\Enums\TransactionType::TopUp->value)->firstOrFail();
    }

    expect(fn () => $this->action->execute($topUpTx, 500))
        ->toThrow(InvalidAmountException::class);
});
