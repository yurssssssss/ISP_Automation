<?php

namespace Tests\Fakes;

use App\Services\MikroTik\RouterGateway;
use App\Services\MikroTik\RouterOsException;

/** In-memory router: accounts keyed by name, plus a log of every call. */
class FakeRouter implements RouterGateway
{
    public array $accounts = [];

    public array $active = [];

    public array $calls = [];

    public bool $down = false;

    /** Passwords of accounts made with createAccount(), by username. */
    public array $passwords = [];

    /** false = behave like a router whose address and login aren't filled in yet. */
    public bool $configured = true;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function addAccount(string $username, string $profile = '20M', bool $disabled = false, bool $online = true): void
    {
        $this->accounts[$username] = ['.id' => '*'.(count($this->accounts) + 1), 'name' => $username, 'profile' => $profile, 'disabled' => $disabled ? 'true' : 'false'];
        if ($online) {
            $this->active[$username] = true;
        }
    }

    private function call(string $what): void
    {
        if ($this->down) {
            throw new RouterOsException('Cannot reach the router.');
        }
        $this->calls[] = $what;
    }

    public function identity(): string
    {
        $this->call('identity');

        return 'FakeRouter';
    }

    public function findAccount(string $username, string $service = 'pppoe'): ?array
    {
        $this->call("find {$username}");

        return $this->accounts[$username] ?? null;
    }

    public function createAccount(string $username, string $password, string $profile, string $service = 'pppoe', ?string $comment = null): void
    {
        $this->call("create {$username}");
        $this->addAccount($username, $profile, online: false);
        $this->passwords[$username] = $password;
    }

    public function setDisabled(string $username, bool $disabled, string $service = 'pppoe'): void
    {
        $this->call(($disabled ? 'disable ' : 'enable ').$username);
        $this->accounts[$username]['disabled'] = $disabled ? 'true' : 'false';
    }

    public function setProfile(string $username, string $profile, string $service = 'pppoe'): void
    {
        $this->call("profile {$username} {$profile}");
        $this->accounts[$username]['profile'] = $profile;
    }

    public function kick(string $username, string $service = 'pppoe'): int
    {
        $this->call("kick {$username}");
        $was = isset($this->active[$username]);
        unset($this->active[$username]);

        return (int) $was;
    }
}
