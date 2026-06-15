<?php

declare(strict_types=1);

namespace App\Service;

class TestEnvironmentDetector
{
    public function isTestEnvironment(): bool
    {
        if ($this->isTestEnvironmentByConstant()) {
            return true;
        }
        // @codeCoverageIgnoreStart
        if ($this->isTestEnvironmentByBacktrace()) {
            return true;
        }
        // @codeCoverageIgnoreEnd
        if ($this->isTestEnvironmentByClassOrEnv()) {
            return true;
        }

        return false;
    }

    public function isTestEnvironmentByConstant(): bool
    {
        return defined('STUD_CLI_TEST_MODE') && STUD_CLI_TEST_MODE === true;
    }

    /**
     * @codeCoverageIgnore Backtrace/env detection not reachable when running from PHPUnit
     */
    public function isTestEnvironmentByBacktrace(): bool
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);
        foreach ($backtrace as $frame) {
            if (! isset($frame['class'])) {
                continue;
            }
            if (str_contains($frame['class'], 'PHPUnit')) {
                return true;
            }
            if ($this->isTestCaseSubclass($frame['class'])) {
                return true;
            }
            if (str_starts_with($frame['function'], 'test')
                && str_contains($frame['class'], 'Test')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @codeCoverageIgnore Fallback detection when backtrace does not hit
     */
    public function isTestEnvironmentByClassOrEnv(): bool
    {
        if (class_exists(\PHPUnit\Framework\TestCase::class, true)) {
            return true;
        }
        if (defined('PHPUNIT')) {
            return true;
        }
        if (getenv('PHPUNIT') !== false) {
            return true;
        }
        $appEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null);

        return $appEnv !== null && strtolower($appEnv) === 'test';
    }

    /**
     * @codeCoverageIgnore Backtrace/env detection not reachable when running from PHPUnit
     */
    private function isTestCaseSubclass(string $className): bool
    {
        try {
            /** @var class-string $className */
            return (new \ReflectionClass($className))->isSubclassOf(\PHPUnit\Framework\TestCase::class);
        } catch (\ReflectionException) {
            return false;
        }
    }
}
