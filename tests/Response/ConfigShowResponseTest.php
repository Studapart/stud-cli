<?php

declare(strict_types=1);

namespace App\Tests\Response;

use App\Response\ConfigShowResponse;
use PHPUnit\Framework\TestCase;

class ConfigShowResponseTest extends TestCase
{
    public function testSuccessWithGlobalOnly(): void
    {
        $response = ConfigShowResponse::success(['LANGUAGE' => 'en']);

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->getError());
        $this->assertSame(['LANGUAGE' => 'en'], $response->globalConfig);
        $this->assertNull($response->projectConfig);
    }

    public function testSuccessWithGlobalAndProject(): void
    {
        $response = ConfigShowResponse::success(['LANGUAGE' => 'en'], ['projectKey' => 'PROJ']);

        $this->assertTrue($response->isSuccess());
        $this->assertSame(['projectKey' => 'PROJ'], $response->projectConfig);
    }

    public function testError(): void
    {
        $response = ConfigShowResponse::error('config.show.no_config_found');

        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.show.no_config_found', $response->getError());
        $this->assertEmpty($response->globalConfig);
        $this->assertNull($response->projectConfig);
    }
}
