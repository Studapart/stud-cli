<?php

declare(strict_types=1);

namespace App\Handler\GlobalInit;

use App\Service\GitTokenPromptResolver;
use App\Service\GlobalConfigProviderResolver;
use App\Service\Prompt\PromptInterface;

final class InitPromptCollectors
{
    public function __construct(
        public readonly \App\Handler\InitPromptInputHelper $inputHelper,
        public readonly JiraCredentialsCollector $jira,
        public readonly LinearApiKeyCollector $linear,
        public readonly GitProviderTokensCollector $git,
    ) {
    }

    public static function defaults(
        PromptInterface $prompt,
        GitTokenPromptResolver $gitTokenPromptResolver,
        GlobalConfigProviderResolver $providerResolver,
    ): self {
        $inputHelper = new \App\Handler\InitPromptInputHelper($prompt);

        return new self(
            $inputHelper,
            new JiraCredentialsCollector($providerResolver, $inputHelper, $prompt),
            new LinearApiKeyCollector($providerResolver, $inputHelper),
            new GitProviderTokensCollector($providerResolver, $inputHelper, $gitTokenPromptResolver, $prompt),
        );
    }
}
