<?php

namespace App\Tests\Responder;

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
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));
        $this->io->expects($this->once())
            ->method('error')
            ->with($this->callback(function ($message) {
                return is_string($message) && ! empty($message);
            }));
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

        $this->io->expects($this->atLeastOnce())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));
        $this->io->expects($this->once())
            ->method('definitionList')
            ->with(
                $this->callback(function ($arg) {
                    return is_array($arg) && in_array('TPW-35', $arg, true);
                }),
                $this->callback(function ($arg) {
                    return is_array($arg) && in_array('Test Title', $arg, true);
                }),
                $this->callback(function ($arg) {
                    return is_array($arg) && in_array('In Progress', $arg, true);
                }),
                $this->callback(function ($arg) {
                    return is_array($arg) && in_array('John Doe', $arg, true);
                }),
                $this->callback(function ($arg) {
                    return is_array($arg) && in_array('Task', $arg, true);
                }),
                $this->callback(function ($arg) {
                    return is_array($arg) && ! empty($arg);
                }),
                $this->callback(function ($arg) {
                    return is_array($arg) && in_array('https://your-company.atlassian.net/browse/TPW-35', $arg, true);
                })
            );
        $this->io->expects($this->never())
            ->method('error');

        $this->responder->respond($this->io, $response, 'TPW-35');
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
        $this->assertStringContainsString('TPW-35', $output);
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

        $this->io->expects($this->atLeastOnce())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));
        $this->io->expects($this->atLeastOnce())
            ->method('text')
            ->with($this->callback(function ($content) {
                return is_array($content);
            }));

        $this->responder->respond($this->io, $response, 'TPW-35');
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

        $io->expects($this->atLeastOnce())
            ->method('section')
            ->with($this->callback(fn ($t) => is_string($t) && $t !== ''));
        $io->expects($this->once())
            ->method('definitionList');

        $responder->respond($io, $response, 'TPW-35');
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
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));
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
                $this->callback(function ($arg) {
                    // Should contain translated "none" label
                    return is_array($arg) && ! empty($arg);
                }),
                $this->anything()
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
        $io = $this->createMock(SymfonyStyle::class);

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
        $successProperty->setAccessible(true);
        $successProperty->setValue($responseInstance, true);
        $issueProperty = $response->getProperty('issue');
        $issueProperty->setAccessible(true);
        $issueProperty->setValue($responseInstance, null);

        $this->io->expects($this->once())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));
        $this->io->expects($this->never())
            ->method('definitionList');
        $this->io->expects($this->never())
            ->method('error');

        $this->responder->respond($this->io, $responseInstance, 'TPW-35');
    }

    public function testRespondDisplaysContentWithListsAndText(): void
    {
        $description = "Regular text before\n[ ] Checkbox item\nRegular text after";
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
        $this->io->expects($this->any())
            ->method('listing')
            ->with($this->callback(function ($list) {
                return is_array($list);
            }));
        $this->io->expects($this->any())
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
        $this->assertStringContainsString('TPW-35', $output);
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
        $this->assertStringContainsString('TPW-35', $output);
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
        $issue = new WorkItem('1', 'PROJ-1', 'Test', 'Open', 'user', '', [], 'Story');
        $response = ItemShowResponse::success($issue);
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($this->io, $response, 'PROJ-1', OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertSame('PROJ-1', $result->data['issue']['key']);
    }

    public function testRespondJsonReturnsErrorWhenNotSuccess(): void
    {
        $response = ItemShowResponse::error('Not found');
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($this->io, $response, 'PROJ-1', OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
    }
}
