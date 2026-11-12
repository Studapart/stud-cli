<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\IssueTrackerProvider;
use App\Service\DualTrackerPolicy;
use PHPUnit\Framework\TestCase;

final class DualTrackerPolicyTest extends TestCase
{
    private DualTrackerPolicy $policy;

    /** @var array<string, mixed> */
    private array $dualGlobal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new DualTrackerPolicy();
        $this->dualGlobal = [
            'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
            'JIRA_URL' => 'https://example.atlassian.net',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token',
            'LINEAR_API_KEY' => 'lin',
        ];
    }

    public function testAutoProjectWithBothTrackersConfiguredIsDualAuto(): void
    {
        $project = ['issueTrackerProvider' => IssueTrackerProvider::Auto->value];

        $this->assertTrue($this->policy->isDualAuto($this->dualGlobal, $project));
    }

    public function testProjectWithoutStoredProviderIsTreatedAsAuto(): void
    {
        $this->assertTrue($this->policy->isDualAuto($this->dualGlobal, []));
    }

    public function testExplicitProjectProviderIsNotDualAuto(): void
    {
        $project = ['issueTrackerProvider' => IssueTrackerProvider::Jira->value];

        $this->assertFalse($this->policy->isDualAuto($this->dualGlobal, $project));
    }

    public function testOnlyOneTrackerConfiguredIsNotDualAuto(): void
    {
        $global = $this->dualGlobal;
        $global['ISSUE_TRACKER_PROVIDERS'] = ['jira'];

        $this->assertFalse($this->policy->isDualAuto($global, []));
    }

    public function testMissingLinearCredentialsIsNotDualAuto(): void
    {
        $global = $this->dualGlobal;
        unset($global['LINEAR_API_KEY']);

        $this->assertFalse($this->policy->isDualAuto($global, []));
    }

    public function testCreateTakesTrackerFromLookupInDualAutoWithoutOverride(): void
    {
        $this->assertTrue($this->policy->createTakesTrackerFromLookup(null, $this->dualGlobal, []));
    }

    public function testCreateTakesTrackerFromLookupTreatsBlankOverrideAsNoOverride(): void
    {
        $this->assertTrue($this->policy->createTakesTrackerFromLookup('  ', $this->dualGlobal, []));
    }

    public function testCreateKeepsOverrideWhenProviderIsGiven(): void
    {
        $this->assertFalse($this->policy->createTakesTrackerFromLookup('jira', $this->dualGlobal, []));
    }

    public function testCreateDoesNotTakeTrackerFromLookupForSingleTracker(): void
    {
        $global = $this->dualGlobal;
        $global['ISSUE_TRACKER_PROVIDERS'] = ['jira'];

        $this->assertFalse($this->policy->createTakesTrackerFromLookup(null, $global, []));
    }
}
