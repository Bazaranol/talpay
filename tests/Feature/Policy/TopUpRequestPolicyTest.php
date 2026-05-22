<?php

use App\Actions\Wallet\CreateTopUpRequest;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeClient(): User
{
    return User::factory()->create(['role' => UserRole::Client->value]);
}

function makeAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('returns 404 when a client accesses another client\'s top-up request', function () {
    $owner = makeClient();
    $ownerWallet = Wallet::createForUser($owner);
    $request = (new CreateTopUpRequest)->execute($ownerWallet, 5_000);

    $other = makeClient();
    Wallet::createForUser($other);

    $this->actingAs($other)
        ->get('/client/top-up-requests/'.$request->id)
        ->assertStatus(404);
});

it('allows a client to view their own top-up request', function () {
    $client = makeClient();
    $wallet = Wallet::createForUser($client);
    $request = (new CreateTopUpRequest)->execute($wallet, 5_000);

    $this->actingAs($client)
        ->get('/client/top-up-requests/'.$request->id)
        ->assertStatus(200);
});

it('returns 404 for a client with no wallet accessing any top-up request', function () {
    $owner = makeClient();
    $ownerWallet = Wallet::createForUser($owner);
    $request = (new CreateTopUpRequest)->execute($ownerWallet, 5_000);

    $noWalletClient = makeClient();

    $this->actingAs($noWalletClient)
        ->get('/client/top-up-requests/'.$request->id)
        ->assertStatus(404);
});
