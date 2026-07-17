<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\WorkItem;
use App\Enum\WorkflowChannel;

/**
 * Builds provider-neutral pull request bodies from a work item.
 */
class SubmitPrBodyBuilder
{
    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly array $jiraConfig,
        private readonly CanConvertToMarkdownInterface $htmlConverter,
    ) {
    }

    public function build(string $issueKey, ?WorkItem $issue, WorkflowEntryRecorder $recorder): string
    {
        $issueUrl = $this->resolveIssueUrl($issueKey, $issue);
        $prBody = $this->descriptionForPrBody($issue, $recorder);
        if ($prBody === null || $prBody === '') {
            $prBody = "Resolves: {$issueUrl}";
        }

        return $this->prependIssueLinkToBody($prBody, $issueKey, $issueUrl);
    }

    protected function resolveIssueUrl(string $issueKey, ?WorkItem $issue): string
    {
        if ($issue !== null && $this->hasWorkItemUrl($issue)) {
            return (string) $issue->url;
        }

        $jiraBase = isset($this->jiraConfig['JIRA_URL']) && is_string($this->jiraConfig['JIRA_URL'])
            ? rtrim($this->jiraConfig['JIRA_URL'], '/')
            : '';
        if ($jiraBase !== '') {
            return "{$jiraBase}/browse/{$issueKey}";
        }

        return $issueKey;
    }

    protected function descriptionForPrBody(?WorkItem $issue, WorkflowEntryRecorder $recorder): ?string
    {
        if ($issue === null) {
            return null;
        }

        $raw = $this->rawDescriptionForPrBody($issue);
        if ($raw === null) {
            return null;
        }

        if ($this->hasWorkItemUrl($issue) && ! $this->looksLikeHtml($raw)) {
            return $raw;
        }

        return $this->convertDescriptionToMarkdown($raw, $recorder);
    }

    /**
     * Prefer renderedDescription; fall back to description only when the work item has a URL (Linear).
     */
    protected function rawDescriptionForPrBody(WorkItem $issue): ?string
    {
        $raw = $issue->renderedDescription;
        if ($raw === null || $raw === '') {
            if (! $this->hasWorkItemUrl($issue)) {
                return null;
            }
            $raw = $issue->description;
        }

        if (trim($raw) === '' || $raw === 'No description provided.') {
            return null;
        }

        return $raw;
    }

    protected function hasWorkItemUrl(WorkItem $issue): bool
    {
        return is_string($issue->url) && $issue->url !== '';
    }

    protected function looksLikeHtml(string $content): bool
    {
        return (bool) preg_match('/<[a-z][\s\S]*>/i', $content);
    }

    /**
     * Convert HTML description to Markdown; log and fallback to raw HTML on failure.
     */
    protected function convertDescriptionToMarkdown(string $html, WorkflowEntryRecorder $recorder): string
    {
        try {
            $markdown = $this->htmlConverter->toMarkdown($html);
            $markdown = MarkdownHelper::unescapeCheckboxMarkdown($markdown);
            $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, '  Converted HTML to Markdown for PR description', WorkflowChannel::Jira);

            return $markdown;
        } catch (\Exception $e) {
            $this->logConversionFailure($e, $recorder);

            return $html;
        }
    }

    protected function logConversionFailure(\Exception $e, WorkflowEntryRecorder $recorder): void
    {
        $errorMessage = $e->getMessage();
        if (str_contains($errorMessage, 'DOMDocument') || str_contains($errorMessage, "Class 'DOMDocument' not found")) {
            $recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, [
                'HTML to Markdown conversion failed: PHP XML extension is missing.',
                'Install it using:',
                '  Ubuntu/Debian: sudo apt-get install php-xml',
                '  Fedora/RHEL: sudo dnf install php-xml',
                '  macOS (Homebrew): brew install php-xml',
                'Using raw HTML for PR description.',
            ]);

            return;
        }

        // @codeCoverageIgnoreStart
        $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, "  HTML to Markdown conversion failed, using raw HTML: {$errorMessage}", WorkflowChannel::Jira);
        // @codeCoverageIgnoreEnd
    }

    protected function prependIssueLinkToBody(string $body, string $issueKey, string $issueUrl): string
    {
        $issueLink = "🔗 **Issue:** [{$issueKey}]({$issueUrl})";

        return $issueLink . "\n\n" . $body;
    }
}
