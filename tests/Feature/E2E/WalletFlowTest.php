<?php

use App\Enums\RefundRequestStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Filament\Client\Pages\TransactionHistory;
use App\Filament\Client\Resources\RefundRequestResource\Pages\ListRefundRequests as ClientListRefundRequests;
use App\Filament\Client\Resources\TopUpRequestResource\Pages\ListTopUpRequests as ClientListTopUpRequests;
use App\Filament\Client\Widgets\BalanceWidget;
use App\Filament\Pages\DebitPage;
use App\Filament\Resources\RefundRequestResource\Pages\ListRefundRequests as AdminListRefundRequests;
use App\Filament\Resources\TopUpRequestResource\Pages\ListTopUpRequests as AdminListTopUpRequests;
use App\Filament\Resources\WalletTransactionResource\Pages\ViewWalletTransaction;
use App\Models\RefundRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->client = User::factory()->create(['role' => UserRole::Client]);
    $this->wallet = Wallet::createForUser($this->client);

    $test = $this;

    // Helper: client creates top-up request and admin confirms it.
    // $rubles is the form amount (e.g. 10 → 1000 kopeks).
    $this->topUp = function (float $rubles, ?string $comment = null) use ($test): void {
        Filament::setCurrentPanel(Filament::getPanel('client'));
        Livewire::actingAs($test->client);
        Livewire::test(ClientListTopUpRequests::class)
            ->callTableAction('createRequest', data: ['amount' => $rubles, 'comment' => $comment])
            ->assertHasNoTableActionErrors();

        $request = $test->wallet->topUpRequests()->latest()->firstOrFail();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($test->admin);
        Livewire::test(AdminListTopUpRequests::class)
            ->callTableAction('confirm', $request)
            ->assertHasNoTableActionErrors();
    };

    // Helper: admin debits the client wallet via DebitPage.
    $this->debit = function (float $rubles, string $description) use ($test): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($test->admin);
        Livewire::test(DebitPage::class)
            ->fillForm([
                'user_id' => $test->client->id,
                'amount' => $rubles,
                'description' => $description,
            ])
            ->call('submit')
            ->assertHasNoFormErrors();
    };

    // Helper: admin creates a refund against a specific debit transaction.
    $this->refund = function (WalletTransaction $debitTx, float $rubles, ?string $description = null) use ($test): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($test->admin);
        Livewire::test(ViewWalletTransaction::class, ['record' => $debitTx->getRouteKey()])
            ->callAction('refund', data: ['amount' => $rubles, 'description' => $description])
            ->assertHasNoActionErrors();
    };

    // Helper: client submits a RefundRequest via client panel, admin confirms it.
    $this->requestRefund = function (WalletTransaction $debitTx, float $rubles, ?string $reason = null) use ($test): void {
        Filament::setCurrentPanel(Filament::getPanel('client'));
        Livewire::actingAs($test->client);
        Livewire::test(ClientListRefundRequests::class)
            ->callTableAction('createRefundRequest', data: [
                'wallet_transaction_id' => $debitTx->id,
                'amount' => $rubles,
                'reason' => $reason,
            ])
            ->assertHasNoTableActionErrors();

        $refundRequest = RefundRequest::query()
            ->where('wallet_transaction_id', $debitTx->id)
            ->latest()
            ->firstOrFail();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($test->admin);
        Livewire::test(AdminListRefundRequests::class)
            ->callTableAction('confirm', $refundRequest)
            ->assertHasNoTableActionErrors();
    };
});

// ─── E2E: пополнение ────────────────────────────────────────────────────────

