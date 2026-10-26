<?php

namespace Tests\Feature;

use App\Http\Middleware\UseCloudflareClientIp;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/** The visitor's real IP behind the Cloudflare Tunnel (rate limits and login lockouts depend on it). */
class ClientIpTest extends TestCase
{
    /** request()->ip() after both middlewares, as in production with TRUSTED_PROXIES=*. */
    private function clientIp(string $remoteAddr, array $headers): string
    {
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $remoteAddr]);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        TrustProxies::at('*');
        $ip = null;
        $capture = function (Request $r) use (&$ip) {
            $ip = $r->ip();

            return response('');
        };
        (new UseCloudflareClientIp)->handle($request, fn (Request $r) => (new TrustProxies)->handle($r, $capture));

        return (string) $ip;
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    public function test_a_made_up_forwarded_for_header_is_ignored_behind_the_tunnel(): void
    {
        $this->assertSame('112.202.121.229', $this->clientIp('172.18.0.5', [
            'X-Forwarded-For' => '203.0.113.77, 112.202.121.229',
            'CF-Connecting-IP' => '112.202.121.229',
        ]));
    }

    public function test_works_for_the_windows_tunnel_service_on_this_machine(): void
    {
        $this->assertSame('112.202.121.229', $this->clientIp('127.0.0.1', [
            'X-Forwarded-For' => '203.0.113.77',
            'CF-Connecting-IP' => '112.202.121.229',
        ]));
    }

    public function test_cf_header_from_outside_the_tunnel_is_not_trusted(): void
    {
        // Someone reaching the app directly can't pick their IP with the Cloudflare header.
        $this->assertSame('198.51.100.9', $this->clientIp('198.51.100.9', ['CF-Connecting-IP' => '1.1.1.1']));
    }

    public function test_a_malformed_cf_header_is_ignored(): void
    {
        $this->assertNotSame('not-an-ip', $this->clientIp('172.18.0.5', ['CF-Connecting-IP' => 'not-an-ip']));
    }
}
