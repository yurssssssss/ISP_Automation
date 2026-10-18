<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'subscription_id', 'period_start', 'period_end', 'due_date', 'amount', 'amount_paid', 'status', 'checkout_session_id', 'reminded_at', 'paid_at'])]
class Invoice extends Model
{
    use HasFactory;

    public const UNPAID = 'unpaid';

    public const PARTIAL = 'partial';

    public const PAID = 'paid';

    public const VOID = 'void';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'reminded_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // INV-2026-000123 — readable, and doubles as PayMongo's reference number.
        static::created(function (Invoice $invoice) {
            if (! $invoice->number) {
                $invoice->updateQuietly(['number' => 'INV-'.$invoice->created_at->format('Y').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT)]);
            }
        });
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Invoices that still have money owing. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [self::UNPAID, self::PARTIAL]);
    }

    public function balance(): float
    {
        return max(0, round((float) $this->amount - (float) $this->amount_paid, 2));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::UNPAID, self::PARTIAL], true);
    }
}
