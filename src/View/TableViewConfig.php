<?php

declare(strict_types=1);

namespace App\View;

use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class TableViewConfig implements ViewConfigInterface
{
    /**
     * @param Column[] $columns
     */
    public function __construct(
        private readonly array $columns,
        private readonly TranslationService $translator
    ) {
    }

    public function getType(): string
    {
        return 'table';
    }

    /**
     * @param array<int, mixed> $dtos
     * @param array<string, mixed> $context
     */
    public function render(array $dtos, SymfonyStyle $io, array $context = []): void
    {
        $visibleColumns = $this->getVisibleColumns($dtos);
        $headers = $this->buildHeaders($visibleColumns);
        $rows = $this->buildRows($dtos, $visibleColumns, $context);

        $io->table($headers, $rows);
    }

    /**
     * @param array<int, mixed> $dtos
     * @return Column[]
     */
    protected function getVisibleColumns(array $dtos): array
    {
        $visible = [];
        foreach ($this->columns as $column) {
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
            $headers[] = $this->translator->trans($column->translationKey);
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
