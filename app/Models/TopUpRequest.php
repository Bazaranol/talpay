<?php

namespace App\Models;

use App\Enums\TopUpRequestStatus;
use App\Exceptions\TopUpAlreadyProcessedException;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $wallet_id
 * @property string $currency
 * @property int $amount
 * @property int|null $commission_amount
 * @property string|null $comment
 * @property TopUpRequestStatus $status
 * @property Carbon|null $confirmed_at
 * @property int|null $confirmed_by
 * @property Carbon|null $rejected_at
 * @property int|null $rejected_by
 * @property string|null $idempotency_key
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Money $amount_as_money
 * @property-read Wallet $wallet
 * @property-read User|null $confirmedBy
 * @property-read User|null $rejectedBy
 */
class TopUpRequest extends Model
{
    protected $fillable = [
        'wallet_id',
        'currency',
        'amount',
        'commission_amount',
        'comment',
        'status',
        'confirmed_at',
        'confirmed_by',
        'rejected_at',
        'rejected_by',
        'idempotency_key',
    ];

    protected $attributes = ['status' => TopUpRequestStatus::Pending->value];

    protected function casts(): array
    {
        return [
            'status' => TopUpRequestStatus::class,
            'amount' => 'integer',
            'commission_amount' => 'integer',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function confirm(User $admin): void
    {
        $this->transitionTo(TopUpRequestStatus::Confirmed, $admin);
    }

    public function reject(User $admin): void
    {
        $this->transitionTo(TopUpRequestStatus::Rejected, $admin);
    }

    private function transitionTo(TopUpRequestStatus $target, User $admin): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new TopUpAlreadyProcessedException($this->id, $this->status);
        }

        [$timestampField, $byField] = match ($target) {
            TopUpRequestStatus::Confirmed => ['confirmed_at', 'confirmed_by'],
            TopUpRequestStatus::Rejected => ['rejected_at', 'rejected_by'],
            default => throw new \LogicException("Cannot transition to {$target->value}"),
        };

        $this->status = $target;
        $this->{$timestampField} = now();
        $this->{$byField} = $admin->id;
        $this->save();
    }

    /** @return Attribute<Money, never> */
    protected function amountAsMoney(): Attribute
    {
        return Attribute::get(
            fn () => Money::ofMinor($this->amount, $this->currency)
        );
    }

    /** @param Builder<TopUpRequest> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', TopUpRequestStatus::Pending);
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
