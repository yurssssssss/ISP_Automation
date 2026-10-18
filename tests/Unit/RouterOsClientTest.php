<?php

namespace Tests\Unit;

use App\Services\MikroTik\RouterOsClient;
use PHPUnit\Framework\TestCase;

class RouterOsClientTest extends TestCase
{
    public function test_word_length_encoding_matches_the_routeros_spec(): void
    {
        $this->assertSame("\x00", RouterOsClient::encodeLength(0));
        $this->assertSame("\x7F", RouterOsClient::encodeLength(0x7F));
        $this->assertSame("\x80\x80", RouterOsClient::encodeLength(0x80));
        $this->assertSame("\xBF\xFF", RouterOsClient::encodeLength(0x3FFF));
        $this->assertSame("\xC0\x40\x00", RouterOsClient::encodeLength(0x4000));
        $this->assertSame("\xDF\xFF\xFF", RouterOsClient::encodeLength(0x1FFFFF));
        $this->assertSame("\xE0\x20\x00\x00", RouterOsClient::encodeLength(0x200000));
        $this->assertSame("\xF0\x10\x00\x00\x00", RouterOsClient::encodeLength(0x10000000));
    }
}
