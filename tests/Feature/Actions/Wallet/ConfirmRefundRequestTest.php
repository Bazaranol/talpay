<?php

use App\Actions\Wallet\ConfirmRefundRequest;
use App\Actions\Wallet\CreateRefundRequest;
use App\Actions\Wallet\DebitWallet;
use App\Enums\RefundRequestStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Exceptions\RefundRequestAlreadyProcessedException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
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

    $this->refundRequest = (new CreateRefundRequest)->execute($this->debit, 2_000, 'Overcharge');
    $this->action = new ConfirmRefundRequest;
});

it('confirms the request, creates a refund transaction, and restores balance', function () {
    $confirmed = $this->action->execute($this->refundRequest, $this->admin);

    expect($confirmed->status)->toBe(RefundRequestStatus::Confirmed)
        ->and($confirmed->confirmed_by)->toBe($this->admin->id)
        ->and($confirmed->confirmed_at)->not->toBeNull();

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(7_000); // 5000 - 5000 + 2000 = ...wait, initial was 10000 - 5000 = 5000, then +2000 = 7000

    $refundTx = WalletTransaction::where('type', TransactionType::Refund->value)->firstOrFail();
    expect($refundTx->amount)->toBe(2_000)
        ->and($refundTx->balance_after)->toBe(7_000)
        ->and($refundTx->reference_id)->toBe($this->debit->id)
        ->and($refundTx->created_by)->toBe($this->admin->id);
});

it('copies the request reason to the refund transaction description', function () {
    $this->action->execute($this->refundRequest, $this->admin);

    $refundTx = WalletTransaction::where('type', TransactionType::Refund->value)->firstOrFail();
    expect($refundTx->description)->toBe('Overcharge');
});

it('throws RefundRequestAlreadyProcessedException when confirming twice', function () {
    $this->action->execute($this->refundRequest, $this->admin);

    expect(fn () => $this->action->execute($this->refundRequest, $this->admin))
        ->toThrow(RefundRequestAlreadyProcessedException::class);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(7_000)
        ->and(WalletTransaction::where('type', TransactionType::Refund->value)->count())->toBe(1);
});

it('throws RefundRequestAlreadyProcessedException when confirming a rejected request', function () {
    $this->refundRequest->reject($this->admin);

    expect(fn () => $this->action->execute($this->refundRequest, $this->admin))
        ->toThrow(RefundRequestAlreadyProcessedException::class);

    expect(WalletTransaction::where('type', TransactionType::Refund->value)->count())->toBe(0);
});
