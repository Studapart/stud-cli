<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Handler\ItemBranchWorkspace;
use App\Handler\ItemStartBranchPlanner;
use App\Handler\ItemStartHandler;
use App\Handler\ItemStartWorkflows;
use App\Handler\ItemTakeoverHandler;
use App\Handler\ItemTrackerGateway;
use App\Handler\JiraItemStartWorkflow;
use App\Handler\LinearItemStartWorkflow;
use App\Service\GitRepository;
use App\Service\IssueTrackerPort;
use App\Service\LinearTypeLabelResolver;
use App\Service\Prompt\PromptInterface;

final class ItemStartHandlerTestKit
{
    /**
     * @param array<string, mixed> $jiraConfig
     */
    public static function create(
        ItemStartWorkflows $workflows,
        ItemStartBranchPlanner $planner,
        ItemBranchWorkspace $workspace,
        array $jiraConfig,
    ): ItemStartHandler {
        return new ItemStartHandler($workflows, $planner, $workspace, $jiraConfig);
    }

    public static function workflows(
        GitRepository $gitRepository,
        IssueTrackerPort $provider,
        PromptInterface $prompt,
        ?LinearTypeLabelResolver $linearTypeLabelResolver = null,
    ): ItemStartWorkflows {
        $tracker = new ItemTrackerGateway($provider);

        return new ItemStartWorkflows(
            $tracker,
            $gitRepository,
            new JiraItemStartWorkflow($tracker, $prompt, $gitRepository),
            new LinearItemStartWorkflow($tracker, $prompt, $gitRepository, $linearTypeLabelResolver ?? new LinearTypeLabelResolver()),
        );
    }

    public static function linear(
        GitRepository $gitRepository,
        IssueTrackerPort $provider,
        PromptInterface $prompt,
    ): LinearItemStartWorkflow {
        return new LinearItemStartWorkflow(
            new ItemTrackerGateway($provider),
            $prompt,
            $gitRepository,
            new LinearTypeLabelResolver(),
        );
    }

    public static function takeover(
        ItemBranchWorkspace $workspace,
        ItemStartWorkflows $workflows,
        ItemStartHandler $itemStartHandler,
        PromptInterface $prompt,
    ): ItemTakeoverHandler {
        return new ItemTakeoverHandler($workspace, $workflows, $itemStartHandler, $prompt);
    }
}
