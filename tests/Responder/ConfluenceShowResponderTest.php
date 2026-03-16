<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Enum\OutputFormat;
use App\Responder\ConfluenceShowResponder;
use App\Response\ConfluenceShowResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfluenceShowResponderTest extends TestCase
{
    private SymfonyStyle&MockObject $io;
    private ConfluenceShowResponder $responder;

    protected function setUp(): void
    {
        $this->io = $this->createMock(SymfonyStyle::class);
        $this->responder = new ConfluenceShowResponder();
    }

    public function testRespondJsonSuccessReturnsAgentJsonResponseWithData(): void
    {
        $response = ConfluenceShowResponse::success(
            '12345',
            'My Page',
            'https://example.com/wiki/pages/12345',
            '# Content\n\nBody text.'
        );

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        self::assertNotNull($result);
        self::assertTrue($result->success);
        self::assertSame(['id' => '12345', 'title' => 'My Page', 'url' => 'https://example.com/wiki/pages/12345', 'body' => '# Content\n\nBody text.'], $result->data);
    }

    public function testRespondJsonErrorReturnsAgentJsonResponseWithError(): void
    {
        $response = ConfluenceShowResponse::error('Page not found');

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        self::assertNotNull($result);
        self::assertFalse($result->success);
        self::assertSame('Page not found', $result->error);
    }

    public function testRespondCliSuccessOutputsTitleUrlAndBody(): void
    {
        $response = ConfluenceShowResponse::success(
            '12345',
            'My Page',
            'https://example.com/wiki/pages/12345',
            'Body content'
        );
        $this->io->expects(self::once())->method('title')->with('My Page');
        $this->io->expects(self::exactly(2))->method('writeln')->with(self::anything());
        $this->io->expects(self::once())->method('section')->with('Content');

        $result = $this->responder->respond($this->io, $response, OutputFormat::Cli);

        self::assertNull($result);
    }

    public function testRespondCliErrorOutputsError(): void
    {
        $response = ConfluenceShowResponse::error('Something failed');
        $this->io->expects(self::once())->method('error')->with('Something failed');

        $result = $this->responder->respond($this->io, $response, OutputFormat::Cli);

        self::assertNull($result);
    }
}
