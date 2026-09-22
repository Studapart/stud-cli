<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CastorPackagingPolicy;
use PHPUnit\Framework\TestCase;

class CastorPackagingPolicyTest extends TestCase
{
    private mixed $previousServer;
    private mixed $previousEnv;
    private mixed $previousPutenv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousServer = $_SERVER[CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV] ?? null;
        $this->previousEnv = $_ENV[CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV] ?? null;
        $this->previousPutenv = getenv(CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV);
        unset($_SERVER[CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV], $_ENV[CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV]);
        putenv(CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV);
    }

    protected function tearDown(): void
    {
        if ($this->previousServer === null) {
            unset($_SERVER[CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV]);
        } else {
            $_SERVER[CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV] = $this->previousServer;
        }
        if ($this->previousEnv === null) {
            unset($_ENV[CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV]);
        } else {
            $_ENV[CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV] = $this->previousEnv;
        }
        if ($this->previousPutenv === false) {
            putenv(CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV);
        } else {
            putenv(CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV . '=' . $this->previousPutenv);
        }
        parent::tearDown();
    }

    public function testApplyWhenPackagedSetsEnvSuperglobals(): void
    {
        CastorPackagingPolicy::applyWhenPackaged(true);

        $this->assertSame('1', $_SERVER[CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV]);
        $this->assertSame('1', $_ENV[CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV]);
        $this->assertSame('1', getenv(CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV));
    }

    public function testApplyWhenNotPackagedLeavesEnvUntouched(): void
    {
        CastorPackagingPolicy::applyWhenPackaged(false);

        $this->assertArrayNotHasKey(CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV, $_SERVER);
        $this->assertArrayNotHasKey(CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV, $_ENV);
        $this->assertFalse(getenv(CastorPackagingPolicy::DISABLE_AGENT_DETECTION_ENV));
    }
}
