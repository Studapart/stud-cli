<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\Enum\GitProvider;
use App\Enum\IssueTrackerProvider;
use App\Service\ConfigValidatePlanner;
use App\Service\GlobalConfigProviderResolver;
use PHPUnit\Framework\TestCase;

final class ConfigValidatePlannerTest extends TestCase
{
    private ConfigValidatePlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new ConfigValidatePlanner();
    }

    public function testExplicitLinearWinsOverSharedScopeKey(): void
    {
        $plan = $this->planner->plan(
            $this->dualGlobalConfig(),
            [
                'issueTrackerProvider' => IssueTrackerProvider::Linear->value,
                'projectKey' => 'SCI',
                'linearTeamKey' => 'SCI',
            ],
            true,
            false,
            false,
            false,
            'github',
        );

        $this->assertSame(IssueTrackerProvider::Linear, $plan->selectedTracker);
        $this->assertNull($plan->projectSelectionError);
        $this->assertSame(GitProvider::Github, $plan->projectGit);
    }

    public function testNoProjectConfigHasNoSelectionFailure(): void
    {
        $plan = $this->planner->plan($this->dualGlobalConfig(), [], true, false, false, false, 'github');

        $this->assertNull($plan->selectedTracker);
        $this->assertNull($plan->projectSelectionError);
        $this->assertNull($plan->projectGit);
        $this->assertTrue($plan->probeJira);
        $this->assertTrue($plan->probeLinear);
    }

    public function testSharedScopeWithoutExplicitProviderIsASelectionError(): void
    {
        $plan = $this->planner->plan(
            $this->dualGlobalConfig(),
            ['projectKey' => 'SCI', 'linearTeamKey' => 'SCI'],
            true,
            false,
            false,
            true,
        );

        $this->assertNull($plan->selectedTracker);
        $this->assertNotNull($plan->projectSelectionError);
        $this->assertFalse($plan->probeGit);
    }

    public function testProjectKeyOnlySelectsJiraWhenDualGlobal(): void
    {
        $plan = $this->planner->plan(
            $this->dualGlobalConfig(),
            ['projectKey' => 'SCI'],
            true,
            false,
            false,
            false,
            'github',
        );

        $this->assertSame(IssueTrackerProvider::Jira, $plan->selectedTracker);
        $this->assertNull($plan->projectSelectionError);
    }

    public function testProjectKeyOnlySelectsLinearWhenLinearOnlyGlobal(): void
    {
        $plan = $this->planner->plan(
            ['ISSUE_TRACKER_PROVIDERS' => ['linear'], 'GIT_PROVIDERS' => ['github']],
            ['projectKey' => 'SCI'],
            true,
            false,
            false,
            false,
            'github',
        );

        $this->assertSame(IssueTrackerProvider::Linear, $plan->selectedTracker);
        $this->assertNull($plan->projectSelectionError);
    }

    public function testUnreadableProjectConfigFailsSelection(): void
    {
        $plan = $this->planner->plan(
            $this->dualGlobalConfig(),
            [],
            true,
            false,
            false,
            false,
            'github',
            MessageRef::key('config.show.project_config_unreadable'),
        );

        $this->assertTrue($plan->hasProjectConfig);
        $this->assertNull($plan->selectedTracker);
        $this->assertNotNull($plan->projectSelectionError);
        $this->assertSame('config.show.project_config_unreadable', $plan->projectSelectionError->key);
    }

    public function testLinearTeamKeyOnlySelectsLinearWhenBothTrackersListed(): void
    {
        $plan = $this->planner->plan(
            $this->dualGlobalConfig(),
            ['linearTeamKey' => 'ENG'],
            true,
            false,
            false,
            false,
            'github',
        );

        $this->assertSame(IssueTrackerProvider::Linear, $plan->selectedTracker);
        $this->assertNull($plan->projectSelectionError);
    }

    public function testDistinctScopeKeysSelectTheJiraKey(): void
    {
        $plan = $this->planner->plan(
            $this->dualGlobalConfig(),
            ['projectKey' => 'SCI', 'linearTeamKey' => 'ENG'],
            true,
            false,
            false,
            false,
            'github',
        );

        $this->assertSame(IssueTrackerProvider::Jira, $plan->selectedTracker);
        $this->assertNull($plan->projectSelectionError);
    }

    public function testUnpinnedProjectWithoutScopeKeyFailsWhenBothTrackersListed(): void
    {
        $plan = $this->planner->plan(
            $this->dualGlobalConfig(),
            ['gitProvider' => 'github'],
            true,
            false,
            false,
            false,
            null,
        );

        $this->assertNull($plan->selectedTracker);
        $this->assertNotNull($plan->projectSelectionError);
        $this->assertSame('issue_tracker_provider.auto_requires_issue_key', $plan->projectSelectionError->key);
    }

    public function testUnpinnedProjectWithoutScopeKeyFailsWhenNoTrackerListed(): void
    {
        $resolver = new class () extends GlobalConfigProviderResolver {
            public function resolveIssueTrackerProviders(array $global): array
            {
                return [];
            }
        };
        $plan = (new ConfigValidatePlanner($resolver))->plan(
            ['GIT_PROVIDERS' => ['github']],
            ['gitProvider' => 'github'],
            true,
            false,
            false,
            false,
            null,
        );

        $this->assertNull($plan->selectedTracker);
        $this->assertNotNull($plan->projectSelectionError);
        $this->assertSame('guard.error.ambiguous_issue_tracker_provider', $plan->projectSelectionError->key);
    }

    public function testProjectKeyOnlySelectsJiraWhenJiraOnlyGlobal(): void
    {
        $plan = $this->planner->plan(
            ['ISSUE_TRACKER_PROVIDERS' => ['jira'], 'GIT_PROVIDERS' => ['github']],
            ['projectKey' => 'SCI'],
            true,
            false,
            false,
            false,
            'github',
        );

        $this->assertSame(IssueTrackerProvider::Jira, $plan->selectedTracker);
        $this->assertNull($plan->projectSelectionError);
    }

    /**
     * @return array<string, mixed>
     */
    private function dualGlobalConfig(): array
    {
        return [
            'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
            'GIT_PROVIDERS' => ['github'],
        ];
    }
}
