<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['client_id', 'plan_id', 'service_type', 'username', 'password', 'next_due_date', 'status', 'suspended_at'])]
#[Hidden(['password'])]
class Subscription extends Model
{
    use HasFactory;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'next_due_date' => 'date',
            'suspended_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function routerLogs(): HasMany
    {
        return $this->hasMany(RouterLog::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === self::SUSPENDED;
    }
}