it('e2e top-up: client creates request, admin confirms, client sees balance and transaction', function () {
    // Step 1 — client creates top-up request via client panel table header action
    Filament::setCurrentPanel(Filament::getPanel('client'));
    Livewire::actingAs($this->client);
    Livewire::test(ClientListTopUpRequests::class)
        ->callTableAction('createRequest', data: ['amount' => 10, 'comment' => 'Deposit'])
        ->assertHasNoTableActionErrors();

    $request = $this->wallet->topUpRequests()->firstOrFail();
    expect($request->amount)->toBe(1000)
        ->and($request->status->value)->toBe('pending');

    // Step 2 — admin confirms via admin panel table row action
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::actingAs($this->admin);
    Livewire::test(AdminListTopUpRequests::class)
        ->callTableAction('confirm', $request)
        ->assertHasNoTableActionErrors();

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(1000);

    // Step 3 — client sees updated balance in BalanceWidget
    Filament::setCurrentPanel(Filament::getPanel('client'));
    Livewire::actingAs($this->client);
    Livewire::test(BalanceWidget::class)
        ->assertSee('10'); // 10,00 ₽

    // Step 4 — client sees TopUp transaction in TransactionHistory
    $tx = $this->wallet->transactions()->firstOrFail();
    expect($tx->type)->toBe(TransactionType::TopUp)
        ->and($tx->amount)->toBe(1000)
        ->and($tx->balance_after)->toBe(1000);

    Livewire::test(TransactionHistory::class)
        ->assertCanSeeTableRecords([$tx]);
});

// ─── E2E: списание ──────────────────────────────────────────────────────────

it('e2e debit: admin debits client, client sees transaction and reduced balance', function () {
    ($this->topUp)(10); // wallet: 1000 kopeks

    // Admin debits 3 rubles (300 kopeks) via DebitPage
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::actingAs($this->admin);
    Livewire::test(DebitPage::class)
        ->fillForm([
            'user_id' => $this->client->id,
            'amount' => 3,
            'description' => 'Monthly fee',
        ])
        ->call('submit')
        ->assertHasNoFormErrors();

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(700);

    $tx = $this->wallet->transactions()->ofType(TransactionType::Debit)->firstOrFail();
    expect($tx->amount)->toBe(300)
        ->and($tx->balance_after)->toBe(700);

    // Client sees the debit in TransactionHistory
    Filament::setCurrentPanel(Filament::getPanel('client'));
    Livewire::actingAs($this->client);
    Livewire::test(TransactionHistory::class)
        ->assertCanSeeTableRecords([$tx]);
});

// ─── E2E: возврат ───────────────────────────────────────────────────────────

it('e2e refund: admin refunds debit, client sees refund transaction and restored balance', function () {
    ($this->topUp)(10); // +1000, balance: 1000
    ($this->debit)(3, 'Fee'); // -300, balance: 700

    $debitTx = $this->wallet->transactions()->ofType(TransactionType::Debit)->firstOrFail();

    // Admin creates partial refund of 2 rubles (200 kopeks) via ViewWalletTransaction page action
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::actingAs($this->admin);
    Livewire::test(ViewWalletTransaction::class, ['record' => $debitTx->getRouteKey()])
        ->callAction('refund', data: ['amount' => 2, 'description' => 'Partial refund'])
        ->assertHasNoActionErrors();

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(900); // 700 + 200

    $refundTx = $this->wallet->transactions()->ofType(TransactionType::Refund)->firstOrFail();
    expect($refundTx->amount)->toBe(200)
        ->and($refundTx->balance_after)->toBe(900);

    // Client sees the refund in TransactionHistory
    Filament::setCurrentPanel(Filament::getPanel('client'));
    Livewire::actingAs($this->client);
    Livewire::test(TransactionHistory::class)
        ->assertCanSeeTableRecords([$refundTx]);
});

// ─── Integrity ──────────────────────────────────────────────────────────────

it('integrity: top-up 1000 → debit 300 → debit 500 → refund 200 → balance 400 with correct history', function () {
    ($this->topUp)(10);          // +1000 kopeks, balance: 1000
    ($this->debit)(3, 'D1');     // -300  kopeks, balance: 700
    ($this->debit)(5, 'D2');     // -500  kopeks, balance: 200

    $firstDebit = $this->wallet->transactions()
        ->ofType(TransactionType::Debit)
        ->orderBy('id')
        ->firstOrFail();

    ($this->refund)($firstDebit, 2, 'R1'); // +200 kopeks, balance: 400

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(400);

    // Four transactions in creation order
    $txs = $this->wallet->transactions()->orderBy('id')->get();
    expect($txs)->toHaveCount(4);

    [$topUp, $debit1, $debit2, $refund] = $txs;

    expect($topUp->type)->toBe(TransactionType::TopUp)
        ->and($topUp->amount)->toBe(1000)
        ->and($topUp->balance_after)->toBe(1000);

    expect($debit1->type)->toBe(TransactionType::Debit)
        ->and($debit1->amount)->toBe(300)
        ->and($debit1->balance_after)->toBe(700);

    expect($debit2->type)->toBe(TransactionType::Debit)
        ->and($debit2->amount)->toBe(500)
        ->and($debit2->balance_after)->toBe(200);

    expect($refund->type)->toBe(TransactionType::Refund)
        ->and($refund->amount)->toBe(200)
        ->and($refund->balance_after)->toBe(400);

    // Client sees all four transactions in TransactionHistory
    Filament::setCurrentPanel(Filament::getPanel('client'));
    Livewire::actingAs($this->client);
    Livewire::test(TransactionHistory::class)
        ->assertCanSeeTableRecords($txs->all());
});

