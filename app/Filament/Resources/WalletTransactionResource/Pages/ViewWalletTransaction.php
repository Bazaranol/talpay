<?php

namespace App\Filament\Resources\WalletTransactionResource\Pages;

use App\Actions\Wallet\RefundDebit;
use App\Enums\TransactionType;
use App\Exceptions\RefundExceedsDebitException;
use App\Filament\Resources\WalletTransactionResource;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property WalletTransaction $record
 */
class ViewWalletTransaction extends ViewRecord
{
    protected static string $resource = WalletTransactionResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('id')->label('#'),
            TextEntry::make('wallet.user.name')->label('Клиент'),
            TextEntry::make('type')
                ->label('Тип')
                ->badge()
                ->formatStateUsing(fn (TransactionType $state) => match ($state) {
                    TransactionType::TopUp => 'Пополнение',
                    TransactionType::Debit => 'Списание',
                    TransactionType::Refund => 'Возврат',
                })
                ->color(fn (TransactionType $state) => match ($state) {
                    TransactionType::TopUp => 'success',
                    TransactionType::Debit => 'danger',
                    TransactionType::Refund => 'warning',
                }),
            TextEntry::make('amount')
                ->label('Сумма')
                ->formatStateUsing(fn (int $state, WalletTransaction $record) => Wallet::formatMinor($state, $record->currency)),
            TextEntry::make('commission_amount')
                ->label('Комиссия')
                ->formatStateUsing(fn (int $state, WalletTransaction $record) => Wallet::formatMinor($state, $record->currency))
                ->visible(fn (WalletTransaction $record) => $record->type === TransactionType::TopUp && $record->commission_amount > 0),
            TextEntry::make('gross_amount')
                ->label('Изначальная сумма заявки')
                ->state(fn (WalletTransaction $record): int => $record->amount + $record->commission_amount)
                ->formatStateUsing(fn (int $state, WalletTransaction $record) => Wallet::formatMinor($state, $record->currency))
                ->visible(fn (WalletTransaction $record) => $record->type === TransactionType::TopUp && $record->commission_amount > 0),
            TextEntry::make('balance_after')
                ->label('Баланс после')
                ->formatStateUsing(fn (int $state, WalletTransaction $record) => Wallet::formatMinor($state, $record->currency)),
            TextEntry::make('description')->label('Описание')->placeholder('—'),
            TextEntry::make('created_by')
                ->label('Оператор')
                ->formatStateUsing(fn () => 'Администратор'),
            TextEntry::make('created_at')->label('Дата')->dateTime('d.m.Y H:i:s'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refund')
                ->label('Создать возврат')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (): bool => $this->record->type === TransactionType::Debit)
                ->fillForm(function (ViewWalletTransaction $livewire): array {
                    $alreadyRefunded = WalletTransaction::query()
                        ->where('reference_type', WalletTransaction::class)
                        ->where('reference_id', $livewire->record->id)
                        ->where('type', TransactionType::Refund)
                        ->sum('amount');

                    return [
                        'amount' => max(0, ($livewire->record->amount - $alreadyRefunded) / 100),
                    ];
                })
                ->form([
                    TextInput::make('amount')
                        ->label('Сумма возврата (руб.)')
                        ->numeric()
                        ->minValue(0.01)
                        ->required(),

                    Textarea::make('description')
                        ->label('Описание'),
                ])
                ->action(function (array $data): void {
                    try {
                        (new RefundDebit)->execute(
                            $this->record,
                            (int) round($data['amount'] * 100),
                            auth()->user(),
                            $data['description'] ?? null,
                        );

                        Notification::make()->success()->title('Возврат успешно выполнен')->send();
                        $this->refreshFormData([]);
                    } catch (RefundExceedsDebitException $e) {
                        $available = Wallet::formatMinor($e->debitAmount - $e->alreadyRefunded);
                        Notification::make()
                            ->danger()
                            ->title('Сумма превышает доступный остаток')
                            ->body("Доступно для возврата: {$available}")
                            ->send();
                        throw new \Filament\Support\Exceptions\Halt;
                    }
                }),
        ];
    }
}
