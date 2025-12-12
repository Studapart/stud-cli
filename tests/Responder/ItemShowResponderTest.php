<?php

namespace App\Tests\Responder;

use App\DTO\WorkItem;
use App\Responder\ItemShowResponder;
use App\Response\ItemShowResponse;
use App\Service\ColorHelper;
use App\Service\DescriptionFormatter;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemShowResponderTest extends CommandTestCase
{
    private ItemShowResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responder = new ItemShowResponder(
            $this->translationService,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            null,
            null
        );
    }

    public function testRespondDisplaysErrorWhenResponseIsNotSuccessful(): void
    {
        $response = ItemShowResponse::error('Issue not found');
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));
        $io->expects($this->once())
            ->method('error')
            ->with($this->callback(function ($message) {
                return is_string($message) && ! empty($message);
            }));
        $io->expects($this->never())
            ->method('definitionList');

        $this->responder->respond($io, $response, 'TPW-35');
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
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->atLeastOnce())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));
        $io->expects($this->once())
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
                $this->isInstanceOf(TableSeparator::class),
                $this->callback(function ($arg) {
                    return is_array($arg) && in_array('https://your-company.atlassian.net/browse/TPW-35', $arg, true);
                })
            );
        $io->expects($this->never())
            ->method('error');

        $this->responder->respond($io, $response, 'TPW-35');
    }

    public function testRespondDisplaysVerboseOutputWhenVerbose(): void
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
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(true);
        $io->expects($this->once())
            ->method('writeln')
            ->with($this->callback(function ($message) {
                return is_string($message) && str_contains($message, 'TPW-35');
            }));

        $this->responder->respond($io, $response, 'TPW-35');
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
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->atLeastOnce())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));
        $io->expects($this->atLeastOnce())
            ->method('text')
            ->with($this->callback(function ($content) {
                return is_array($content);
            }));

        $this->responder->respond($io, $response, 'TPW-35');
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
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));
        $io->expects($this->once())
            ->method('definitionList');
        $io->expects($this->never())
            ->method('text');

        $this->responder->respond($io, $response, 'TPW-35');
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
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
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
                $this->anything(),
                $this->anything()
            );

        $this->responder->respond($io, $response, 'TPW-35');
    }

    public function testRespondUsesCustomDescriptionFormatter(): void
    {
        $descriptionFormatter = $this->createMock(DescriptionFormatter::class);
        $responder = new ItemShowResponder(
            $this->translationService,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            $descriptionFormatter,
            null
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
        // Create a response with null issue (defensive check)
        $response = new \ReflectionClass(ItemShowResponse::class);
        $responseInstance = $response->newInstanceWithoutConstructor();
        $successProperty = $response->getProperty('success');
        $successProperty->setAccessible(true);
        $successProperty->setValue($responseInstance, true);
        $issueProperty = $response->getProperty('issue');
        $issueProperty->setAccessible(true);
        $issueProperty->setValue($responseInstance, null);

        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));
        $io->expects($this->never())
            ->method('definitionList');
        $io->expects($this->never())
            ->method('error');

        $this->responder->respond($io, $responseInstance, 'TPW-35');
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
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->atLeastOnce())
            ->method('section');
        $io->expects($this->once())
            ->method('definitionList');
        // May call listing or text depending on formatting
        $io->expects($this->any())
            ->method('listing')
            ->with($this->callback(function ($list) {
                return is_array($list);
            }));
        $io->expects($this->any())
            ->method('text')
            ->with($this->callback(function ($text) {
                return is_array($text);
            }));

        $this->responder->respond($io, $response, 'TPW-35');
    }

    public function testRespondWithColorHelperAppliesColors(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $responder = new ItemShowResponder(
            $this->translationService,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            null,
            $colorHelper
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

        $colorHelper->expects($this->once())
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
        $responder = new ItemShowResponder(
            $this->translationService,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            null,
            $colorHelper
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

        $colorHelper->expects($this->once())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->atLeastOnce())
            ->method('format')
            ->willReturnCallback(fn ($color, $text) => "<{$color}>{$text}</>");

        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(true);
        $io->expects($this->once())
            ->method('writeln')
            ->with($this->stringContains('TPW-35'));

        $responder->respond($io, $response, 'TPW-35');
    }

    public function testRespondWithColorHelperAndVerboseWithoutColorHelperUsesFallback(): void
    {
        $responder = new ItemShowResponder(
            $this->translationService,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            null,
            null
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

        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(true);
        $io->expects($this->once())
            ->method('writeln')
            ->with($this->stringContains('<fg=gray>'));

        $responder->respond($io, $response, 'TPW-35');
    }

    public function testDisplayDescriptionWithColorHelperAppliesColors(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $responder = new ItemShowResponder(
            $this->translationService,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            null,
            $colorHelper
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
        $io = $this->createMock(SymfonyStyle::class);

        $colorHelper->expects($this->once())
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
        $responder = new ItemShowResponder(
            $this->translationService,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            null,
            $colorHelper
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
        $io = $this->createMock(SymfonyStyle::class);

        $colorHelper->expects($this->once())
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
        $responder = new ItemShowResponder(
            $this->translationService,
            ['JIRA_URL' => 'https://your-company.atlassian.net'],
            $descriptionFormatter,
            $colorHelper
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

        $colorHelper->expects($this->once())
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
}
