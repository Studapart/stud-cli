<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\IssueTrackerProvider;
use App\Service\AttachmentUrlProviderGuesser;
use PHPUnit\Framework\TestCase;

final class AttachmentUrlProviderGuesserTest extends TestCase
{
    private AttachmentUrlProviderGuesser $guesser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guesser = new AttachmentUrlProviderGuesser();
    }

    public function testGuessJiraFromAtlassianHost(): void
    {
        $this->assertSame(
            IssueTrackerProvider::Jira->value,
            $this->guesser->guess('https://example.atlassian.net/secure/attachment/1/x.png'),
        );
    }

    public function testGuessLinearFromLinearAppHost(): void
    {
        $this->assertSame(
            IssueTrackerProvider::Linear->value,
            $this->guesser->guess('https://linear.app/team/issue/SCIL-1/attachment/uuid'),
        );
    }

    public function testGuessReturnsNullForEmptyUrl(): void
    {
        $this->assertNull($this->guesser->guess(''));
        $this->assertNull($this->guesser->guess(null));
    }

    public function testGuessReturnsNullForUnparseableUrl(): void
    {
        $this->assertNull($this->guesser->guess('not-a-url'));
    }

    public function testGuessReturnsNullForUnknownHost(): void
    {
        $this->assertNull($this->guesser->guess('https://example.com/file'));
    }
}
