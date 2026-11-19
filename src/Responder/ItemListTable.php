<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\ItemListResponse;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;

/**
 * Renders the item:list issue table, adding a provider column for multi-provider results.
 */
class ItemListTable
{
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
    ) {
    }

    public function render(ItemListResponse $response): void
    {
        $columns = [
            new Column('key', 'table.key', fn ($item) => $item->key),
            new Column('status', 'table.status', fn ($item) => $item->status),
            new Column('title', 'table.summary', fn ($item) => $item->title),
        ];
        if ($response->multiProvider) {
            $columns[] = new Column(
                'provider',
                'table.provider',
                fn ($item) => $this->providerLabelForIssue($response, $item->key),
            );
        }

        $viewConfig = new PageViewConfig([
            new Section('', [new TableBlock($columns)]),
        ], $this->helper->translator, $this->helper->colorHelper);

        $viewConfig->render($response->issues, $this->logger);
    }

    protected function providerLabelForIssue(ItemListResponse $response, string $issueKey): string
    {
        foreach ($response->issues as $index => $issue) {
            if ($issue->key === $issueKey) {
                return $response->issueProviders[$index] ?? '';
            }
        }

        return '';
    }
}
