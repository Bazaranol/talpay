<?php

use App\Actions\Wallet\DebitWallet;
use App\Enums\UserRole;
use App\Exceptions\InsufficientFundsException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

// DatabaseMigrations (not RefreshDatabase) is required: RefreshDatabase wraps the
// test in a transaction, so forked child processes reconnect to an empty DB because
// the uncommitted parent data is invisible across connections.
// DatabaseMigrations commits every statement, making rows visible after fork.
//
// Run this test explicitly against a live PostgreSQL instance:
//   RUN_CONCURRENT_TEST=1 vendor/bin/pest tests/Feature/Actions/Wallet/DebitWalletConcurrentTest.php
uses(DatabaseMigrations::class);

it('serialises concurrent 6 000-kopek debits on a 10 000-kopek balance so exactly one succeeds', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $wallet = Wallet::createForUser(User::factory()->create(['role' => UserRole::Client]));
    DB::table('wallets')->where('id', $wallet->id)->update(['balance' => 10_000]);

    $walletId = $wallet->id;
    $adminId = $admin->id;

    if (function_exists('pcntl_fork')) {
        // ── pcntl_fork path ────────────────────────────────────────────────
        // PostgreSQL's SELECT … FOR UPDATE acquires a row-level lock on the
        // wallet row. The second process to arrive blocks until the first
        // commits, then re-reads balance = 4 000 and throws
        // InsufficientFundsException. Double-spend is structurally impossible.

        // Purge all open connections before fork: sharing a pdo_pgsql socket
        // across fork() causes undefined behaviour (duplicate ACKs, corrupted
        // protocol state). Each process reconnects independently on first use.
        DB::purge();

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }

        if ($pid === 0) {
            // ── Child process ──────────────────────────────────────────────
            try {
                (new DebitWallet)->execute(
                    Wallet::findOrFail($walletId),
                    6_000,
                    'Concurrent debit — child',
                    User::findOrFail($adminId),
                );
                exit(0); // won the race
            } catch (InsufficientFundsException) {
                exit(1); // lost the race — expected
            } catch (Throwable) {
                exit(2); // unexpected error
            }
        }

        // ── Parent process ─────────────────────────────────────────────────
        $parentSucceeded = true;
        try {
            (new DebitWallet)->execute(
                Wallet::findOrFail($walletId),
                6_000,
                'Concurrent debit — parent',
                User::findOrFail($adminId),
            );
        } catch (InsufficientFundsException) {
            $parentSucceeded = false;
        }

        $childStatus = 0;
        pcntl_waitpid($pid, $childStatus);
        $childSucceeded = pcntl_wexitstatus($childStatus) === 0;

        DB::reconnect();

        expect($parentSucceeded xor $childSucceeded)->toBeTrue();
        expect(Wallet::find($walletId)->balance)->toBe(4_000);
        expect(WalletTransaction::count())->toBe(1);

    } else {
        // ── Two-connection sequential simulation ───────────────────────────
        // Without pcntl, true parallelism is unavailable. This path verifies
        // that the losing process reads the post-commit balance and throws
        // InsufficientFundsException. The serialisation guarantee is provided
        // by PostgreSQL row-level locks, not by application code, so testing
        // the lock contention itself requires the pcntl branch above.

        $action = new DebitWallet;

        $action->execute(
            Wallet::findOrFail($walletId),
            6_000,
            'First debit',
            User::findOrFail($adminId),
        );

        expect(fn () => $action->execute(
            Wallet::findOrFail($walletId),
            6_000,
            'Second debit',
            User::findOrFail($adminId),
        ))->toThrow(InsufficientFundsException::class);

        expect(Wallet::find($walletId)->balance)->toBe(4_000);
        expect(WalletTransaction::count())->toBe(1);
    }
});
