<?php

namespace App\Support;

use App\Notifications\EmailCodeNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 6-digit one-time codes sent by email: verifying an account's email and
 * "forgot password".
 *
 *  - one active code per email and purpose: a new code replaces the old one;
 *  - single use: deleted the moment it's accepted;
 *  - expires after MINUTES; MAX_ATTEMPTS wrong tries cancel it;
 *  - a new code at most every RESEND_SECONDS, and LIMITS per period.
 * Only a hash of the code is stored.
 */
class EmailCode
{
    public const VERIFY = 'verify_email';

    public const RESET = 'reset_password';

    public const MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    /** [max emails, per seconds] for each purpose. */
    private const LIMITS = [
        self::VERIFY => [5, 3600],   // 5 an hour
        self::RESET => [5, 86400],   // 5 a day
    ];

    private static function norm(string $email): string
    {
        return Str::lower(trim($email));
    }

    private static function counterKey(string $email, string $purpose): string
    {
        return "email-codes:$purpose:".sha1(self::norm($email));
    }

    /** Codes this email can still be sent in the current period. */
    public static function sendsLeft(string $email, string $purpose): int
    {
        return max(0, self::LIMITS[$purpose][0] - (int) Cache::get(self::counterKey($email, $purpose), 0));
    }

    /** Seconds until another code may be sent (0 = now). */
    public static function secondsUntilResend(string $email, string $purpose): int
    {
        $row = DB::table('email_codes')->where('email', self::norm($email))->where('purpose', $purpose)->first();
        if (! $row) {
            return 0;
        }

        return (int) max(0, self::RESEND_SECONDS - now()->diffInSeconds(Carbon::parse($row->created_at), true));
    }

    /** Is there a code for this email that can still be used? */
    public static function hasActive(string $email, string $purpose): bool
    {
        return DB::table('email_codes')->where('email', self::norm($email))->where('purpose', $purpose)
            ->where('expires_at', '>', now())->exists();
    }

    /**
     * Creates a new code (replacing any earlier one) and emails it.
     *
     * @return string 'sent' | 'cooldown' | 'limit' | 'failed'
     */
    public static function send(object $notifiable, string $email, string $purpose): string
    {
        if (self::secondsUntilResend($email, $purpose) > 0) {
            return 'cooldown';
        }
        if (self::sendsLeft($email, $purpose) === 0) {
            return 'limit';
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $key = ['email' => self::norm($email), 'purpose' => $purpose];

        DB::table('email_codes')->where($key)->delete();
        DB::table('email_codes')->insert($key + [
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::MINUTES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sent right away (not queued) so a mail failure is shown to the
        // person instead of a "code sent" that never arrives.
        try {
            $notifiable->notify(new EmailCodeNotification($code, $purpose));
        } catch (\Throwable $e) {
            Log::error('Email code failed to send', ['purpose' => $purpose, 'error' => $e->getMessage()]);
            DB::table('email_codes')->where($key)->delete();

            return 'failed';
        }

        $counter = self::counterKey($email, $purpose);
        Cache::add($counter, 0, self::LIMITS[$purpose][1]);
        Cache::increment($counter);

        return 'sent';
    }

    /**
     * Checks a typed code. A correct code is deleted (single use).
     *
     * @return string 'ok' | 'wrong' | 'locked' (too many wrong tries — code
     *                cancelled) | 'expired' | 'none' (no code / already used)
     */
    public static function check(string $email, string $purpose, string $code): string
    {
        $key = ['email' => self::norm($email), 'purpose' => $purpose];

        return DB::transaction(function () use ($key, $code) {
            $row = DB::table('email_codes')->where($key)->lockForUpdate()->first();

            if (! $row) {
                return 'none';
            }
            if (Carbon::parse($row->expires_at)->isPast()) {
                DB::table('email_codes')->where($key)->delete();

                return 'expired';
            }
            if (Hash::check(preg_replace('/\D/', '', $code), $row->code_hash)) {
                DB::table('email_codes')->where($key)->delete();

                return 'ok';
            }
            if ($row->attempts + 1 >= self::MAX_ATTEMPTS) {
                DB::table('email_codes')->where($key)->delete();

                return 'locked';
            }
            DB::table('email_codes')->where($key)->increment('attempts');

            return 'wrong';
        });
    }

    /** What to tell the person for a send() result other than 'sent'. */
    public static function sendMessage(string $result, string $email, string $purpose): string
    {
        return match ($result) {
            'cooldown' => 'Please wait '.self::secondsUntilResend($email, $purpose).' seconds before asking for another code.',
            'limit' => 'Too many codes requested. Please try again later.',
            default => 'We could not send the email right now. Please try again in a few minutes.',
        };
    }

    /** What to tell the person for a check() result other than 'ok'. */
    public static function checkMessage(string $result): string
    {
        return match ($result) {
            'wrong' => 'That code is not correct. Please check the latest email and try again.',
            'locked' => 'Too many wrong tries, so that code was cancelled. Please request a new code.',
            'expired' => 'That code has expired. Please request a new one.',
            default => 'That code is no longer valid. Please request a new code.',
        };
    }
}
