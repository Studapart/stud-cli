<?php

namespace App\Tests;

use App\Git\GitRepository;
use App\Jira\JiraService;

class TestKernel
{
    public static ?GitRepository $gitRepository = null;
    public static ?JiraService $jiraService = null;
}
