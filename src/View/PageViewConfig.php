<?php

declare(strict_types=1);

namespace App\View;

use App\Service\ColorHelper;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class PageViewConfig implements ViewConfigInterface
{
    /**
     * @param Section[] $sections
     */
    public function __construct(
        private readonly array $sections,
        private readonly TranslationService $translator,
        private readonly ?ColorHelper $colorHelper = null
    ) {
    }

    public function getType(): string
    {
        return 'page';
    }

    /**
     * @param array<int, mixed> $dtos
     * @param array<string, mixed> $context
     */
    public function render(array $dtos, SymfonyStyle $io, array $context = []): void
    {
        if (empty($dtos)) {
            return;
        }

        // Register color styles before rendering
        if ($this->colorHelper !== null) {
            $this->colorHelper->registerStyles($io);
        }

        foreach ($this->sections as $section) {
            $this->renderSection($section, $dtos, $io, $context);
        }
    }

    /**
     * @param array<int, mixed> $dtos
     * @param array<string, mixed> $context
     */
    protected function renderSection(Section $section, array $dtos, SymfonyStyle $io, array $context): void
    {
        // Only create section if title is not empty (for table-only views, section is created by responder)
        if (! empty($section->title)) {
            $sectionTitle = $section->title;
            // Apply section_title color if ColorHelper is available
            if ($this->colorHelper !== null) {
                $sectionTitle = $this->colorHelper->format('section_title', $sectionTitle);
            }
            $io->section($sectionTitle);
        }

        $definitionItems = [];
        $contentItems = [];
        $tableBlocks = [];

        foreach ($section->items as $item) {
            if ($item instanceof DefinitionItem) {
                $definitionItems[] = $item;
            } elseif ($item instanceof Content) {
                $contentItems[] = $item;
            } elseif ($item instanceof TableBlock) {
                $tableBlocks[] = $item;
            }
        }

        // For definition lists and content, use first DTO (single item display)
        $dto = $dtos[0] ?? null;
        if ($dto !== null) {
            if (! empty($definitionItems)) {
                $this->renderDefinitionList($definitionItems, $dto, $io, $context);
            }

            foreach ($contentItems as $content) {
                $this->renderContent($content, $dto, $io, $context);
            }
        }

        // For table blocks, use all DTOs (list display)
        foreach ($tableBlocks as $tableBlock) {
            $this->renderTableBlock($tableBlock, $dtos, $io, $context);
        }
    }

    /**
     * @param DefinitionItem[] $items
     * @param array<string, mixed> $context
     */
    protected function renderDefinitionList(array $items, mixed $dto, SymfonyStyle $io, array $context): void
    {
        $definitionData = [];
        foreach ($items as $item) {
            $key = $this->translator->trans($item->translationKey);
            $value = ($item->valueExtractor)($dto, $context);

            // Apply colors if ColorHelper is available
            if ($this->colorHelper !== null) {
                $key = $this->colorHelper->format('definition_key', $key);
                if (is_string($value)) {
                    $value = $this->colorHelper->format('definition_value', $value);
                }
            }

            $definitionData[] = [$key => $value];
        }

        $io->definitionList(...$definitionData);
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function renderContent(Content $content, mixed $dto, SymfonyStyle $io, array $context): void
    {
        $extractor = $content->contentExtractor;
        if (! is_callable($extractor)) {
            return;
        }

        $contentData = $extractor($dto, $context);

        if ($content->formatter === 'listing' && is_array($contentData)) {
            // Apply listing_item color if ColorHelper is available
            if ($this->colorHelper !== null) {
                $contentData = array_map(fn ($item) => $this->colorHelper->format('listing_item', (string) $item), $contentData);
            }
            $io->listing($contentData);
        } elseif ($content->formatter === 'text' && is_array($contentData)) {
            // Apply text_content color if ColorHelper is available
            if ($this->colorHelper !== null) {
                $contentData = array_map(fn ($item) => $this->colorHelper->format('text_content', (string) $item), $contentData);
            }
            $io->text($contentData);
        } elseif (is_string($contentData)) {
            // Apply text_content color if ColorHelper is available
            if ($this->colorHelper !== null) {
                $contentData = $this->colorHelper->format('text_content', $contentData);
            }
            $io->text($contentData);
        }
    }

    /**
     * Renders a table block with an array of DTOs.
     *
     * @param array<int, mixed> $dtos
     * @param array<string, mixed> $context
     */
    protected function renderTableBlock(TableBlock $tableBlock, array $dtos, SymfonyStyle $io, array $context): void
    {
        $visibleColumns = $this->getVisibleColumns($tableBlock->columns, $dtos);
        $headers = $this->buildHeaders($visibleColumns);
        $rows = $this->buildRows($dtos, $visibleColumns, $context);

        $io->table($headers, $rows);
    }

    /**
     * @param Column[] $columns
     * @param array<int, mixed> $dtos
     * @return Column[]
     */
    protected function getVisibleColumns(array $columns, array $dtos): array
    {
        $visible = [];
        foreach ($columns as $column) {
            if ($this->shouldShowColumn($column, $dtos)) {
                $visible[] = $column;
            }
        }

        return $visible;
    }

    /**
     * @param Column[] $columns
     * @return array<string>
     */
    protected function buildHeaders(array $columns): array
    {
        $headers = [];
        foreach ($columns as $column) {
            $header = $this->translator->trans($column->translationKey);
            // Apply table_header color if ColorHelper is available
            if ($this->colorHelper !== null) {
                $header = $this->colorHelper->format('table_header', $header);
            }
            $headers[] = $header;
        }

        return $headers;
    }

    /**
     * @param array<int, mixed> $dtos
     * @param Column[] $columns
     * @param array<string, mixed> $context
     * @return array<int, array<string>>
     */
    protected function buildRows(array $dtos, array $columns, array $context): array
    {
        $rows = [];
        foreach ($dtos as $dto) {
            $row = [];
            foreach ($columns as $column) {
                $value = $this->extractValue($dto, $column, $context);
                $row[] = $value ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<int, mixed> $dtos
     */
    protected function shouldShowColumn(Column $column, array $dtos): bool
    {
        if ($column->condition === null) {
            return true;
        }

        foreach ($dtos as $dto) {
            if ($this->evaluateCondition($dto, $column->condition)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function extractValue(mixed $dto, Column $column, array $context): ?string
    {
        if ($column->formatter !== null) {
            // Determine parameter count safely
            $paramCount = $this->getFormatterParameterCount($column->formatter);

            // Formatters expect (dto, context) - pass DTO as first parameter
            // For backward compatibility with 1-parameter formatters that expect property value,
            // we extract the value first, but this is deprecated
            if ($paramCount === 1) {
                // Try passing DTO first (new standard for Responder formatters)
                /** @var callable(mixed): string|null $formatter */
                $formatter = $column->formatter;

                try {
                    $result = $formatter($dto);
                    if ($result !== null) {
                        return $result;
                    }
                } catch (\Throwable $e) {
                    // If that fails, fall back to passing property value (old test formatters)
                }
                // If result is null or exception occurred, try with property value
                $value = $this->getPropertyValue($dto, $column->property);

                return $formatter($value);
            }

            // Formatter expects (dto, context) - the standard
            /** @var callable(mixed, array<string, mixed>): string|null $formatter */
            $formatter = $column->formatter;

            return $formatter($dto, $context);
        }

        $value = $this->getPropertyValue($dto, $column->property);

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param mixed $formatter
     */
    private function getFormatterParameterCount(mixed $formatter): int
    {
        if ($formatter instanceof \Closure) {
            $reflection = new \ReflectionFunction($formatter);

            return $reflection->getNumberOfParameters();
        }

        if (is_string($formatter) && function_exists($formatter)) {
            $reflection = new \ReflectionFunction($formatter);

            return $reflection->getNumberOfParameters();
        }

        if (is_array($formatter) && count($formatter) === 2) {
            $reflection = new \ReflectionMethod($formatter[0], $formatter[1]);

            return $reflection->getNumberOfParameters();
        }

        // Default: assume 2 parameters (dto, context) for unknown callable types
        return 2;
    }

    protected function getPropertyValue(mixed $dto, string $property): mixed
    {
        if (is_object($dto) && property_exists($dto, $property)) {
            return $dto->$property;
        }

        if (is_array($dto) && array_key_exists($property, $dto)) {
            return $dto[$property];
        }

        return null;
    }

    protected function evaluateCondition(mixed $dto, string $condition): bool
    {
        $value = $this->getPropertyValue($dto, $condition);

        return $value !== null && $value !== '';
    }
}
