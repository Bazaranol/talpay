<?php

use App\Actions\Wallet\ConfirmTopUpRequest;
use App\Actions\Wallet\CreateTopUpRequest;
use App\Actions\Wallet\DebitWallet;
use App\Actions\Wallet\RefundDebit;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Exceptions\InvalidAmountException;
use App\Exceptions\RefundExceedsDebitException;
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

    $this->action = new RefundDebit;
});

it('creates a full refund and restores the balance', function () {
    $refund = $this->action->execute($this->debit, 5_000, $this->admin, 'Full refund');

    expect($refund->type)->toBe(TransactionType::Refund)
        ->and($refund->amount)->toBe(5_000)
        ->and($refund->balance_after)->toBe(10_000)
        ->and($refund->reference_id)->toBe($this->debit->id)
        ->and($refund->reference_type)->toBe(WalletTransaction::class)
        ->and($refund->created_by)->toBe($this->admin->id);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(10_000);
});

it('creates a partial refund and increases the balance by the refund amount', function () {
    $refund = $this->action->execute($this->debit, 2_000, $this->admin);

    expect($refund->amount)->toBe(2_000)
        ->and($refund->balance_after)->toBe(7_000);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(7_000);
});

it('allows multiple partial refunds up to the full debit amount', function () {
    $this->action->execute($this->debit, 2_000, $this->admin);
    $this->action->execute($this->debit, 3_000, $this->admin);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(10_000);
    expect(WalletTransaction::where('type', TransactionType::Refund->value)->count())->toBe(2);
});

it('throws RefundExceedsDebitException when refund amount exceeds original debit', function () {
    expect(fn () => $this->action->execute($this->debit, 6_000, $this->admin))
        ->toThrow(RefundExceedsDebitException::class);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(5_000);
    expect(WalletTransaction::where('type', TransactionType::Refund->value)->count())->toBe(0);
});

it('throws RefundExceedsDebitException when the debit has already been fully refunded', function () {
    $this->action->execute($this->debit, 5_000, $this->admin);

    expect(fn () => $this->action->execute($this->debit, 1, $this->admin))
        ->toThrow(RefundExceedsDebitException::class);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(10_000);
});

it('throws InvalidAmountException when trying to refund a top-up transaction', function () {
    $topUpTx = WalletTransaction::where('type', TransactionType::TopUp->value)->first()
        ?? (function () {
            $req = (new CreateTopUpRequest)->execute($this->wallet, 3_000);
            (new ConfirmTopUpRequest)->execute($req, $this->admin);

            return WalletTransaction::where('type', TransactionType::TopUp->value)->firstOrFail();
        })();

    expect(fn () => $this->action->execute($topUpTx, 1_000, $this->admin))
        ->toThrow(InvalidAmountException::class);
});

it('throws InvalidAmountException when trying to refund a refund transaction', function () {
    $refundTx = $this->action->execute($this->debit, 2_000, $this->admin);

    expect(fn () => $this->action->execute($refundTx, 500, $this->admin))
        ->toThrow(InvalidAmountException::class);
});