// ─── E2E: заявка на возврат ─────────────────────────────────────────────────

it('e2e refund request: client submits, admin confirms, balance restored and client sees refund', function () {
    ($this->topUp)(10);       // +1000, balance: 1000
    ($this->debit)(4, 'Fee'); // -400, balance: 600

    $debitTx = $this->wallet->transactions()->ofType(TransactionType::Debit)->firstOrFail();

    // Client submits RefundRequest for 3 rubles (300 kopeks) via client panel
    Filament::setCurrentPanel(Filament::getPanel('client'));
    Livewire::actingAs($this->client);
    Livewire::test(ClientListRefundRequests::class)
        ->callTableAction('createRefundRequest', data: [
            'wallet_transaction_id' => $debitTx->id,
            'amount' => 3,
            'reason' => 'Wrong charge',
        ])
        ->assertHasNoTableActionErrors();

    $refundRequest = RefundRequest::query()->where('wallet_transaction_id', $debitTx->id)->firstOrFail();
    expect($refundRequest->status)->toBe(RefundRequestStatus::Pending)
        ->and($refundRequest->amount)->toBe(300);

    // Admin confirms via admin panel
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::actingAs($this->admin);
    Livewire::test(AdminListRefundRequests::class)
        ->callTableAction('confirm', $refundRequest)
        ->assertHasNoTableActionErrors();

    $refundRequest->refresh();
    expect($refundRequest->status)->toBe(RefundRequestStatus::Confirmed);

    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(900); // 600 + 300

    // Refund WalletTransaction was created
    $refundTx = $this->wallet->transactions()->ofType(TransactionType::Refund)->firstOrFail();
    expect($refundTx->amount)->toBe(300)
        ->and($refundTx->balance_after)->toBe(900);

    // Client sees the refund transaction in TransactionHistory
    Filament::setCurrentPanel(Filament::getPanel('client'));
    Livewire::actingAs($this->client);
    Livewire::test(TransactionHistory::class)
        ->assertCanSeeTableRecords([$refundTx]);
});

it('e2e refund request: admin rejects, balance unchanged, request shows rejected status', function () {
    ($this->topUp)(5);        // +500, balance: 500
    ($this->debit)(2, 'Fee'); // -200, balance: 300

    $debitTx = $this->wallet->transactions()->ofType(TransactionType::Debit)->firstOrFail();

    // Client creates refund request
    Filament::setCurrentPanel(Filament::getPanel('client'));
    Livewire::actingAs($this->client);
    Livewire::test(ClientListRefundRequests::class)
        ->callTableAction('createRefundRequest', data: [
            'wallet_transaction_id' => $debitTx->id,
            'amount' => 1,
            'reason' => 'Incorrect charge',
        ])
        ->assertHasNoTableActionErrors();

    $refundRequest = RefundRequest::query()->where('wallet_transaction_id', $debitTx->id)->firstOrFail();

    // Admin rejects
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::actingAs($this->admin);
    Livewire::test(AdminListRefundRequests::class)
        ->callTableAction('reject', $refundRequest, data: ['rejection_reason' => 'Policy violation'])
        ->assertHasNoTableActionErrors();

    $refundRequest->refresh();
    expect($refundRequest->status)->toBe(RefundRequestStatus::Rejected)
        ->and($refundRequest->rejection_reason)->toBe('Policy violation');

    // Balance must be unchanged
    $this->wallet->refresh();
    expect($this->wallet->balance)->toBe(300);
});
