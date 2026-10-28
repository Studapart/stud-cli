<?php

namespace App\Tests\Responder;

use App\DTO\IssueAttachment;
use App\DTO\WorkItem;
use App\Enum\OutputFormat;
use App\Responder\ItemShowResponder;
use App\Response\ItemShowResponse;
use App\Service\ColorHelper;
use App\Service\DescriptionFormatter;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemShowResponderTest extends CommandTestCase
{
    private SymfonyStyle&\PHPUnit\Framework\MockObject\MockObject $io;

    private ItemShowResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $helper = new ResponderHelper($this->translationService);
        $this->io = $this->createMock(SymfonyStyle::class);
        $this->responder = new ItemShowResponder(
            $helper,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            $this->createLogger($this->io),
            null,
        );
    }

    public function testRespondDisplaysErrorWhenResponseIsNotSuccessful(): void
    {
        $response = ItemShowResponse::error('Issue not found');

        $this->io->expects($this->once())
            ->method('section')
            ->with('item.show.section {"key":"TPW-35"}');
        $this->io->expects($this->once())
            ->method('error')
            ->with('item.show.error_not_found {"key":"TPW-35"}');
        $this->io->expects($this->never())
            ->method('definitionList');

        $this->responder->respond($this->io, $response, 'TPW-35');
    }

    public function testRespondDisplaysDefinitionListWhenSuccessful(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test Title',
            'In Progress',
            'John Doe',
            'Description',
            ['label1', 'label2'],
            'Task',
            [],
            null
        );
        $response = ItemShowResponse::success($issue);

        $sections = [];
        $this->io->expects($this->atLeastOnce())
            ->method('section')
            ->willReturnCallback(function (string $title) use (&$sections): void {
                $sections[] = $title;
            });
        $this->io->expects($this->once())
            ->method('definitionList')
            ->with(
                ['item.show.label_key' => 'TPW-35'],
                ['item.show.label_title' => 'Test Title'],
                ['item.show.label_status' => 'In Progress'],
                ['item.show.label_assignee' => 'John Doe'],
                ['item.show.label_type' => 'Task'],
                ['item.show.label_labels' => 'label1, label2'],
                ['item.show.label_link' => 'https://your-company.atlassian.net/browse/TPW-35'],
                ['item.show.label_attachments' => 'item.show.label_none'],
            );
        $this->io->expects($this->never())
            ->method('error');

        $this->responder->respond($this->io, $response, 'TPW-35');

        $this->assertContains('item.show.section {"key":"TPW-35"}', $sections);
    }

    public function testRespondDisplaysVerboseOutputWhenVerbose(): void
    {
        $io = $this->createSymfonyStyle(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);
        $responder = new ItemShowResponder(
            new ResponderHelper($this->translationService),
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            new \App\Service\Logger($io, []),
            null,
        );
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test',
            'To Do',
            'User',
            'Description',
            [],
            'Task',
            [],
            null
        );
        $response = ItemShowResponse::success($issue);

        $responder->respond($io, $response, 'TPW-35');

        $output = $this->getOutput($io);
        $this->assertStringContainsString('item.show.fetching {"key":"TPW-35"}', $output);
    }

    public function testRespondDisplaysDescriptionSections(): void
    {
        $description = "Title: Test Feature\n\n---\n\nUser Story\nAs a developer";
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test',
            'To Do',
            'User',
            $description,
            [],
            'Task',
            [],
            null
        );
        $response = ItemShowResponse::success($issue);

        $sections = [];
        $this->io->expects($this->atLeastOnce())
            ->method('section')
            ->willReturnCallback(function (string $title) use (&$sections): void {
                $sections[] = $title;
            });
        $this->io->expects($this->atLeastOnce())
            ->method('text')
            ->with($this->callback(function ($content) {
                return is_array($content);
            }));

        $this->responder->respond($this->io, $response, 'TPW-35');

        $this->assertContains('item.show.section {"key":"TPW-35"}', $sections);
        $this->assertContains('Title: Test Feature', $sections);
        $this->assertContains('User Story', $sections);
    }

    public function testRespondHandlesDescriptionSectionWithEmptyContent(): void
    {
        $descriptionFormatter = $this->createMock(DescriptionFormatter::class);
        $descriptionFormatter->method('parseSections')
            ->with('Desc')
            ->willReturn([
                ['title' => 'SectionWithNoContent', 'contentLines' => []],
            ]);
        $helper = new ResponderHelper($this->translationService);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemShowResponder(
            $helper,
            ['JIRA_URL' => 'https://example.com'],
            $this->createLogger($io),
            $descriptionFormatter,
        );
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test',
            'To Do',
            'User',
            'Desc',
            [],
            'Task',
            [],
            null
        );
        $response = ItemShowResponse::success($issue);

        $sections = [];
        $io->expects($this->atLeastOnce())
            ->method('section')
            ->willReturnCallback(function (string $title) use (&$sections): void {
                $sections[] = $title;
            });
        $io->expects($this->once())
            ->method('definitionList');

        $responder->respond($io, $response, 'TPW-35');

        $this->assertContains('SectionWithNoContent', $sections);
    }

    public function testRespondHandlesEmptyDescription(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test',
            'To Do',
            'User',
            '',
            [],
            'Task',
            [],
            null
        );
        $response = ItemShowResponse::success($issue);

        $this->io->expects($this->once())
            ->method('section')
            ->with('item.show.section {"key":"TPW-35"}');
        $this->io->expects($this->once())
            ->method('definitionList');
        $this->io->expects($this->never())
            ->method('text');

        $this->responder->respond($this->io, $response, 'TPW-35');
    }

    public function testRespondDisplaysLabelsCorrectly(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test',
            'To Do',
            'User',
            'Description',
            [],
            'Task',
            [],
            null
        );
        $response = ItemShowResponse::success($issue);

        $this->io->expects($this->once())
            ->method('definitionList')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                ['item.show.label_labels' => 'item.show.label_none'],
                $this->anything(),
                ['item.show.label_attachments' => 'item.show.label_none'],
            );

        $this->responder->respond($this->io, $response, 'TPW-35');
    }

    public function testRespondUsesCustomDescriptionFormatter(): void
    {
        $descriptionFormatter = $this->createMock(DescriptionFormatter::class);
        $helper = new ResponderHelper($this->translationService);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemShowResponder(
            $helper,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            $this->createLogger($io),
            $descriptionFormatter,
        );

        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test',
            'To Do',
            'User',
            'Description',
            [],
            'Task',
            [],
            null
        );
        $response = ItemShowResponse::success($issue);

        $descriptionFormatter->expects($this->once())
            ->method('parseSections')
            ->with('Description')
            ->willReturn([
                [
                    'title' => 'Test Section',
                    'contentLines' => ['Content'],
                ],
            ]);

        $descriptionFormatter->expects($this->once())
            ->method('formatContentForDisplay')
            ->with(['Content'])
            ->willReturn(['lists' => [], 'text' => [['Content']]]);

        $responder->respond($io, $response, 'TPW-35');
    }

    public function testRespondHandlesNullIssueGracefully(): void
    {
        $response = new \ReflectionClass(ItemShowResponse::class);
        $responseInstance = $response->newInstanceWithoutConstructor();
        $successProperty = $response->getProperty('success');
        \App\Util\ReflectionAccessor::ensureAccessible($successProperty);
        $successProperty->setValue($responseInstance, true);
        $issueProperty = $response->getProperty('issue');
        \App\Util\ReflectionAccessor::ensureAccessible($issueProperty);
        $issueProperty->setValue($responseInstance, null);

        $this->io->expects($this->once())
            ->method('section')
            ->with('item.show.section {"key":"TPW-35"}');
        $this->io->expects($this->never())
            ->method('definitionList');
        $this->io->expects($this->never())
            ->method('error');

        $this->responder->respond($this->io, $responseInstance, 'TPW-35');
    }

    public function testRespondDisplaysContentWithListsAndText(): void
    {
        $description = "Section\nRegular text before\n[ ] Checkbox item";
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test',
            'To Do',
            'User',
            $description,
            [],
            'Task',
            [],
            null
        );
        $response = ItemShowResponse::success($issue);

        $this->io->expects($this->atLeastOnce())
            ->method('section');
        $this->io->expects($this->once())
            ->method('definitionList');
        $this->io->expects($this->atLeastOnce())
            ->method('listing')
            ->with($this->callback(function ($list) {
                return is_array($list);
            }));
        $this->io->expects($this->atLeastOnce())
            ->method('text')
            ->with($this->callback(function ($text) {
                return is_array($text);
            }));

        $this->responder->respond($this->io, $response, 'TPW-35');
    }

    public function testRespondWithColorHelperAppliesColors(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemShowResponder(
            $helper,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            $this->createLogger($io),
            null,
        );

        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test',
            'To Do',
            'User',
            'Description',
            [],
            'Task',
            [],
            null
        );
        $response = ItemShowResponse::success($issue);

        $colorHelper->expects($this->atLeastOnce())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->atLeastOnce())
            ->method('format')
            ->willReturnCallback(fn ($color, $text) => "<{$color}>{$text}</>");

        $io->expects($this->atLeastOnce())
            ->method('section');
        $io->expects($this->once())
            ->method('definitionList');

        $responder->respond($io, $response, 'TPW-35');
    }

    public function testRespondWithColorHelperAndVerboseAppliesColorsToVerboseMessage(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $colorHelper->expects($this->atLeastOnce())
            ->method('registerStyles')
            ->with($this->anything());
        $colorHelper->expects($this->atLeastOnce())
            ->method('format')
            ->willReturnCallback(fn ($color, $text) => "<{$color}>{$text}</>");

        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $io = $this->createSymfonyStyle(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);
        $responder = new ItemShowResponder(
            $helper,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            new \App\Service\Logger($io, []),
            null,
        );

        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test',
            'To Do',
            'User',
            'Description',
            [],
            'Task',
            [],
            null
        );
        $response = ItemShowResponse::success($issue);

        $responder->respond($io, $response, 'TPW-35');

        $output = $this->getOutput($io);
        $this->assertStringContainsString('item.show.fetching {"key":"TPW-35"}', $output);
    }

    public function testRespondWithColorHelperAndVerboseWithoutColorHelperUsesFallback(): void
    {
        $helper = new ResponderHelper($this->translationService);
        $io = $this->createSymfonyStyle(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);
        $responder = new ItemShowResponder(
            $helper,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            new \App\Service\Logger($io, []),
            null,
        );

        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test',
            'To Do',
            'User',
            'Description',
            [],
            'Task',
            [],
            null
        );
        $response = ItemShowResponse::success($issue);

        $responder->respond($io, $response, 'TPW-35');

        $output = $this->getOutput($io);
        $this->assertStringContainsString('item.show.fetching {"key":"TPW-35"}', $output);
    }

    public function testDisplayDescriptionWithColorHelperAppliesColors(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemShowResponder(
            $helper,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            $this->createLogger($io),
            null,
        );

        $description = "Title: Test Feature\n\n---\n\nUser Story\nAs a developer";
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test',
            'To Do',
            'User',
            $description,
            [],
            'Task',
            [],
            null
        );
        $response = ItemShowResponse::success($issue);

        $colorHelper->expects($this->atLeastOnce())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->atLeastOnce())
            ->method('format')
            ->willReturnCallback(fn ($color, $text) => "<{$color}>{$text}</>");

        $io->expects($this->atLeastOnce())
            ->method('section');
        $io->expects($this->any())
            ->method('text');

        $responder->respond($io, $response, 'TPW-35');
    }

    public function testDisplayContentWithColorHelperAppliesColorsToListing(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemShowResponder(
            $helper,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            $this->createLogger($io),
            null,
        );

        $description = "[ ] Checkbox item 1\n[ ] Checkbox item 2";
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test',
            'To Do',
            'User',
            $description,
            [],
            'Task',
            [],
            null
        );
        $response = ItemShowResponse::success($issue);

        $colorHelper->expects($this->atLeastOnce())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->atLeastOnce())
            ->method('format')
            ->willReturnCallback(fn ($color, $text) => "<{$color}>{$text}</>");

        $io->expects($this->atLeastOnce())
            ->method('section');
        $io->expects($this->atLeastOnce())
            ->method('listing')
            ->with($this->callback(function ($list) {
                return is_array($list);
            }));

        $responder->respond($io, $response, 'TPW-35');
    }

    public function testDisplayContentWithColorHelperAppliesColorsToTextArray(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $descriptionFormatter = $this->createMock(DescriptionFormatter::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemShowResponder(
            $helper,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            $this->createLogger($io),
            $descriptionFormatter,
        );

        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test',
            'To Do',
            'User',
            'Description',
            [],
            'Task',
            [],
            null
        );
        $response = ItemShowResponse::success($issue);

        $descriptionFormatter->expects($this->once())
            ->method('parseSections')
            ->willReturn([
                [
                    'title' => 'Test Section',
                    'contentLines' => ['Line 1', 'Line 2'],
                ],
            ]);

        $descriptionFormatter->expects($this->once())
            ->method('formatContentForDisplay')
            ->willReturn([
                'lists' => [],
                'text' => [['Line 1', 'Line 2']], // Array of text lines
            ]);

        $colorHelper->expects($this->atLeastOnce())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->atLeastOnce())
            ->method('format')
            ->willReturnCallback(fn ($color, $text) => "<{$color}>{$text}</>");

        $io->expects($this->atLeastOnce())
            ->method('section');
        $io->expects($this->atLeastOnce())
            ->method('text')
            ->with($this->callback(function ($text) {
                return is_array($text);
            }));

        $responder->respond($io, $response, 'TPW-35');
    }

    public function testRespondJsonReturnsSerializedIssue(): void
    {
        $attachments = [
            new IssueAttachment('100', 'doc.pdf', 99, 'https://jira/rest/attachment/content/100', 'application/pdf'),
        ];
        $issue = new WorkItem(
            '1',
            'PROJ-1',
            'Test',
            'Open',
            'user',
            '',
            [],
            'Story',
            [],
            null,
            null,
            $attachments
        );
        $response = ItemShowResponse::success($issue);

        $result = $this->responder->respond($this->io, $response, 'PROJ-1', OutputFormat::Json);

        $this->assertNotNull($result);
        $payload = $result->toPayload();
        $this->assertAgentJsonSuccess($payload);
        $this->assertSame('PROJ-1', $payload['data']['issue']['key']);
        $this->assertCount(1, $payload['data']['issue']['attachments']);
        $this->assertSame('doc.pdf', $payload['data']['issue']['attachments'][0]['filename']);
        $this->assertSame(99, $payload['data']['issue']['attachments'][0]['size']);
        $this->assertSame('https://jira/rest/attachment/content/100', $payload['data']['issue']['attachments'][0]['contentUrl']);
        $this->assertSame('application/pdf', $payload['data']['issue']['attachments'][0]['mimeType']);
        $this->assertArrayNotHasKey('renderedDescription', $payload['data']['issue']);
    }

    public function testRespondJsonOmitsRenderedDescriptionWhenPresent(): void
    {
        $issue = new WorkItem(
            '1',
            'PROJ-1',
            'Test',
            'Open',
            'user',
            'Plain description',
            [],
            'Story',
            [],
            null,
            '<p>HTML rendered</p>',
        );
        $response = ItemShowResponse::success($issue);

        $result = $this->responder->respond($this->io, $response, 'PROJ-1', OutputFormat::Json);

        $this->assertNotNull($result);
        $payload = $result->toPayload();
        $this->assertAgentJsonSuccess($payload);
        $this->assertSame('Plain description', $payload['data']['issue']['description']);
        $this->assertArrayNotHasKey('renderedDescription', $payload['data']['issue']);
    }

    public function testRespondJsonReturnsErrorWhenNotSuccess(): void
    {
        $response = ItemShowResponse::error('Not found');

        $result = $this->responder->respond($this->io, $response, 'PROJ-1', OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertAgentJsonErrorString($result->toPayload(), 'Not found');
    }
}
