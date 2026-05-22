<?php

use App\Actions\Wallet\DebitWallet;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\InvalidAmountException;
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
    $this->action = new DebitWallet;
});

it('debits the wallet and records a transaction', function () {
    $tx = $this->action->execute($this->wallet, 4_000, 'Test debit', $this->admin);

    expect($tx->type)->toBe(TransactionType::Debit)
        ->and($tx->amount)->toBe(4_000)
        ->and($tx->balance_after)->toBe(6_000)
        ->and($tx->created_by)->toBe($this->admin->id);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(6_000);

    expect(WalletTransaction::count())->toBe(1);
});

it('throws InsufficientFundsException and leaves wallet unchanged when balance is too low', function () {
    expect(fn () => $this->action->execute($this->wallet, 20_000, 'Overdraft attempt', $this->admin))
        ->toThrow(InsufficientFundsException::class);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(10_000)
        ->and(WalletTransaction::count())->toBe(0);
});

it('throws InvalidAmountException for zero amount', function () {
    expect(fn () => $this->action->execute($this->wallet, 0, 'Bad amount', $this->admin))
        ->toThrow(InvalidAmountException::class);
});

it('throws InvalidAmountException for empty description', function () {
    expect(fn () => $this->action->execute($this->wallet, 1_000, '   ', $this->admin))
        ->toThrow(InvalidAmountException::class);
});
