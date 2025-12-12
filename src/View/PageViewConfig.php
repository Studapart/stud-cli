<?php

declare(strict_types=1);

namespace App\View;

use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class PageViewConfig implements ViewConfigInterface
{
    /**
     * @param Section[] $sections
     */
    public function __construct(
        private readonly array $sections,
        private readonly TranslationService $translator
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

        $dto = $dtos[0];

        foreach ($this->sections as $section) {
            $this->renderSection($section, $dto, $io, $context);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function renderSection(Section $section, mixed $dto, SymfonyStyle $io, array $context): void
    {
        $io->section($section->title);

        $definitionItems = [];
        $contentItems = [];

        foreach ($section->items as $item) {
            if ($item instanceof DefinitionItem) {
                $definitionItems[] = $item;
            } elseif ($item instanceof Content) {
                $contentItems[] = $item;
            }
        }

        if (! empty($definitionItems)) {
            $this->renderDefinitionList($definitionItems, $dto, $io, $context);
        }

        foreach ($contentItems as $content) {
            $this->renderContent($content, $dto, $io, $context);
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
            $io->listing($contentData);
        } elseif ($content->formatter === 'text' && is_array($contentData)) {
            $io->text($contentData);
        } elseif (is_string($contentData)) {
            $io->text($contentData);
        }
    }
}
