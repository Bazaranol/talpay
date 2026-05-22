<?php

use App\Actions\Wallet\ConfirmTopUpRequest;
use App\Actions\Wallet\CreateTopUpRequest;
use App\Enums\TopUpRequestStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Exceptions\TopUpAlreadyProcessedException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->wallet = Wallet::createForUser(User::factory()->create(['role' => UserRole::Client]));
    $this->wallet->update(['commission_rate_bps' => 0]);
    $this->request = (new CreateTopUpRequest)->execute($this->wallet, 10_000);
    $this->action = new ConfirmTopUpRequest;
});

it('confirms a pending request, increases balance and creates a transaction (zero commission)', function () {
    $confirmed = $this->action->execute($this->request, $this->admin);

    expect($confirmed->status)->toBe(TopUpRequestStatus::Confirmed)
        ->and($confirmed->confirmed_by)->toBe($this->admin->id)
        ->and($confirmed->confirmed_at)->not->toBeNull()
        ->and($confirmed->commission_amount)->toBe(0);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(10_000);

    $tx = WalletTransaction::first();
    expect($tx)->not->toBeNull()
        ->and($tx->wallet_id)->toBe($this->wallet->id)
        ->and($tx->type)->toBe(TransactionType::TopUp)
        ->and($tx->amount)->toBe(10_000)
        ->and($tx->commission_amount)->toBe(0)
        ->and($tx->balance_after)->toBe(10_000)
        ->and($tx->created_by)->toBe($this->admin->id);
});

it('throws TopUpAlreadyProcessedException and leaves balance unchanged when confirming twice', function () {
    $this->action->execute($this->request, $this->admin);

    $this->wallet->refresh();
    $balanceAfterFirst = $this->wallet->balance;

    expect(fn () => $this->action->execute($this->request, $this->admin))
        ->toThrow(TopUpAlreadyProcessedException::class);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe($balanceAfterFirst)
        ->and(WalletTransaction::count())->toBe(1);
});

it('copies the request comment to the transaction description', function () {
    $request = (new CreateTopUpRequest)->execute($this->wallet, 5_000, 'Зарплата за май');
    $this->action->execute($request, $this->admin);
    $tx = WalletTransaction::first();
    expect($tx->description)->toBe('Зарплата за май');
});

it('throws TopUpAlreadyProcessedException when confirming a rejected request', function () {
    $this->request->reject($this->admin);

    expect(fn () => $this->action->execute($this->request, $this->admin))
        ->toThrow(TopUpAlreadyProcessedException::class);
});

it('deducts commission from confirmed top-up and records it on transaction', function () {
    $this->wallet->update(['commission_rate_bps' => 500]); // 5%
    $request = (new CreateTopUpRequest)->execute($this->wallet, 10_000);
    $this->action->execute($request, $this->admin);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(9_500);

    $tx = WalletTransaction::first();
    expect($tx->amount)->toBe(9_500)
        ->and($tx->commission_amount)->toBe(500)
        ->and($tx->balance_after)->toBe(9_500);

    $request->refresh();
    expect($request->commission_amount)->toBe(500);
});

it('records zero commission when rate is zero', function () {
    $request = (new CreateTopUpRequest)->execute($this->wallet, 5_000);
    $this->action->execute($request, $this->admin);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(5_000);

    $tx = WalletTransaction::first();
    expect($tx->amount)->toBe(5_000)
        ->and($tx->commission_amount)->toBe(0);

    $request->refresh();
    expect($request->commission_amount)->toBe(0);
});

it('caps commission at 100% so balance can never decrease on top-up', function () {
    // Defense-in-depth: даже если кто-то в обход формы выставит 150%,
    // calculateCommission ограничит ставку 10000 bps, нетто не станет отрицательным.
    // CHECK constraint в БД — вторая линия защиты.
    DB::table('wallets')->where('id', $this->wallet->id)->update(['commission_rate_bps' => 10_000]);
    $this->wallet->refresh();

    $request = (new CreateTopUpRequest)->execute($this->wallet, 10_000);
    $this->action->execute($request, $this->admin);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(0); // 100% комиссия → net = 0, не отрицательное
});

it('correctly truncates fractional commission (333 bps on 1000 kopeks = 33, net = 967)', function () {
    $this->wallet->update(['commission_rate_bps' => 333]); // 3.33%
    $request = (new CreateTopUpRequest)->execute($this->wallet, 1_000);
    $this->action->execute($request, $this->admin);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(967); // intdiv(1000 * 333, 10000) = 33, net = 967

    $tx = WalletTransaction::first();
    expect($tx->amount)->toBe(967)
        ->and($tx->commission_amount)->toBe(33)
        ->and($tx->balance_after)->toBe(967);
});
