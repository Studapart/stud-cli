<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PrCommentInputResolver;
use PHPUnit\Framework\TestCase;

class PrCommentInputResolverTest extends TestCase
{
    public function testResolveBuildsRequestFromArgument(): void
    {
        $request = (new PrCommentInputResolver())->resolve('message', 'target', true);

        $this->assertSame('message', $request->message);
        $this->assertSame('target', $request->replyTo);
        $this->assertTrue($request->resolve);
    }

    public function testResolveAllowsMissingMessage(): void
    {
        $request = (new PrCommentInputResolver())->resolve(null);

        $this->assertNull($request->message);
        $this->assertFalse($request->isReply());
    }

    public function testResolvePrefersStdinContent(): void
    {
        $resolver = new class () extends PrCommentInputResolver {
            protected function readStdin(): string
            {
                return 'stdin message';
            }
        };

        $request = $resolver->resolve('argument message');

        $this->assertSame('stdin message', $request->message);
    }
}
