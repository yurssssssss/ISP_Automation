<?php

namespace App\Console\Commands;

use App\Services\MikroTik\RouterGateway;
use App\Services\MikroTik\RouterOsException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mikrotik:test {username? : A PPPoE/hotspot user to look up} {--service=pppoe : pppoe or hotspot}')]
#[Description('Check the connection to the MikroTik router (and optionally look up one account)')]
class MikroTikTest extends Command
{
    public function handle(RouterGateway $router): int
    {
        $config = config('isp.mikrotik');
        $this->line("Connecting to {$config['host']}:{$config['port']}".($config['ssl'] ? ' (API-SSL)' : '').' as '.$config['username'].' ...');

        try {
            $this->info('Connected. Router identity: '.$router->identity());

            if ($username = $this->argument('username')) {
                $service = $this->option('service');
                $account = $router->findAccount($username, $service);

                if (! $account) {
                    $this->warn("No {$service} account named \"{$username}\".");

                    return self::FAILURE;
                }

                $this->table(['Field', 'Value'], collect($account)
                    ->except(['password'])
                    ->map(fn ($value, $key) => [$key, $value])
                    ->values()
                    ->all());
            }
        } catch (RouterOsException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
