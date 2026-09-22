<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\HttpClientDefaults;
use PHPUnit\Framework\TestCase;

class HttpClientDefaultsTest extends TestCase
{
    public function testWithLimitsAddsDefaultTimeouts(): void
    {
        $options = HttpClientDefaults::withLimits(['headers' => ['Accept' => 'application/json']]);

        $this->assertSame(HttpClientDefaults::TIMEOUT_SECONDS, $options['timeout']);
        $this->assertSame(HttpClientDefaults::MAX_DURATION_SECONDS, $options['max_duration']);
        $this->assertSame(['Accept' => 'application/json'], $options['headers']);
    }

    public function testWithLimitsKeepsExplicitTimeouts(): void
    {
        $options = HttpClientDefaults::withLimits([
            'timeout' => 5.0,
            'max_duration' => 9.0,
        ]);

        $this->assertSame(5.0, $options['timeout']);
        $this->assertSame(9.0, $options['max_duration']);
    }

    public function testWithTransferLimitsAddsIdleTimeoutWithoutMaxDuration(): void
    {
        $options = HttpClientDefaults::withTransferLimits(['headers' => ['Accept' => 'application/octet-stream']]);

        $this->assertSame(HttpClientDefaults::TIMEOUT_SECONDS, $options['timeout']);
        $this->assertSame(0.0, $options['max_duration']);
        $this->assertSame(['Accept' => 'application/octet-stream'], $options['headers']);
    }

    public function testWithTransferLimitsKeepsExplicitTimeout(): void
    {
        $options = HttpClientDefaults::withTransferLimits(['timeout' => 12.0]);

        $this->assertSame(12.0, $options['timeout']);
        $this->assertSame(0.0, $options['max_duration']);
    }
}
