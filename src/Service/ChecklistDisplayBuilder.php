<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Groups description lines into checkbox lists (with indented sub-items) and plain text blocks.
 */
final class ChecklistDisplayBuilder
{
    /** @var array<int, array<int, string>> */
    private array $lists = [];

    /** @var array<int, array<int, string>> */
    private array $text = [];

    /** @var array<int, string> */
    private array $currentList = [];

    /** @var array<int, string> */
    private array $currentText = [];

    private ?string $currentListItem = null;

    /** @var array<int, string> */
    private array $currentSubItems = [];

    public function startCheckboxItem(string $item): void
    {
        $this->flushCurrentListItem();
        $this->flushCurrentText();
        $this->currentListItem = $item;
    }

    public function addNonCheckboxLine(string $trimmed): void
    {
        if ($this->currentListItem !== null) {
            if ($trimmed !== '') {
                $this->currentSubItems[] = $trimmed;
            }

            return;
        }
        if ($trimmed !== '') {
            $this->currentText[] = $trimmed;
        } elseif ($this->currentText !== []) {
            $this->currentText[] = '';
        }
    }

    /**
     * @return array{lists: array<array<string>>, text: array<array<string>>}
     */
    public function build(): array
    {
        $this->flushCurrentListItem();
        if ($this->currentList !== []) {
            $this->lists[] = $this->currentList;
        }
        $this->flushCurrentText();

        return ['lists' => $this->lists, 'text' => $this->text];
    }

    private function flushCurrentText(): void
    {
        if ($this->currentText !== []) {
            $this->text[] = $this->currentText;
            $this->currentText = [];
        }
    }

    private function flushCurrentListItem(): void
    {
        if ($this->currentListItem === null) {
            return;
        }
        $formatted = $this->currentListItem;
        foreach ($this->currentSubItems as $sub) {
            $formatted .= "\n  - " . $sub;
        }
        $this->currentList[] = $formatted;
        $this->currentListItem = null;
        $this->currentSubItems = [];
    }
}
