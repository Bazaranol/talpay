<?php

namespace App\Filament\Client\Resources;

use App\Actions\Wallet\CreateTopUpRequest;
use App\Enums\TopUpRequestStatus;
use App\Filament\Client\Resources\TopUpRequestResource\Pages;
use App\Models\TopUpRequest;
use App\Models\Wallet;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TopUpRequestResource extends Resource
{
    protected static ?string $model = TopUpRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Заявки на пополнение';

    protected static ?string $modelLabel = 'Заявка';

    protected static ?string $pluralModelLabel = 'Заявки на пополнение';

    /** @return Builder<TopUpRequest> */
    public static function getEloquentQuery(): Builder
    {
        $wallet = auth()->user()?->wallet;

        return parent::getEloquentQuery()
            ->when(
                $wallet instanceof Wallet,
                fn (Builder $q) => $q->whereBelongsTo($wallet, 'wallet'),
                fn (Builder $q) => $q->whereRaw('1 = 0'),
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

                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->formatStateUsing(fn (int $state) => Wallet::formatMinor($state))
                    ->sortable(),

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

                Tables\Columns\TextColumn::make('comment')
                    ->label('Комментарий')
                    ->limit(50)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('createRequest')
                    ->label('Создать заявку')
                    ->icon('heroicon-o-plus')
                    ->form(function (): array {
                        $wallet = auth()->user()?->wallet;

                        return [
                            TextInput::make('amount')
                                ->label('Сумма (руб.)')
                                ->numeric()
                                ->minValue(1)
                                ->required()
                                ->live(debounce: 500),

                            Placeholder::make('commission_preview')
                                ->label('')
                                ->content(function (Get $get) use ($wallet): string {
                                    if (! $wallet instanceof Wallet || $wallet->commission_rate_bps <= 0) {
                                        return '';
                                    }
                                    $amount = (float) ($get('amount') ?? 0);
                                    if ($amount <= 0) {
                                        return '';
                                    }
                                    $gross = (int) round($amount * 100);
                                    $commission = $wallet->calculateCommission($gross);
                                    $net = $gross - $commission;
                                    $ratePct = number_format($wallet->commission_rate_bps / 100, 2, '.', '');
                                    $netStr = number_format($net / 100, 2, ',', ' ');
                                    $grossStr = number_format($gross / 100, 2, ',', ' ');

                                    return "При комиссии {$ratePct}%: к зачислению {$netStr} ₽ из {$grossStr} ₽";
                                }),

                            Textarea::make('comment')
                                ->label('Комментарий')
                                ->nullable(),
                        ];
                    })
                    ->action(function (array $data): void {
                        $wallet = auth()->user()?->wallet;
                        if (! $wallet instanceof Wallet) {
                            return;
                        }
                        (new CreateTopUpRequest)->execute(
                            $wallet,
                            (int) round($data['amount'] * 100),
                            $data['comment'] ?? null,
                        );
                    }),
            ])
            ->recordUrl(fn (TopUpRequest $record) => Pages\ViewTopUpRequest::getUrl(['record' => $record]))
            ->paginated([10, 25, 50]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTopUpRequests::route('/'),
            'view' => Pages\ViewTopUpRequest::route('/{record}'),
        ];
    }
}
