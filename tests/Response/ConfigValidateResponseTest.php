<?php

declare(strict_types=1);

namespace App\Tests\Response;

use App\Response\ConfigValidateResponse;
use PHPUnit\Framework\TestCase;

class ConfigValidateResponseTest extends TestCase
{
    public function testCreateAllOk(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_OK,
            null,
            ConfigValidateResponse::STATUS_OK,
            null
        );

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->getError());
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->gitStatus);
    }

    public function testCreateJiraFailMakesResponseNotSuccess(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_FAIL,
            'Connection refused',
            ConfigValidateResponse::STATUS_OK,
            null
        );

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->jiraStatus);
        $this->assertSame('Connection refused', $response->jiraMessage);
    }

    public function testCreateGitFailMakesResponseNotSuccess(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_OK,
            null,
            ConfigValidateResponse::STATUS_FAIL,
            'Unauthorized'
        );

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->gitStatus);
        $this->assertSame('Unauthorized', $response->gitMessage);
    }

    public function testCreateBothSkippedIsSuccess(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_SKIPPED,
            null,
            ConfigValidateResponse::STATUS_SKIPPED,
            null
        );

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->gitStatus);
    }

    public function testError(): void
    {
        $response = ConfigValidateResponse::error('config.error.not_found');

        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.error.not_found', $response->getError());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->gitStatus);
    }
}
