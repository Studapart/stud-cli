<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ConfigValidateResponse;
use App\Service\Logger;
use App\Service\ResponderHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders config:validate result: Jira and Git provider status (OK, Fail, or Skipped).
 */
class ConfigValidateResponder
{
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger
    ) {
    }

    public function respond(SymfonyStyle $io, ConfigValidateResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            return $this->respondJson($response);
        }

        if (! $response->isSuccess() && $response->getError() !== null) {
            $message = $this->helper->translator->trans($response->getError());
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $message));

            return null;
        }

        $this->helper->initSection($this->logger, 'config.validate.section_title');

        $jiraLabel = $this->helper->translator->trans('config.validate.label_jira');
        $gitLabel = $this->helper->translator->trans('config.validate.label_git_provider');
        $linearLabel = $this->helper->translator->trans('config.validate.label_linear');
        $jiraValue = $this->formatStatus($response->jiraStatus, $response->jiraMessage);
        $gitValue = $this->formatStatus($response->gitStatus, $response->gitMessage);
        $linearValue = $this->formatStatus($response->linearStatus, $response->linearMessage);

        if ($this->helper->colorHelper !== null) {
            $jiraLabel = $this->helper->colorHelper->format('definition_key', $jiraLabel);
            $jiraValue = $this->helper->colorHelper->format('definition_value', $jiraValue);
            $gitLabel = $this->helper->colorHelper->format('definition_key', $gitLabel);
            $gitValue = $this->helper->colorHelper->format('definition_value', $gitValue);
            $linearLabel = $this->helper->colorHelper->format('definition_key', $linearLabel);
            $linearValue = $this->helper->colorHelper->format('definition_value', $linearValue);
        }

        $this->logger->definitionList(
            Logger::VERBOSITY_NORMAL,
            [$jiraLabel => $jiraValue],
            [$gitLabel => $gitValue],
            [$linearLabel => $linearValue],
        );

        foreach ($response->getWarnings() as $warning) {
            $message = $warning->message;
            $text = $message instanceof \App\DTO\MessageRef
                ? $this->helper->translator->trans($message->key, $message->parameters)
                : (string) $message;
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $text);
        }

        return null;
    }

    protected function respondJson(ConfigValidateResponse $response): AgentJsonResponse
    {
        $diagnostics = $response->diagnosticsPayload();

        if (! $response->isSuccess()) {
            $error = $response->getError() ?? 'Validation failed';

            return new AgentJsonResponse(
                false,
                error: $this->helper->translator->transForAgentText($error),
                diagnostics: $diagnostics,
            );
        }

        return new AgentJsonResponse(true, data: [
            'jiraStatus' => $response->jiraStatus,
            'jiraMessage' => $response->jiraMessage,
            'gitStatus' => $response->gitStatus,
            'gitMessage' => $response->gitMessage,
            'linearStatus' => $response->linearStatus,
            'linearMessage' => $response->linearMessage,
        ], diagnostics: $diagnostics);
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
