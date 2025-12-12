<?php

namespace App\Tests\View;

use App\DTO\WorkItem;
use App\Service\ColorHelper;
use App\Tests\CommandTestCase;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class TableViewConfigTest extends CommandTestCase
{
    private PageViewConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $columns = [
            new Column('key', 'table.key'),
            new Column('status', 'table.status'),
            new Column('priority', 'table.priority', null, 'priority'),
        ];

        $this->config = new PageViewConfig([
            new Section('', [new TableBlock($columns)]),
        ], $this->translationService, null);
    }

    public function testGetTypeReturnsPage(): void
    {
        $this->assertSame('page', $this->config->getType());
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
        $config = new PageViewConfig([
            new Section('', [new TableBlock($columns)]),
        ], $this->translationService, null);

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
        $config = new PageViewConfig([
            new Section('', [new TableBlock($columns)]),
        ], $this->translationService, null);
        $issues = [
            new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task'),
        ];

        $result = $this->callPrivateMethod($config, 'getVisibleColumns', [$columns, $issues]);

        $this->assertCount(1, $result);
        $this->assertSame('key', $result[0]->property);
    }

    public function testExtractValueWithTwoParameterFormatter(): void
    {
        $formatter = fn ($item, array $context) => $context['prefix'] . $item->key;
        $column = new Column('key', 'table.key', $formatter);
        $dto = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $context = ['prefix' => 'KEY-'];
        $result = $this->callPrivateMethod($this->config, 'extractValue', [$dto, $column, $context]);

        $this->assertSame('KEY-TPW-1', $result);
    }

    public function testExtractValueWithOneParameterFormatterThatThrows(): void
    {
        $formatter = function ($item) {
            // Throw when called with DTO object, but work when called with string value
            if (is_object($item)) {
                throw new \RuntimeException('Cannot process object');
            }

            return 'Formatted: ' . (string) $item;
        };
        $column = new Column('key', 'table.key', $formatter);
        $dto = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $result = $this->callPrivateMethod($this->config, 'extractValue', [$dto, $column, []]);

        // Should fall back to property value formatter
        $this->assertSame('Formatted: TPW-1', $result);
    }

    public function testExtractValueWithOneParameterFormatterThatReturnsNull(): void
    {
        $formatter = function ($item) {
            // Return null when called with DTO object, but work when called with string value
            if (is_object($item)) {
                return null;
            }

            return 'Formatted: ' . (string) $item;
        };
        $column = new Column('key', 'table.key', $formatter);
        $dto = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $result = $this->callPrivateMethod($this->config, 'extractValue', [$dto, $column, []]);

        // Should fall back to property value formatter (formatter is called with property value)
        $this->assertSame('Formatted: TPW-1', $result);
    }

    public function testGetFormatterParameterCountWithClosure(): void
    {
        $formatter = fn ($item) => $item->key;
        $result = $this->callPrivateMethod($this->config, 'getFormatterParameterCount', [$formatter]);

        $this->assertSame(1, $result);
    }

    public function testGetFormatterParameterCountWithStringFunction(): void
    {
        $result = $this->callPrivateMethod($this->config, 'getFormatterParameterCount', ['strlen']);

        $this->assertSame(1, $result);
    }

    public function testGetFormatterParameterCountWithArrayMethod(): void
    {
        $formatter = [$this->config, 'getType'];
        $result = $this->callPrivateMethod($this->config, 'getFormatterParameterCount', [$formatter]);

        $this->assertSame(0, $result);
    }

    public function testGetFormatterParameterCountWithUnknownType(): void
    {
        $formatter = new \stdClass();
        $result = $this->callPrivateMethod($this->config, 'getFormatterParameterCount', [$formatter]);

        // Should default to 2 parameters
        $this->assertSame(2, $result);
    }

    public function testGetFormatterParameterCountWithNonExistentStringFunction(): void
    {
        $result = $this->callPrivateMethod($this->config, 'getFormatterParameterCount', ['nonexistent_function_12345']);

        // Should default to 2 parameters when function doesn't exist
        $this->assertSame(2, $result);
    }

    public function testGetFormatterParameterCountWithInvalidArray(): void
    {
        $formatter = ['not', 'valid', 'array'];
        $result = $this->callPrivateMethod($this->config, 'getFormatterParameterCount', [$formatter]);

        // Should default to 2 parameters for invalid array
        $this->assertSame(2, $result);
    }

    public function testRenderTableWithColorHelperAppliesColorsToHeaders(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $columns = [
            new Column('key', 'table.key'),
        ];
        $config = new PageViewConfig([
            new Section('', [new TableBlock($columns)]),
        ], $this->translationService, $colorHelper);

        $issues = [
            new WorkItem('1', 'TPW-1', 'Title 1', 'To Do', 'User', 'desc', [], 'Task'),
        ];

        $io = $this->createMock(SymfonyStyle::class);
        $this->translationService->expects($this->once())
            ->method('trans')
            ->with('table.key')
            ->willReturn('Key');

        $colorHelper->expects($this->once())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->once())
            ->method('format')
            ->with('table_header', $this->anything())
            ->willReturnCallback(function ($color, $text) {
                return "<{$color}>{$text}</>";
            });

        $io->expects($this->once())
            ->method('table')
            ->with(
                $this->callback(function ($headers) {
                    return is_array($headers) && count($headers) === 1 && str_contains($headers[0], 'table_header');
                }),
                $this->anything()
            );

        $config->render($issues, $io);
    }
}
