<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Enum\OutputFormat;
use App\Responder\ConfluenceShowResponder;
use App\Response\ConfluenceShowResponse;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfluenceShowResponderTest extends CommandTestCase
{
    private SymfonyStyle&MockObject $io;
    private ConfluenceShowResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $helper = new ResponderHelper($this->translationService);
        $this->io = $this->createMock(SymfonyStyle::class);
        $logger = $this->createLogger($this->io);
        $this->responder = new ConfluenceShowResponder($helper, $logger);
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

    public function testRespondCliSuccessUsesPageViewConfig(): void
    {
        $response = ConfluenceShowResponse::success(
            '12345',
            'My Page',
            'https://example.com/wiki/pages/12345',
            'Body content'
        );
        $this->io->expects(self::atLeastOnce())->method('section')->with(self::anything());
        $this->io->expects(self::once())
            ->method('definitionList')
            ->with(self::anything(), self::anything(), self::anything());
        $this->io->expects(self::once())->method('text')->with(self::equalTo('Body content'));
        $this->io->expects(self::never())->method('error');

        $result = $this->responder->respond($this->io, $response, OutputFormat::Cli);

        self::assertNull($result);
    }

    public function testRespondCliSuccessWithoutBodyDoesNotCallText(): void
    {
        $response = ConfluenceShowResponse::success(
            '12345',
            'My Page',
            'https://example.com/wiki/pages/12345',
            ''
        );
        $this->io->expects(self::atLeastOnce())->method('section')->with(self::anything());
        $this->io->expects(self::once())->method('definitionList')->with(self::anything(), self::anything(), self::anything());
        $this->io->expects(self::never())->method('text');
        $this->io->expects(self::never())->method('error');

        $this->responder->respond($this->io, $response, OutputFormat::Cli);
    }

    public function testRespondCliErrorOutputsError(): void
    {
        $response = ConfluenceShowResponse::error('Something failed');
        $this->io->expects(self::once())->method('section')->with(self::anything());
        $this->io->expects(self::once())->method('error')->with('Something failed');
        $this->io->expects(self::never())->method('definitionList');

        $result = $this->responder->respond($this->io, $response, OutputFormat::Cli);

        self::assertNull($result);
    }
}
