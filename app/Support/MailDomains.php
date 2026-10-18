<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Whether an email provider (the part after @) exists and can receive mail:
 * it has an MX record, or at least an A record. Big providers skip the
 * lookup (as do .test addresses when running locally); any other domain is
 * looked up at most once a day.
 */
class MailDomains
{
    public const KNOWN = [
        'gmail.com', 'yahoo.com', 'yahoo.com.ph', 'ymail.com', 'outlook.com', 'hotmail.com',
        'live.com', 'icloud.com', 'me.com', 'mail.com', 'aol.com', 'proton.me', 'protonmail.com',
    ];

    public function receivesMail(string $domain): bool
    {
        $domain = strtolower($domain);

        if (in_array($domain, self::KNOWN, true)) {
            return true;
        }

        // Local test accounts like admin@isp.test: these names never resolve.
        if (app()->isLocal() && preg_match('/\.(test|localhost)$/', $domain)) {
            return true;
        }

        return Cache::remember('mail-domain:'.$domain, now()->addDay(),
            fn (): bool => checkdnsrr($domain.'.', 'MX') || checkdnsrr($domain.'.', 'A'));
    }
}
