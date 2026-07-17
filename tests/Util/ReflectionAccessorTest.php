<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\ReflectionAccessor;
use PHPUnit\Framework\TestCase;

final class ReflectionAccessorTest extends TestCase
{
    public function testEnsureAccessibleAllowsPrivateMethodInvoke(): void
    {
        $subject = new class () {
            private function secret(): string
            {
                return 'hidden';
            }
        };

        $method = new \ReflectionMethod($subject, 'secret');
        ReflectionAccessor::ensureAccessible($method);

        self::assertSame('hidden', $method->invoke($subject));
    }

    public function testEnsureAccessibleSkipsPublicMethod(): void
    {
        $subject = new class () {
            public function open(): string
            {
                return 'visible';
            }
        };

        $method = new \ReflectionMethod($subject, 'open');
        ReflectionAccessor::ensureAccessible($method);

        self::assertSame('visible', $method->invoke($subject));
    }

    public function testEnsureAccessibleAllowsPrivatePropertyRead(): void
    {
        $subject = new class () {
            private string $value = 'stored';
        };

        $property = new \ReflectionProperty($subject, 'value');
        ReflectionAccessor::ensureAccessible($property);

        self::assertSame('stored', $property->getValue($subject));
    }

    public function testEnsureAccessibleSkipsPublicProperty(): void
    {
        $subject = new class () {
            public string $value = 'open';
        };

        $property = new \ReflectionProperty($subject, 'value');
        ReflectionAccessor::ensureAccessible($property);

        self::assertSame('open', $property->getValue($subject));
    }
}
