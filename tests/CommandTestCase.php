<?php

namespace App\Tests;

use App\Git\GitRepository;
use App\Jira\JiraService;
use PHPUnit\Framework\TestCase;

abstract class CommandTestCase extends TestCase
{
    protected GitRepository $gitRepository;
    protected JiraService $jiraService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->jiraService = $this->createMock(JiraService::class);
    }
}
