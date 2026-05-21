<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\PrCommentResponse;
use App\Service\DtoSerializer;
use App\Service\Logger;
use App\Service\ResponderHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

class PrCommentResponder
{
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
        private readonly DtoSerializer $serializer = new DtoSerializer(),
    ) {
    }

    public function respond(
        SymfonyStyle $io,
        PrCommentResponse $response,
        OutputFormat $format = OutputFormat::Cli,
    ): ?AgentJsonResponse {
        if ($format === OutputFormat::Json) {
            return $this->serialize($response);
        }

        if (! $response->isSuccess()) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $response->getError() ?? '');

            return null;
        }

        $this->helper->initSection($this->logger, 'pr.comment.section');
        $this->logger->success(Logger::VERBOSITY_NORMAL, $response->message);

        return null;
    }

    protected function serialize(PrCommentResponse $response): AgentJsonResponse
    {
        if (! $response->isSuccess()) {
            return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
        }

        return new AgentJsonResponse(true, data: $this->serializer->serialize($response));
    }
}
