<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\ProviderFanOutResult;
use PHPUnit\Framework\TestCase;

final class ProviderFanOutResultTest extends TestCase
{
    public function testIsMultiProviderWhenItemsComeFromSeveralProviders(): void
    {
        $result = new ProviderFanOutResult(['a', 'b'], ['jira', 'linear'], []);

        $this->assertTrue($result->isMultiProvider());
    }

    public function testIsNotMultiProviderWhenItemsComeFromOneProvider(): void
    {
        $result = new ProviderFanOutResult(['a', 'b'], ['jira', 'jira'], []);

        $this->assertFalse($result->isMultiProvider());
    }

    public function testIsNotMultiProviderWithoutItems(): void
    {
        $result = new ProviderFanOutResult([], [], []);

        $this->assertFalse($result->isMultiProvider());
    }
}
