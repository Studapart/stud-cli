<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\JiraSupportedTypes;
use Symfony\Component\String\Slugger\AsciiSlugger;

class BranchNameGenerator
{
    public const PREFIX_FIX = 'fix';
    public const PREFIX_FEAT = 'feat';
    public const PREFIX_CHORE = 'chore';

    public function __construct(
        private readonly JiraService $jiraService,
    ) {
    }

    public function generateBranchNameFromKey(string $key): string
    {
        $issue = $this->jiraService->getIssue($key);
        $prefix = self::prefixForIssueType($issue->issueType);
        $slugger = new AsciiSlugger();
        $slugValue = $slugger->slug($issue->title)->lower()->toString();

        return "{$prefix}/{$key}-{$slugValue}";
    }

    public function getBranchPrefixFromIssueType(string $issueType): string
    {
        return self::prefixForIssueType($issueType);
    }

    public static function prefixForIssueType(string $issueType): string
    {
        return match (JiraSupportedTypes::tryFromName($issueType)) {
            JiraSupportedTypes::Bug => self::PREFIX_FIX,
            JiraSupportedTypes::Story, JiraSupportedTypes::Epic => self::PREFIX_FEAT,
            JiraSupportedTypes::Task, JiraSupportedTypes::SubTask => self::PREFIX_CHORE,
            null => self::PREFIX_FEAT,
        };
    }
}
