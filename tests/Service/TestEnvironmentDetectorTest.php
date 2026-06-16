<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TestEnvironmentDetector;
use PHPUnit\Framework\TestCase;

class TestEnvironmentDetectorTest extends TestCase
{
    public function testIsTestEnvironmentWhenConstantSet(): void
    {
        $detector = new TestEnvironmentDetector();

        $this->assertTrue($detector->isTestEnvironment());
        $this->assertTrue($detector->isTestEnvironmentByConstant());
    }

    public function testIsTestEnvironmentByClassOrEnv(): void
    {
        $detector = new TestEnvironmentDetector();

        $this->assertTrue($detector->isTestEnvironmentByClassOrEnv());
    }

    public function testIsTestEnvironmentUsesFallbackDetectors(): void
    {
        $detector = new class () extends TestEnvironmentDetector {
            public function isTestEnvironmentByConstant(): bool
            {
                return false;
            }

            public function isTestEnvironmentByBacktrace(): bool
            {
                return false;
            }
        };

        $this->assertTrue($detector->isTestEnvironment());
    }

    public function testIsTestEnvironmentReturnsFalseWhenNoSignals(): void
    {
        $detector = new class () extends TestEnvironmentDetector {
            public function isTestEnvironmentByConstant(): bool
            {
                return false;
            }

            public function isTestEnvironmentByBacktrace(): bool
            {
                return false;
            }

            public function isTestEnvironmentByClassOrEnv(): bool
            {
                return false;
            }
        };

        $this->assertFalse($detector->isTestEnvironment());
    }
}
