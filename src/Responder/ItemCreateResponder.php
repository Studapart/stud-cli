<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\ItemCreateResponse;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemCreateResponder
{
    /**
     * @param array<string, string> $jiraConfig Must contain JIRA_URL
     */
    public function __construct(
        private readonly TranslationService $translator,
        private readonly array $jiraConfig
    ) {
    }

    public function respond(SymfonyStyle $io, ItemCreateResponse $response): void
    {
        if (! $response->isSuccess() || $response->key === null) {
            return;
        }
        $baseUrl = rtrim($this->jiraConfig['JIRA_URL'] ?? '', '/');
        $url = $baseUrl !== '' ? $baseUrl . '/browse/' . $response->key : $response->self;
        $message = $this->translator->trans('item.create.success', [
            'key' => $response->key,
            'url' => $url,
        ]);
        $io->success($message);
        $skipped = $response->skippedOptionalFields ?? [];
        if ($skipped !== []) {
            $io->note($this->translator->trans('item.create.note_skipped_optional_fields', ['%fields%' => implode(', ', $skipped)]));
        }
    }
}
