<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\AgentJsonResponse;
use App\Response\PrCommentsResponse;
use App\Service\DtoSerializer;

/**
 * Serializes PR/MR comments responses for agent-mode JSON consumers.
 */
class PrCommentsJsonSerializer
{
    public function __construct(
        private readonly DtoSerializer $serializer,
    ) {
    }

    public function serialize(PrCommentsResponse $response, bool $threaded): AgentJsonResponse
    {
        if (! $response->isSuccess()) {
            return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
        }

        if ($threaded) {
            return new AgentJsonResponse(true, data: [
                'mode' => 'threaded',
                'pullNumber' => $response->pullNumber,
                'conversations' => $this->serializer->serializeList($response->conversations),
            ]);
        }

        return new AgentJsonResponse(true, data: [
            'issueComments' => $this->serializer->serializeList($response->issueComments),
            'reviewComments' => $this->serializer->serializeList($response->reviewComments),
            'reviews' => $this->serializer->serializeList($response->reviews),
            'pullNumber' => $response->pullNumber,
        ]);
    }
}
