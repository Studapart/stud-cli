<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Config\ProjectStudConfigKeys;
use App\DTO\MessageRef;
use App\DTO\SubmitOptions;
use App\DTO\WorkflowRecorder;
use App\Exception\ApiException;
use App\Service\GithubGitHostingAdapter;
use App\Service\GitRepository;
use App\Service\Prompt\PromptInterface;
use App\Service\SubmitLabelPlanner;
use App\Service\SubmitLabelResolver;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SubmitLabelPlannerTest extends TestCase
{
    private const CREATE_OPTION = 'Create: Create the label on GitHub and add it to the PR';
    private const IGNORE_OPTION = 'Ignore: Skip this label and remove it from the final list';
    private const RETRY_OPTION = 'Retry: Abort the command and re-run with a corrected list';

    private GitRepository&Stub $gitRepository;

    private PromptInterface&Stub $prompt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gitRepository = $this->createStub(GitRepository::class);
        $this->prompt = $this->createStub(PromptInterface::class);
    }

    private function createPlanner(?GithubGitHostingAdapter $gitProvider): SubmitLabelPlanner
    {
        $resolver = $gitProvider !== null ? new SubmitLabelResolver($gitProvider, $this->prompt) : null;

        return new SubmitLabelPlanner($this->gitRepository, $resolver);
    }

    /**
     * @param list<array{name: string}> $remoteLabels
     */
    private function providerWithLabels(array $remoteLabels): GithubGitHostingAdapter
    {
        $provider = $this->createMock(GithubGitHostingAdapter::class);
        $provider->expects($this->once())->method('getLabels')->willReturn($remoteLabels);

        return $provider;
    }

    /**
     * @return array{create: array<string>, existing: array<string>}|null
     */
    private function planExplicit(GithubGitHostingAdapter $provider, string $labels, bool $quiet = false): ?array
    {
        return $this->createPlanner($provider)->plan(new WorkflowRecorder(), new SubmitOptions(labels: $labels, quiet: $quiet));
    }

    public function testPlanUsesExplicitLabelsForNewAndExistingPullRequests(): void
    {
        $provider = $this->providerWithLabels([['name' => 'bug'], ['name' => 'enhancement']]);

        $this->assertSame(
            ['create' => ['bug', 'enhancement'], 'existing' => ['bug', 'enhancement']],
            $this->planExplicit($provider, 'bug,enhancement'),
        );
    }

    public function testPlanReturnsEmptyForBlankExplicitLabels(): void
    {
        $provider = $this->createMock(GithubGitHostingAdapter::class);
        $provider->expects($this->never())->method('getLabels');

        $this->assertSame(['create' => [], 'existing' => []], $this->planExplicit($provider, ''));
        $this->assertSame(['create' => [], 'existing' => []], $this->planExplicit($provider, '  ,  ,  '));
    }

    public function testPlanReturnsEmptyWithoutGitProvider(): void
    {
        $plan = $this->createPlanner(null)->plan(new WorkflowRecorder(), new SubmitOptions(labels: 'bug'));

        $this->assertSame(['create' => [], 'existing' => []], $plan);
    }

    public function testPlanMatchesRemoteLabelsCaseInsensitively(): void
    {
        $provider = $this->providerWithLabels([['name' => 'Bug']]);

        $this->assertSame(['create' => ['Bug'], 'existing' => ['Bug']], $this->planExplicit($provider, 'bug'));
    }

    public function testPlanCreatesMissingLabelWhenChosen(): void
    {
        $provider = $this->providerWithLabels([['name' => 'bug']]);
        $provider->expects($this->once())
            ->method('createLabel')
            ->with('new-label', $this->matchesRegularExpression('/^[0-9a-f]{6}$/i'), null)
            ->willReturn(['name' => 'new-label']);
        $this->prompt->method('choice')->willReturn(self::CREATE_OPTION);

        $plan = $this->planExplicit($provider, 'bug,new-label');

        $this->assertNotNull($plan);
        $this->assertCount(2, $plan['create']);
        $this->assertContains('bug', $plan['create']);
        $this->assertContains('new-label', $plan['create']);
    }

    public function testPlanIgnoresMissingLabelWhenChosen(): void
    {
        $provider = $this->providerWithLabels([['name' => 'bug']]);
        $provider->expects($this->never())->method('createLabel');
        $this->prompt->method('choice')->willReturn(self::IGNORE_OPTION);

        $this->assertSame(['create' => ['bug'], 'existing' => ['bug']], $this->planExplicit($provider, 'bug,typo'));
    }

    public function testPlanReturnsNullWhenUserChoosesRetry(): void
    {
        $provider = $this->providerWithLabels([['name' => 'bug']]);
        $provider->expects($this->never())->method('createLabel');
        $this->prompt->method('choice')->willReturn(self::RETRY_OPTION);

        $this->assertNull($this->planExplicit($provider, 'bug,typo'));
    }

    public function testPlanQuietIgnoresUnknownLabels(): void
    {
        $provider = $this->providerWithLabels([['name' => 'bug']]);

        $this->assertSame(['create' => ['bug'], 'existing' => ['bug']], $this->planExplicit($provider, 'bug,unknown-label', true));
    }

    public function testPlanReturnsNullWhenLabelCreationFails(): void
    {
        $provider = $this->providerWithLabels([['name' => 'bug']]);
        $provider->expects($this->once())->method('createLabel')->willThrowException(new \Exception('API Error'));
        $this->prompt->method('choice')->willReturn(self::CREATE_OPTION);

        $this->assertNull($this->planExplicit($provider, 'bug,new-label'));
    }

    public function testPlanReturnsNullWhenLabelCreationFailsWithApiException(): void
    {
        $provider = $this->providerWithLabels([['name' => 'bug']]);
        $provider->expects($this->once())
            ->method('createLabel')
            ->willThrowException(new ApiException("Failed to create label 'new-label'.", 'HTTP 422: Validation Failed', 422));
        $this->prompt->method('choice')->willReturn(self::CREATE_OPTION);

        $this->assertNull($this->planExplicit($provider, 'bug,new-label'));
    }

    public function testPlanOmittedLabelsReturnsEmptyWithoutProjectDefault(): void
    {
        $this->gitRepository->method('readProjectConfig')->willReturn([]);
        $provider = $this->createMock(GithubGitHostingAdapter::class);
        $provider->expects($this->never())->method('getLabels');

        $plan = $this->createPlanner($provider)->plan(new WorkflowRecorder(), new SubmitOptions());

        $this->assertSame(['create' => [], 'existing' => []], $plan);
    }

    public function testPlanOmittedLabelsReturnsEmptyWhenProjectConfigUnreadable(): void
    {
        $this->gitRepository->method('readProjectConfig')->willThrowException(new \RuntimeException('no config'));

        $plan = $this->createPlanner(null)->plan(new WorkflowRecorder(), new SubmitOptions());

        $this->assertSame(['create' => [], 'existing' => []], $plan);
    }

    public function testPlanOmittedLabelsAppliesProjectDefaultToNewPullRequestOnly(): void
    {
        $this->gitRepository->method('readProjectConfig')->willReturn([ProjectStudConfigKeys::PULL_REQUEST_LABELS => ['bug']]);
        $provider = $this->createMock(GithubGitHostingAdapter::class);
        $provider->expects($this->exactly(2))->method('getLabels')->willReturn([['name' => 'bug']]);

        $plan = $this->createPlanner($provider)->plan(new WorkflowRecorder(), new SubmitOptions());

        $this->assertSame(['create' => ['bug'], 'existing' => []], $plan);
    }

    public function testPlanOmittedLabelsWarnsAndSkipsDefaultWhenCatalogUnavailable(): void
    {
        $this->gitRepository->method('readProjectConfig')->willReturn([ProjectStudConfigKeys::PULL_REQUEST_LABELS => ['bug']]);
        $provider = $this->createStub(GithubGitHostingAdapter::class);
        $provider->method('getLabels')->willThrowException(new \RuntimeException('offline'));
        $recorder = new WorkflowRecorder();

        $plan = $this->createPlanner($provider)->plan($recorder, new SubmitOptions());

        $this->assertSame(['create' => [], 'existing' => []], $plan);
        $this->assertEquals(
            MessageRef::key('submit.warning_label_default_unavailable', ['error' => 'offline']),
            $recorder->getEntries()[0]->message,
        );
    }

    public function testPlanOmittedLabelsReturnsNullWhenUserChoosesRetry(): void
    {
        $this->gitRepository->method('readProjectConfig')->willReturn([ProjectStudConfigKeys::PULL_REQUEST_LABELS => ['typo']]);
        $provider = $this->createStub(GithubGitHostingAdapter::class);
        $provider->method('getLabels')->willReturn([['name' => 'bug']]);
        $this->prompt->method('choice')->willReturn(self::RETRY_OPTION);

        $this->assertNull($this->createPlanner($provider)->plan(new WorkflowRecorder(), new SubmitOptions()));
    }
}
