<?php

namespace App\Filament\Pages;

use App\Actions\Wallet\DebitWallet;
use App\Enums\UserRole;
use App\Exceptions\InsufficientFundsException;
use App\Models\User;
use App\Models\Wallet;
use Filament\Forms\ComponentContainer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * @property ComponentContainer $form
 */
class DebitPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-minus-circle';

    protected static ?string $navigationLabel = 'Списание';

    protected static ?string $title = 'Ручное списание';

    protected static string $view = 'filament.pages.debit-page';

    protected static ?string $slug = 'debit';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('user_id')
                    ->label('Клиент')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => User::query()
                        ->where('role', UserRole::Client)
                        ->whereHas('wallet')
                        ->where(fn ($q) => $q
                            ->where('email', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%"))
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (User $u) => [$u->id => "{$u->name} ({$u->email})"])
                        ->toArray()
                    )
                    ->getOptionLabelUsing(function (mixed $value): string {
                        $user = User::find($value);

                        return $user !== null ? "{$user->name} ({$user->email})" : (string) $value;
                    })
                    ->required(),

                TextInput::make('amount')
                    ->label('Сумма (руб.)')
                    ->numeric()
                    ->minValue(1)
                    ->required(),

                Textarea::make('description')
                    ->label('Описание')
                    ->required(),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        $client = User::findOrFail($data['user_id']);
        $wallet = $client->wallet;

        if (! $wallet instanceof Wallet) {
            Notification::make()->danger()->title('У клиента нет кошелька')->send();

            return;
        }

        try {
            (new DebitWallet)->execute(
                $wallet,
                (int) round($data['amount'] * 100),
                $data['description'],
                auth()->user(),
            );

            Notification::make()->success()->title('Списание выполнено')->send();
            $this->form->fill();
        } catch (InsufficientFundsException $e) {
            $available = Wallet::formatMinor($e->availableBalance);
            Notification::make()
                ->danger()
                ->title('Недостаточно средств')
                ->body("Доступный баланс клиента: {$available}")
                ->send();
            throw new \Filament\Support\Exceptions\Halt;
        }
    }
}
