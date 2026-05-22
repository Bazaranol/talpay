<?php

namespace App\Filament\Resources;

use App\Actions\Wallet\ConfirmRefundRequest;
use App\Actions\Wallet\RejectRefundRequest;
use App\Enums\RefundRequestStatus;
use App\Exceptions\RefundExceedsDebitException;
use App\Exceptions\RefundRequestAlreadyProcessedException;
use App\Filament\Resources\RefundRequestResource\Pages;
use App\Models\RefundRequest;
use App\Models\Wallet;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RefundRequestResource extends Resource
{
    protected static ?string $model = RefundRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationLabel = 'Заявки на возврат';

    protected static ?string $modelLabel = 'Заявка на возврат';

    protected static ?string $pluralModelLabel = 'Заявки на возврат';

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

                Tables\Columns\TextColumn::make('debit.wallet.user.name')
                    ->label('Клиент')
                    ->description(fn (RefundRequest $record) => $record->debit->wallet->user->email)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'debit.wallet.user',
                        fn (Builder $q) => $q
                            ->where('name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%")
                    ))
                    ->sortable(),

                Tables\Columns\TextColumn::make('wallet_transaction_id')
                    ->label('Списание №')
                    ->formatStateUsing(fn (int $state) => "№{$state}"),

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
                    ->limit(40)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        RefundRequestStatus::Pending->value => 'Ожидает',
                        RefundRequestStatus::Confirmed->value => 'Подтверждена',
                        RefundRequestStatus::Rejected->value => 'Отклонена',
                    ])
                    ->default(RefundRequestStatus::Pending->value),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm')
                    ->label('Подтвердить')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (RefundRequest $record) => $record->status === RefundRequestStatus::Pending)
                    ->action(function (RefundRequest $record): void {
                        try {
                            (new ConfirmRefundRequest)->execute($record, auth()->user());
                            Notification::make()->success()->title('Возврат выполнен')->send();
                        } catch (RefundExceedsDebitException $e) {
                            $available = Wallet::formatMinor($e->debitAmount - $e->alreadyRefunded);
                            Notification::make()
                                ->danger()
                                ->title('Сумма превышает доступный остаток')
                                ->body("Доступно для возврата: {$available}")
                                ->send();
                            throw new \Filament\Support\Exceptions\Halt;
                        } catch (RefundRequestAlreadyProcessedException) {
                            Notification::make()
                                ->danger()
                                ->title('Заявка уже обработана')
                                ->send();
                            throw new \Filament\Support\Exceptions\Halt;
                        }
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Отклонить')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (RefundRequest $record) => $record->status === RefundRequestStatus::Pending)
                    ->form([
                        Textarea::make('rejection_reason')->label('Причина отклонения'),
                    ])
                    ->action(function (RefundRequest $record, array $data): void {
                        try {
                            (new RejectRefundRequest)->execute(
                                $record,
                                auth()->user(),
                                $data['rejection_reason'] ?? null,
                            );
                            Notification::make()->success()->title('Заявка отклонена')->send();
                        } catch (RefundRequestAlreadyProcessedException) {
                            Notification::make()
                                ->danger()
                                ->title('Заявка уже обработана')
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRefundRequests::route('/'),
        ];
    }
}
