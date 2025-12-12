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
        $value = $this->getPropertyValue($dto, $column->property);

        if ($column->formatter !== null) {
            return ($column->formatter)($value, $context);
        }

        if ($value === null) {
            return null;
        }

        return (string) $value;
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
