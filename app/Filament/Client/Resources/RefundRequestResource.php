<?php

namespace App\Filament\Client\Resources;

use App\Actions\Wallet\CreateRefundRequest;
use App\Enums\RefundRequestStatus;
use App\Enums\TransactionType;
use App\Models\RefundRequest;
use App\Exceptions\RefundExceedsDebitException;
use App\Filament\Client\Resources\RefundRequestResource\Pages;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RefundRequestResource extends Resource
{
    protected static ?string $model = RefundRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationLabel = 'Заявки на возврат';

    protected static ?string $modelLabel = 'Заявка на возврат';

    protected static ?string $pluralModelLabel = 'Заявки на возврат';

    /** @return Builder<RefundRequest> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas(
            'debit.wallet',
            fn (Builder $q) => $q->where('user_id', auth()->id())
        );
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('debit.id')
                    ->label('Списание №')
                    ->formatStateUsing(fn (?int $state) => $state !== null ? "№{$state}" : '—'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->formatStateUsing(fn (int $state) => Wallet::formatMinor($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (RefundRequestStatus $state) => match ($state) {
                        RefundRequestStatus::Pending => 'Ожидает',
                        RefundRequestStatus::Confirmed => 'Подтверждена',
                        RefundRequestStatus::Rejected => 'Отклонена',
                    })
                    ->color(fn (RefundRequestStatus $state) => match ($state) {
                        RefundRequestStatus::Pending => 'warning',
                        RefundRequestStatus::Confirmed => 'success',
                        RefundRequestStatus::Rejected => 'danger',
                    }),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Причина')
                    ->limit(50)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('createRefundRequest')
                    ->label('Создать заявку на возврат')
                    ->icon('heroicon-o-plus')
                    ->form(function (): array {
                        $wallet = auth()->user()?->wallet;

                        $debitOptions = $wallet instanceof Wallet
                            ? WalletTransaction::query()
                                ->where('wallet_id', $wallet->id)
                                ->where('type', TransactionType::Debit)
                                ->orderByDesc('created_at')
                                ->get()
                                ->filter(function (WalletTransaction $debit): bool {
                                    $confirmed = WalletTransaction::query()
                                        ->where('reference_type', (new WalletTransaction)->getMorphClass())
                                        ->where('reference_id', $debit->id)
                                        ->where('type', TransactionType::Refund)
                                        ->sum('amount');
                                    $pending = RefundRequest::query()
                                        ->where('wallet_transaction_id', $debit->id)
                                        ->where('status', RefundRequestStatus::Pending)
                                        ->sum('amount');

                                    return $confirmed + $pending < $debit->amount;
                                })
                                ->mapWithKeys(function (WalletTransaction $tx): array {
                                    $confirmed = WalletTransaction::query()
                                        ->where('reference_type', (new WalletTransaction)->getMorphClass())
                                        ->where('reference_id', $tx->id)
                                        ->where('type', TransactionType::Refund)
                                        ->sum('amount');
                                    $pending = RefundRequest::query()
                                        ->where('wallet_transaction_id', $tx->id)
                                        ->where('status', RefundRequestStatus::Pending)
                                        ->sum('amount');
                                    $available = $tx->amount - $confirmed - $pending;
                                    $label = "Списание №{$tx->id} от {$tx->created_at->format('d.m.Y H:i:s')} — ".
                                        Wallet::formatMinor($tx->amount).
                                        ' (доступно к возврату: '.Wallet::formatMinor($available).')';

                                    return [$tx->id => $label];
                                })
                                ->all()
                            : [];

                        return [
                            Select::make('wallet_transaction_id')
                                ->label('Списание')
                                ->options($debitOptions)
                                ->required()
                                ->searchable(),

                            TextInput::make('amount')
                                ->label('Сумма возврата (руб.)')
                                ->numeric()
                                ->minValue(0.01)
                                ->required(),

                            Textarea::make('reason')
                                ->label('Причина')
                                ->nullable(),
                        ];
                    })
                    ->action(function (array $data): void {
                        $debit = WalletTransaction::find($data['wallet_transaction_id']);
                        if (! $debit instanceof WalletTransaction) {
                            return;
                        }

                        try {
                            (new CreateRefundRequest)->execute(
                                $debit,
                                (int) round($data['amount'] * 100),
                                $data['reason'] ?? null,
                            );
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
            ])
            ->recordUrl(fn (RefundRequest $record) => Pages\ViewRefundRequest::getUrl(['record' => $record]))
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRefundRequests::route('/'),
            'view' => Pages\ViewRefundRequest::route('/{record}'),
        ];
    }
}
