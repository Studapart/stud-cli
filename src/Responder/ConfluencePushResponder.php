<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ConfluencePushResponse;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfluencePushResponder
{
    public function __construct(
        private readonly TranslationService $translator
    ) {
    }

    public function respond(SymfonyStyle $io, ConfluencePushResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            if (! $response->isSuccess()) {
                return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
            }

            return new AgentJsonResponse(true, data: [
                'pageId' => $response->pageId,
                'title' => $response->title,
                'url' => $response->url,
                'action' => $response->action,
            ]);
        }

        if (! $response->isSuccess()) {
            $io->error($response->getError());

            return null;
        }

        $key = $response->action === 'created'
            ? 'confluence.push.success_created'
            : 'confluence.push.success_updated';
        $message = $this->translator->trans($key, [
            '%title%' => $response->title ?? '',
            '%url%' => $response->url ?? '',
        ]);
        $io->success($message);

        return null;
    }
}
