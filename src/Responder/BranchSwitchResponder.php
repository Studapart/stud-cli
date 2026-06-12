<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\BranchSwitchResponse;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\View\DefinitionItem;
use App\View\PageViewConfig;
use App\View\Section;
use Symfony\Component\Console\Style\SymfonyStyle;

class BranchSwitchResponder
{
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger
    ) {
    }

    public function respond(SymfonyStyle $io, BranchSwitchResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            return $this->respondJson($response);
        }

        if (! $response->isSuccess()) {
            $this->respondCliError($response);

            return null;
        }

        $this->helper->initSection($this->logger, 'branch.switch.section', ['key' => $response->key]);
        $viewConfig = new PageViewConfig([
            new Section('', [
                new DefinitionItem('branch.switch.label_branch', fn (BranchSwitchResponse $item): string => $item->branch ?? ''),
                new DefinitionItem('branch.switch.label_synced', fn (BranchSwitchResponse $item): string => $this->helper->translator->trans($item->synced ? 'branch.switch.value_yes' : 'branch.switch.value_no')),
            ]),
        ], $this->helper->translator, $this->helper->colorHelper);
        $viewConfig->render([$response], $this->logger);
        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->helper->translator->trans('branch.switch.success', ['branch' => $response->branch ?? '']));

        return null;
    }

    protected function respondJson(BranchSwitchResponse $response): AgentJsonResponse
    {
        if (! $response->isSuccess()) {
            return new AgentJsonResponse(false, error: $this->helper->translator->renderForAgentText($response->getErrorMessage() ?? 'Unknown error'));
        }

        return new AgentJsonResponse(true, data: [
            'key' => $response->key,
            'branch' => $response->branch,
            'matches' => $response->matches,
            'switched' => $response->switched,
            'synced' => $response->synced,
            'syncExitCode' => $response->syncExitCode,
        ]);
    }

    protected function respondCliError(BranchSwitchResponse $response): void
    {
        $error = $response->getError();
        if ($error !== null) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $error);

            return;
        }

        if ($response->needsSelection) {
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->helper->translator->trans('branch.switch.multiple_branches', ['key' => $response->key]));
            $this->logger->listing(Logger::VERBOSITY_NORMAL, $response->matches);
        }
    }
}
