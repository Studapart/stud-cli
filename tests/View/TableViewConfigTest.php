<?php

namespace App\Tests\View;

use App\DTO\WorkItem;
use App\Tests\CommandTestCase;
use App\View\Column;
use App\View\TableViewConfig;
use Symfony\Component\Console\Style\SymfonyStyle;

class TableViewConfigTest extends CommandTestCase
{
    private TableViewConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $columns = [
            new Column('key', 'table.key'),
            new Column('status', 'table.status'),
            new Column('priority', 'table.priority', null, 'priority'),
        ];

        $this->config = new TableViewConfig($columns, $this->translationService);
    }

    public function testGetTypeReturnsTable(): void
    {
        $this->assertSame('table', $this->config->getType());
    }

    public function testRenderCallsTableWithCorrectHeadersAndRows(): void
    {
        $issues = [
            new WorkItem('1', 'TPW-1', 'Title 1', 'To Do', 'User', 'desc', [], 'Task'),
        ];

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('table')
            ->with(
                $this->callback(function ($headers) {
                    return is_array($headers) && count($headers) === 2;
                }),
                $this->callback(function ($rows) {
                    return is_array($rows) &&
                        count($rows) === 1 &&
                        count($rows[0]) === 2 &&
                        $rows[0][0] === 'TPW-1' &&
                        $rows[0][1] === 'To Do';
                })
            );

        $this->config->render($issues, $io);
    }

    public function testConditionalColumnVisibilityShowsPriorityWhenPresent(): void
    {
        $issues = [
            new WorkItem('1', 'TPW-1', 'Title 1', 'To Do', 'User', 'desc', [], 'Task', [], 'High'),
        ];

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('table')
            ->with(
                $this->callback(function ($headers) {
                    return is_array($headers) && count($headers) === 3;
                }),
                $this->callback(function ($rows) {
                    return is_array($rows) &&
                        count($rows) === 1 &&
                        count($rows[0]) === 3 &&
                        $rows[0][2] === 'High';
                })
            );

        $this->config->render($issues, $io);
    }

    public function testConditionalColumnVisibilityHidesPriorityWhenAbsent(): void
    {
        $issues = [
            new WorkItem('1', 'TPW-1', 'Title 1', 'To Do', 'User', 'desc', [], 'Task'),
        ];

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('table')
            ->with(
                $this->callback(function ($headers) {
                    return is_array($headers) && count($headers) === 2;
                }),
                $this->callback(function ($rows) {
                    return is_array($rows) &&
                        count($rows) === 1 &&
                        count($rows[0]) === 2;
                })
            );

        $this->config->render($issues, $io);
    }

    public function testColumnFormattersAreAppliedCorrectly(): void
    {
        $formatter = fn ($value) => 'Formatted: ' . (string) $value;
        $columns = [
            new Column('key', 'table.key', $formatter),
        ];
        $config = new TableViewConfig($columns, $this->translationService);

        $issues = [
            new WorkItem('1', 'TPW-1', 'Title 1', 'To Do', 'User', 'desc', [], 'Task'),
        ];

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('table')
            ->with(
                $this->anything(),
                $this->callback(function ($rows) {
                    return is_array($rows) &&
                        count($rows) === 1 &&
                        $rows[0][0] === 'Formatted: TPW-1';
                })
            );

        $config->render($issues, $io);
    }

    public function testBuildHeadersUsesTranslationService(): void
    {
        $columns = [
            new Column('key', 'table.key'),
        ];

        $this->translationService->expects($this->once())
            ->method('trans')
            ->with('table.key')
            ->willReturn('Key');

        $headers = $this->callPrivateMethod($this->config, 'buildHeaders', [$columns]);

        $this->assertSame(['table.key'], $headers);
    }

    public function testShouldShowColumnReturnsTrueWhenNoCondition(): void
    {
        $column = new Column('key', 'table.key');
        $issues = [new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task')];

        $result = $this->callPrivateMethod($this->config, 'shouldShowColumn', [$column, $issues]);

        $this->assertTrue($result);
    }

    public function testShouldShowColumnReturnsTrueWhenConditionMet(): void
    {
        $column = new Column('priority', 'table.priority', null, 'priority');
        $issues = [
            new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task', [], 'High'),
        ];

        $result = $this->callPrivateMethod($this->config, 'shouldShowColumn', [$column, $issues]);

        $this->assertTrue($result);
    }

    public function testShouldShowColumnReturnsFalseWhenConditionNotMet(): void
    {
        $column = new Column('priority', 'table.priority', null, 'priority');
        $issues = [
            new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task'),
        ];

        $result = $this->callPrivateMethod($this->config, 'shouldShowColumn', [$column, $issues]);

        $this->assertFalse($result);
    }

    public function testGetPropertyValueWithArrayDto(): void
    {
        $dto = ['key' => 'TPW-1', 'status' => 'To Do'];
        $result = $this->callPrivateMethod($this->config, 'getPropertyValue', [$dto, 'key']);

        $this->assertSame('TPW-1', $result);
    }

    public function testGetPropertyValueWithObjectDto(): void
    {
        $dto = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $result = $this->callPrivateMethod($this->config, 'getPropertyValue', [$dto, 'key']);

        $this->assertSame('TPW-1', $result);
    }

    public function testGetPropertyValueReturnsNullWhenPropertyNotFound(): void
    {
        $dto = ['key' => 'TPW-1'];
        $result = $this->callPrivateMethod($this->config, 'getPropertyValue', [$dto, 'nonexistent']);

        $this->assertNull($result);
    }

    public function testEvaluateConditionReturnsFalseForEmptyString(): void
    {
        $dto = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $dto->priority = '';
        $result = $this->callPrivateMethod($this->config, 'evaluateCondition', [$dto, 'priority']);

        $this->assertFalse($result);
    }

    public function testExtractValueReturnsNullWhenValueIsNull(): void
    {
        $column = new Column('nonexistent', 'table.key');
        $dto = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $result = $this->callPrivateMethod($this->config, 'extractValue', [$dto, $column, []]);

        $this->assertNull($result);
    }

    public function testExtractValueConvertsValueToString(): void
    {
        $column = new Column('key', 'table.key');
        $dto = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $result = $this->callPrivateMethod($this->config, 'extractValue', [$dto, $column, []]);

        $this->assertSame('TPW-1', $result);
    }

    public function testGetVisibleColumnsFiltersColumns(): void
    {
        $columns = [
            new Column('key', 'table.key'),
            new Column('priority', 'table.priority', null, 'priority'),
        ];
        $config = new TableViewConfig($columns, $this->translationService);
        $issues = [
            new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task'),
        ];

        $result = $this->callPrivateMethod($config, 'getVisibleColumns', [$issues]);

        $this->assertCount(1, $result);
        $this->assertSame('key', $result[0]->property);
    }
}
