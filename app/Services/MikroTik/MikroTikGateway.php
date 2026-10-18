<?php

namespace App\Services\MikroTik;

/**
 * RouterGateway over the RouterOS API. Connects on first use and keeps the
 * connection for the rest of this object's life (one job = one login).
 */
class MikroTikGateway implements RouterGateway
{
    /** Menu paths and the field naming the user in the active list. */
    private const MENUS = [
        'pppoe' => ['accounts' => '/ppp/secret', 'active' => '/ppp/active', 'active_name' => 'name'],
        'hotspot' => ['accounts' => '/ip/hotspot/user', 'active' => '/ip/hotspot/active', 'active_name' => 'user'],
    ];

    private ?RouterOsClient $client = null;

    public function __construct(private array $config) {}

    public static function fromConfig(): self
    {
        return new self(config('isp.mikrotik'));
    }

    public function identity(): string
    {
        return $this->api()->talk('/system/identity/print')[0]['name'] ?? '(unnamed)';
    }

    public function findAccount(string $username, string $service = 'pppoe'): ?array
    {
        return $this->api()->talk(self::menu($service)['accounts'].'/print', [], ['name' => $username])[0] ?? null;
    }

    public function createAccount(string $username, string $password, string $profile, string $service = 'pppoe', ?string $comment = null): void
    {
        $attributes = ['name' => $username, 'password' => $password, 'profile' => $profile];
        if ($service === 'pppoe') {
            $attributes['service'] = 'pppoe';
        }
        if ($comment) {
            $attributes['comment'] = $comment;
        }

        $this->api()->talk(self::menu($service)['accounts'].'/add', $attributes);
    }

    public function setDisabled(string $username, bool $disabled, string $service = 'pppoe'): void
    {
        $this->set($username, $service, ['disabled' => $disabled]);
    }

    public function setProfile(string $username, string $profile, string $service = 'pppoe'): void
    {
        $this->set($username, $service, ['profile' => $profile]);
    }

    public function kick(string $username, string $service = 'pppoe'): int
    {
        $menu = self::menu($service);
        $sessions = $this->api()->talk($menu['active'].'/print', [], [$menu['active_name'] => $username]);

        foreach ($sessions as $session) {
            $this->api()->talk($menu['active'].'/remove', ['.id' => $session['.id']]);
        }

        return count($sessions);
    }

    private function set(string $username, string $service, array $attributes): void
    {
        $account = $this->findAccount($username, $service);
        if (! $account) {
            throw new RouterOsException("No {$service} account named \"{$username}\" on the router.");
        }

        $this->api()->talk(self::menu($service)['accounts'].'/set', ['.id' => $account['.id']] + $attributes);
    }

    private static function menu(string $service): array
    {
        return self::MENUS[$service] ?? throw new \InvalidArgumentException("Unknown service type: {$service}");
    }

    private function api(): RouterOsClient
    {
        if (! $this->client) {
            $client = new RouterOsClient(
                $this->config['host'],
                $this->config['port'],
                $this->config['ssl'],
                $this->config['ssl_verify'],
                $this->config['timeout'],
            );
            $client->connect($this->config['username'], (string) $this->config['password']);
            $this->client = $client;
        }

        return $this->client;
    }
}
