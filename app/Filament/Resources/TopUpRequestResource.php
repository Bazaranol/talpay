<?php

namespace App\Filament\Resources;

use App\Actions\Wallet\ConfirmTopUpRequest;
use App\Enums\TopUpRequestStatus;
use App\Exceptions\TopUpAlreadyProcessedException;
use App\Filament\Resources\TopUpRequestResource\Pages;
use App\Models\TopUpRequest;
use App\Models\Wallet;
use Filament\Forms\Form;
use Illuminate\Database\Eloquent\Builder;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TopUpRequestResource extends Resource
{
    protected static ?string $model = TopUpRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Заявки на пополнение';

    protected static ?string $modelLabel = 'Заявка';

    protected static ?string $pluralModelLabel = 'Заявки на пополнение';

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

                Tables\Columns\TextColumn::make('wallet.user.name')
                    ->label('Клиент')
                    ->description(fn (TopUpRequest $record) => $record->wallet->user->email)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'wallet.user',
                        fn (Builder $q) => $q
                            ->where('name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%")
                    ))
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->formatStateUsing(fn (int $state) => Wallet::formatMinor($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('comment')
                    ->label('Комментарий')
                    ->limit(40)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (TopUpRequestStatus $state) => match ($state) {
                        TopUpRequestStatus::Pending => 'Ожидает',
                        TopUpRequestStatus::Confirmed => 'Подтверждена',
                        TopUpRequestStatus::Rejected => 'Отклонена',
                    })
                    ->color(fn (TopUpRequestStatus $state) => match ($state) {
                        TopUpRequestStatus::Pending => 'warning',
                        TopUpRequestStatus::Confirmed => 'success',
                        TopUpRequestStatus::Rejected => 'danger',
                    }),

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
                        TopUpRequestStatus::Pending->value => 'Ожидает',
                        TopUpRequestStatus::Confirmed->value => 'Подтверждена',
                        TopUpRequestStatus::Rejected->value => 'Отклонена',
                    ])
                    ->default(TopUpRequestStatus::Pending->value),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm')
                    ->label('Подтвердить')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (TopUpRequest $record) => $record->status === TopUpRequestStatus::Pending)
                    ->action(function (TopUpRequest $record): void {
                        try {
                            (new ConfirmTopUpRequest)->execute($record, auth()->user());
                        } catch (TopUpAlreadyProcessedException) {
                            Notification::make()
                                ->danger()
                                ->title('Заявка уже обработана')
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Отклонить')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (TopUpRequest $record) => $record->status === TopUpRequestStatus::Pending)
                    ->action(function (TopUpRequest $record): void {
                        try {
                            $record->reject(auth()->user());
                        } catch (TopUpAlreadyProcessedException) {
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
            'index' => Pages\ListTopUpRequests::route('/'),
        ];
    }
}
