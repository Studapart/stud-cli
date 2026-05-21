<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\PrCommentsResponse;
use App\Service\CommentBodyParser;
use App\Service\DtoSerializer;
use App\Service\Logger;
use App\Service\ResponderHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Coordinates PR/MR comments output while delegating concrete presentation concerns.
 */
class PrCommentsResponder
{
    private readonly PrCommentsJsonSerializer $jsonSerializer;
    private readonly PrFlatCommentsRenderer $flatRenderer;
    private readonly PrThreadedConversationRenderer $threadedRenderer;

    public function __construct(
        private readonly ResponderHelper $helper,
        CommentBodyParser $bodyParser,
        private readonly Logger $logger,
        ?DtoSerializer $serializer = null,
    ) {
        $bodyRenderer = new PrCommentBodyRenderer($this->helper, $bodyParser, $this->logger);
        $this->jsonSerializer = new PrCommentsJsonSerializer($serializer ?? new DtoSerializer());
        $this->flatRenderer = new PrFlatCommentsRenderer($this->helper, $this->logger, $bodyRenderer);
        $this->threadedRenderer = new PrThreadedConversationRenderer($this->helper, $this->logger, $bodyRenderer);
    }

    public function respond(
        SymfonyStyle $io,
        PrCommentsResponse $response,
        OutputFormat $format = OutputFormat::Cli,
        bool $threaded = false,
    ): ?AgentJsonResponse {
        $useThreaded = $threaded || $response->threaded;
        if ($format === OutputFormat::Json) {
            return $this->jsonSerializer->serialize($response, $useThreaded);
        }

        $this->helper->initSection($this->logger, 'pr.comments.section', ['number' => $response->pullNumber]);

        if ($useThreaded) {
            $this->threadedRenderer->render($response);

            return null;
        }

        $this->flatRenderer->render($response);

        return null;
    }
}
