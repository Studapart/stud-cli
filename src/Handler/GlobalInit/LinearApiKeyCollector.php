<?php

declare(strict_types=1);

namespace App\Handler\GlobalInit;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\Handler\InitPromptInputHelper;
use App\Service\GlobalConfigProviderResolver;

/**
 * Collects Linear API key for stud config:init (ADR-020).
 */
class LinearApiKeyCollector
{
    public function __construct(
        private readonly GlobalConfigProviderResolver $providerResolver,
        private readonly InitPromptInputHelper $inputHelper,
    ) {
    }

    /**
     * @return array{LINEAR_API_KEY: ?string}
     */
    public function collect(GlobalInitPromptContext $context): array
    {
        $active = $this->providerResolver->collectsLinear($context->workItemProviders);
        $existing = $context->existingConfig;

        if ($active && ! $context->isAgent) {
            $context->recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.linear.title'));
            $context->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.init.linear.description'));
        }

        $linearApiKeyPrompt = MessageRef::key('config.init.linear.api_key_prompt', [], 'Enter your Linear API key (leave blank to keep existing)');

        return [
            'LINEAR_API_KEY' => $this->inputHelper->resolveWhenActive(
                $active,
                $context->isAgent,
                $context->rawAgentInput,
                'linearApiKey',
                $existing['LINEAR_API_KEY'] ?? null,
                $linearApiKeyPrompt,
                hidden: true,
            ),
        ];
    }
}
