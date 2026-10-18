<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'account_no', 'name', 'email', 'phone', 'address', 'status'])]
class Client extends Model
{
    use HasFactory, Notifiable;

    /**
     * Creates the client with its ACC-00001 style account number, which is
     * built from the row's id and so is only known after the insert.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function openAccount(array $attributes): self
    {
        $client = self::create(['account_no' => 'TMP-'.Str::random(10)] + $attributes);
        $client->update(['account_no' => 'ACC-'.str_pad((string) $client->id, 5, '0', STR_PAD_LEFT)]);

        return $client;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasManyThrough
    {
        return $this->hasManyThrough(Invoice::class, Subscription::class);
    }

    /** Billing emails go to the client's own address, else their login's. */
    public function routeNotificationForMail(): ?string
    {
        return $this->email ?: $this->user?->email;
    }
}
