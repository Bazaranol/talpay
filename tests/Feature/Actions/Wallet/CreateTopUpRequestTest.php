<?php

use App\Actions\Wallet\CreateTopUpRequest;
use App\Enums\TopUpRequestStatus;
use App\Exceptions\InvalidAmountException;
use App\Models\TopUpRequest;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->wallet = Wallet::createForUser(User::factory()->create());
    $this->action = new CreateTopUpRequest;
});

it('creates a valid top-up request', function () {
    $request = $this->action->execute($this->wallet, 10_000, 'Test top-up');

    expect($request->id)->toBeInt()
        ->and($request->wallet_id)->toBe($this->wallet->id)
        ->and($request->currency)->toBe(Wallet::DEFAULT_CURRENCY)
        ->and($request->amount)->toBe(10_000)
        ->and($request->status)->toBe(TopUpRequestStatus::Pending)
        ->and($request->comment)->toBe('Test top-up');
});

it('throws InvalidAmountException for zero amount', function () {
    $this->action->execute($this->wallet, 0);
})->throws(InvalidAmountException::class);

it('throws InvalidAmountException for negative amount', function () {
    $this->action->execute($this->wallet, -1);
})->throws(InvalidAmountException::class);

it('returns the same record on duplicate idempotency key', function () {
    $first = $this->action->execute($this->wallet, 10_000, null, 'idem-key-1');
    $second = $this->action->execute($this->wallet, 10_000, null, 'idem-key-1');

    expect($second->id)->toBe($first->id)
        ->and(TopUpRequest::count())->toBe(1);
});
