<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\StudConfigException;
use PHPUnit\Framework\TestCase;

class StudConfigExceptionTest extends TestCase
{
    public function testLinearTeamKeyRequired(): void
    {
        $exception = StudConfigException::linearTeamKeyRequired();

        $this->assertSame('item.create.error_no_linear_team', $exception->messageRef->key);
    }

    public function testInvalidJiraBaseUrl(): void
    {
        $exception = StudConfigException::invalidJiraBaseUrl();

        $this->assertSame('issue_tracker_provider.invalid_jira_base_url', $exception->messageRef->key);
    }

    public function testBaseBranchDefaultMissingIncludesBranchParameter(): void
    {
        $exception = StudConfigException::baseBranchDefaultMissing('develop');

        $this->assertSame('config.base_branch_default_missing', $exception->messageRef->key);
        $this->assertSame('develop', $exception->messageRef->parameters['branch']);
    }

    public function testInitRequiresTty(): void
    {
        $exception = StudConfigException::initRequiresTty();

        $this->assertSame('config.init.error.requires_tty', $exception->messageRef->key);
    }

    public function testInitPromptExhausted(): void
    {
        $exception = StudConfigException::initPromptExhausted();

        $this->assertSame('config.init.error.prompt_exhausted', $exception->messageRef->key);
    }
}
