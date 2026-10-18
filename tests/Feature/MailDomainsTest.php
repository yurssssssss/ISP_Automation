<?php

namespace Tests\Feature;

use App\Support\MailDomains;
use Tests\TestCase;

class MailDomainsTest extends TestCase
{
    public function test_big_providers_are_accepted_without_a_lookup(): void
    {
        $this->assertTrue((new MailDomains)->receivesMail('GMail.com'));
    }

    public function test_test_domains_are_accepted_when_running_locally(): void
    {
        $this->app['env'] = 'local';

        $this->assertTrue((new MailDomains)->receivesMail('isp.test'));
    }
}
