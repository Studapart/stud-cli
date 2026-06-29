<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\LinearTypeLabelException;
use PHPUnit\Framework\TestCase;

class LinearTypeLabelExceptionTest extends TestCase
{
    public function testGroupNotConfigured(): void
    {
        $exception = LinearTypeLabelException::groupNotConfigured();

        $this->assertSame('item.create.linear_type_group_not_configured', $exception->messageRef->key);
    }

    public function testLabelNotFound(): void
    {
        $exception = LinearTypeLabelException::labelNotFound('Bug');

        $this->assertSame('item.create.linear_type_label_not_found', $exception->messageRef->key);
        $this->assertSame('Bug', $exception->messageRef->parameters['type']);
    }

    public function testResolverUnavailable(): void
    {
        $exception = LinearTypeLabelException::resolverUnavailable();

        $this->assertSame('item.create.linear_type_resolver_unavailable', $exception->messageRef->key);
    }
}
