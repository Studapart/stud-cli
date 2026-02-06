<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\ConfigValidateResponse;
use App\Service\ColorHelper;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders config:validate result: Jira and Git provider status (OK, Fail, or Skipped).
 */
class ConfigValidateResponder
{
    public function __construct(
        private readonly TranslationService $translator,
        private readonly ?ColorHelper $colorHelper = null
    ) {
    }

    public function respond(SymfonyStyle $io, ConfigValidateResponse $response): void
    {
        if ($this->colorHelper !== null) {
            $this->colorHelper->registerStyles($io);
        }

        if (! $response->isSuccess() && $response->getError() !== null) {
            $message = $this->translator->trans($response->getError());
            $io->error(explode("\n", $message));

            return;
        }

        $sectionTitle = $this->translator->trans('config.validate.section_title');
        if ($this->colorHelper !== null) {
            $sectionTitle = $this->colorHelper->format('section_title', $sectionTitle);
        }
        $io->section($sectionTitle);

        $jiraLabel = $this->translator->trans('config.validate.label_jira');
        $gitLabel = $this->translator->trans('config.validate.label_git_provider');
        $jiraValue = $this->formatStatus($response->jiraStatus, $response->jiraMessage);
        $gitValue = $this->formatStatus($response->gitStatus, $response->gitMessage);

        if ($this->colorHelper !== null) {
            $jiraLabel = $this->colorHelper->format('definition_key', $jiraLabel);
            $jiraValue = $this->colorHelper->format('definition_value', $jiraValue);
            $gitLabel = $this->colorHelper->format('definition_key', $gitLabel);
            $gitValue = $this->colorHelper->format('definition_value', $gitValue);
        }

        $io->definitionList(
            [$jiraLabel => $jiraValue],
            [$gitLabel => $gitValue]
        );
    }

    protected function formatStatus(string $status, ?string $message): string
    {
        if ($status === ConfigValidateResponse::STATUS_OK) {
            return $this->translator->trans('config.validate.status_ok');
        }

        if ($status === ConfigValidateResponse::STATUS_SKIPPED) {
            return $this->translator->trans('config.validate.status_skipped');
        }

        $failLabel = $this->translator->trans('config.validate.status_fail');

        return $message !== null && $message !== ''
            ? $failLabel . ' (' . $message . ')'
            : $failLabel;
    }
}
