<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Exceptions\WalletTransactionImmutableException;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $wallet_id
 * @property string $currency
 * @property TransactionType $type
 * @property int $amount
 * @property int $commission_amount
 * @property int $balance_after
 * @property int|null $reference_id
 * @property string|null $reference_type
 * @property string|null $description
 * @property int $created_by
 * @property Carbon $created_at
 * @property-read Money $amount_as_money
 * @property-read Money $commission_as_money
 * @property-read Money $balance_after_as_money
 * @property-read Wallet $wallet
 * @property-read User $createdBy
 * @property-read WalletTransaction|TopUpRequest|null $reference
 */
class WalletTransaction extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'wallet_id',
        'currency',
        'type',
        'amount',
        'commission_amount',
        'balance_after',
        'reference_id',
        'reference_type',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => 'integer',
            'commission_amount' => 'integer',
            'balance_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new WalletTransactionImmutableException);
        static::deleting(static fn () => throw new WalletTransactionImmutableException);
    }

    /** @return Attribute<Money, never> */
    protected function amountAsMoney(): Attribute
    {
        return Attribute::get(
            fn () => Money::ofMinor($this->amount, $this->currency)
        );
    }

    /** @return Attribute<Money, never> */
    protected function commissionAsMoney(): Attribute
    {
        return Attribute::get(
            fn () => Money::ofMinor($this->commission_amount, $this->currency)
        );
    }

    /** @return Attribute<Money, never> */
    protected function balanceAfterAsMoney(): Attribute
    {
        return Attribute::get(
            fn () => Money::ofMinor($this->balance_after, $this->currency)
        );
    }

    /** @param Builder<WalletTransaction> $query */
    public function scopeForWallet(Builder $query, int $walletId): void
    {
        $query->where('wallet_id', $walletId);
    }

    /** @param Builder<WalletTransaction> $query */
    public function scopeOfType(Builder $query, TransactionType $type): void
    {
        $query->where('type', $type);
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
