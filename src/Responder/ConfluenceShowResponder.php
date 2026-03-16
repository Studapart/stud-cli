<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ConfluenceShowResponse;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfluenceShowResponder
{
    public function respond(SymfonyStyle $io, ConfluenceShowResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            if (! $response->isSuccess()) {
                return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
            }

            return new AgentJsonResponse(true, data: [
                'id' => $response->id,
                'title' => $response->title,
                'url' => $response->url,
                'body' => $response->body,
            ]);
        }

        if (! $response->isSuccess()) {
            $io->error($response->getError());

            return null;
        }

        $io->title($response->title ?? '');
        $io->writeln('<info>URL:</info> ' . ($response->url ?? ''));
        if ($response->body !== null && $response->body !== '') {
            $io->section('Content');
            $io->writeln($response->body);
        }

        return null;
    }
}
