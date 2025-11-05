<?php

namespace App\Tests;

use App\Git\GitRepository;
use App\Jira\JiraService;
use App\Process\ProcessFactory;

class TestKernel
{
    public static ?GitRepository $gitRepository = null;
    public static ?JiraService $jiraService = null;
    public static ?ProcessFactory $processFactory = null;
}
