<?php

namespace App\Services\MikroTik;

/**
 * What the billing side needs from the router. Bound to MikroTikGateway in
 * AppServiceProvider; tests swap in a fake so no router is needed.
 *
 * $service is 'pppoe' (/ppp/secret) or 'hotspot' (/ip/hotspot/user).
 */
interface RouterGateway
{
    /**
     * Whether a router has been set up at all (address and login filled in).
     * Until then router changes are skipped, not retried as failures.
     */
    public function isConfigured(): bool;

    /** The router's /system identity name — used to test the connection. */
    public function identity(): string;

    /** The account row (name, profile, disabled, ...) or null if missing. */
    public function findAccount(string $username, string $service = 'pppoe'): ?array;

    public function createAccount(string $username, string $password, string $profile, string $service = 'pppoe', ?string $comment = null): void;

    public function setDisabled(string $username, bool $disabled, string $service = 'pppoe'): void;

    public function setProfile(string $username, string $profile, string $service = 'pppoe'): void;

    /** Drops the user's live session(s); returns how many were removed. */
    public function kick(string $username, string $service = 'pppoe'): int;
}
