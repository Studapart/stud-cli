<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ConfigValidateResponse;
use App\Service\ResponderHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders config:validate result: Jira and Git provider status (OK, Fail, or Skipped).
 */
class ConfigValidateResponder
{
    public function __construct(
        private readonly ResponderHelper $helper,
    ) {
    }

    public function respond(SymfonyStyle $io, ConfigValidateResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            return $this->respondJson($response);
        }

        if (! $response->isSuccess() && $response->getError() !== null) {
            $message = $this->helper->translator->trans($response->getError());
            $io->error(explode("\n", $message));

            return null;
        }

        $this->helper->initSection($io, 'config.validate.section_title');

        $jiraLabel = $this->helper->translator->trans('config.validate.label_jira');
        $gitLabel = $this->helper->translator->trans('config.validate.label_git_provider');
        $jiraValue = $this->formatStatus($response->jiraStatus, $response->jiraMessage);
        $gitValue = $this->formatStatus($response->gitStatus, $response->gitMessage);

        if ($this->helper->colorHelper !== null) {
            $jiraLabel = $this->helper->colorHelper->format('definition_key', $jiraLabel);
            $jiraValue = $this->helper->colorHelper->format('definition_value', $jiraValue);
            $gitLabel = $this->helper->colorHelper->format('definition_key', $gitLabel);
            $gitValue = $this->helper->colorHelper->format('definition_value', $gitValue);
        }

        $io->definitionList(
            [$jiraLabel => $jiraValue],
            [$gitLabel => $gitValue]
        );

        return null;
    }

    protected function respondJson(ConfigValidateResponse $response): AgentJsonResponse
    {
        if (! $response->isSuccess()) {
            return new AgentJsonResponse(false, error: $response->getError() ?? 'Validation failed');
        }

        return new AgentJsonResponse(true, data: [
            'jiraStatus' => $response->jiraStatus,
            'jiraMessage' => $response->jiraMessage,
            'gitStatus' => $response->gitStatus,
            'gitMessage' => $response->gitMessage,
        ]);
    }

    protected function formatStatus(string $status, ?string $message): string
    {
        if ($status === ConfigValidateResponse::STATUS_OK) {
            return $this->helper->translator->trans('config.validate.status_ok');
        }

        if ($status === ConfigValidateResponse::STATUS_SKIPPED) {
            return $this->helper->translator->trans('config.validate.status_skipped');
        }

        $failLabel = $this->helper->translator->trans('config.validate.status_fail');

        return $message !== null && $message !== ''
            ? $failLabel . ' (' . $message . ')'
            : $failLabel;
    }
}
