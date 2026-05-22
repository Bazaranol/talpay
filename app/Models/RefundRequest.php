<?php

namespace App\Models;

use App\Enums\RefundRequestStatus;
use App\Exceptions\RefundRequestAlreadyProcessedException;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $wallet_transaction_id
 * @property string $currency
 * @property int $amount
 * @property string|null $reason
 * @property RefundRequestStatus $status
 * @property Carbon|null $confirmed_at
 * @property int|null $confirmed_by
 * @property Carbon|null $rejected_at
 * @property int|null $rejected_by
 * @property string|null $rejection_reason
 * @property string|null $idempotency_key
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Money $amount_as_money
 * @property-read WalletTransaction $debit
 * @property-read User|null $confirmedBy
 * @property-read User|null $rejectedBy
 */
class RefundRequest extends Model
{
    protected $fillable = [
        'wallet_transaction_id',
        'currency',
        'amount',
        'reason',
        'status',
        'confirmed_at',
        'confirmed_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'idempotency_key',
    ];

    protected $attributes = ['status' => RefundRequestStatus::Pending->value];

    protected function casts(): array
    {
        return [
            'status' => RefundRequestStatus::class,
            'amount' => 'integer',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function confirm(User $admin): void
    {
        $this->transitionTo(RefundRequestStatus::Confirmed, $admin);
    }

    public function reject(User $admin, ?string $rejectionReason = null): void
    {
        $this->transitionTo(RefundRequestStatus::Rejected, $admin, $rejectionReason);
    }

    private function transitionTo(RefundRequestStatus $target, User $admin, ?string $rejectionReason = null): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new RefundRequestAlreadyProcessedException($this->id, $this->status);
        }

        [$timestampField, $byField] = match ($target) {
            RefundRequestStatus::Confirmed => ['confirmed_at', 'confirmed_by'],
            RefundRequestStatus::Rejected => ['rejected_at', 'rejected_by'],
            default => throw new \LogicException("Cannot transition to {$target->value}"),
        };

        $this->status = $target;
        $this->{$timestampField} = now();
        $this->{$byField} = $admin->id;
        if ($target === RefundRequestStatus::Rejected && $rejectionReason !== null) {
            $this->rejection_reason = $rejectionReason;
        }
        $this->save();
    }

    /** @return Attribute<Money, never> */
    protected function amountAsMoney(): Attribute
    {
        return Attribute::get(
            fn () => Money::ofMinor($this->amount, $this->currency)
        );
    }

    /** @param Builder<RefundRequest> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', RefundRequestStatus::Pending);
    }

    /** @return BelongsTo<WalletTransaction, $this> */
    public function debit(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
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
