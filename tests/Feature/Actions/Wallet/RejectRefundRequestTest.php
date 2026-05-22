<?php

use App\Actions\Wallet\CreateRefundRequest;
use App\Actions\Wallet\DebitWallet;
use App\Actions\Wallet\RejectRefundRequest;
use App\Enums\RefundRequestStatus;
use App\Enums\UserRole;
use App\Exceptions\RefundRequestAlreadyProcessedException;
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

    $debit = (new DebitWallet)->execute($this->wallet, 5_000, 'Test debit', $this->admin);
    $this->wallet->refresh();

    $this->refundRequest = (new CreateRefundRequest)->execute($debit, 2_000);
    $this->action = new RejectRefundRequest;
});

it('rejects a pending request and stores the reason', function () {
    $rejected = $this->action->execute($this->refundRequest, $this->admin, 'Not eligible');

    expect($rejected->status)->toBe(RefundRequestStatus::Rejected)
        ->and($rejected->rejected_by)->toBe($this->admin->id)
        ->and($rejected->rejected_at)->not->toBeNull()
        ->and($rejected->rejection_reason)->toBe('Not eligible');
});

it('rejects without a reason', function () {
    $rejected = $this->action->execute($this->refundRequest, $this->admin);

    expect($rejected->status)->toBe(RefundRequestStatus::Rejected)
        ->and($rejected->rejection_reason)->toBeNull();
});

it('throws RefundRequestAlreadyProcessedException when rejecting twice', function () {
    $this->action->execute($this->refundRequest, $this->admin);

    expect(fn () => $this->action->execute($this->refundRequest, $this->admin))
        ->toThrow(RefundRequestAlreadyProcessedException::class);
});

it('throws RefundRequestAlreadyProcessedException when rejecting a confirmed request', function () {
    $this->refundRequest->confirm($this->admin);

    expect(fn () => $this->action->execute($this->refundRequest, $this->admin))
        ->toThrow(RefundRequestAlreadyProcessedException::class);
});

it('does not alter the wallet balance', function () {
    $balanceBefore = $this->wallet->balance;

    $this->action->execute($this->refundRequest, $this->admin);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe($balanceBefore);
});
