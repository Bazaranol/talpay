<?php

namespace App\Models;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $balance
 * @property string $currency
 * @property int $commission_rate_bps
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Money $balance_as_money
 * @property-read User $user
 * @property-read Collection<int, TopUpRequest> $topUpRequests
 * @property-read Collection<int, WalletTransaction> $transactions
 */
class Wallet extends Model
{
    public const DEFAULT_CURRENCY = 'RUB';

    protected $fillable = [
        'user_id',
        'currency',
        'commission_rate_bps',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'commission_rate_bps' => 'integer',
        ];
    }

    public static function formatMinor(int $amount, string $currency = self::DEFAULT_CURRENCY): string
    {
        return Money::ofMinor($amount, $currency)->formatTo('ru_RU');
    }

    public function calculateCommission(int $grossAmountMinor): int
    {
        $rate = min($this->commission_rate_bps, 10_000);

        return intdiv($grossAmountMinor * $rate, 10_000);
    }

    public static function createForUser(User $user): self
    {
        return static::create([
            'user_id' => $user->id,
            'balance' => 0,
            'currency' => self::DEFAULT_CURRENCY,
        ]);
    }

    /** @return Attribute<Money, never> */
    protected function balanceAsMoney(): Attribute
    {
        return Attribute::get(
            fn () => Money::ofMinor($this->balance, $this->currency)
        );
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<TopUpRequest, $this> */
    public function topUpRequests(): HasMany
    {
        return $this->hasMany(TopUpRequest::class);
    }

    /** @return HasMany<WalletTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
