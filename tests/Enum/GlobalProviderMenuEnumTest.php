<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\GlobalGitProviderMenu;
use App\Enum\GlobalWorkItemProviderMenu;
use App\Service\MessageRenderer;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;

class GlobalProviderMenuEnumTest extends TestCase
{
    private MessageRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $this->renderer = new MessageRenderer(new TranslationService('en', $translationsPath));
    }

    public function testGitMenuMapsToProviderValues(): void
    {
        $this->assertSame(['github'], GlobalGitProviderMenu::GithubOnly->toProviderValues());
        $this->assertSame(['gitlab'], GlobalGitProviderMenu::GitlabOnly->toProviderValues());
        $this->assertSame(['github', 'gitlab'], GlobalGitProviderMenu::Both->toProviderValues());
    }

    public function testWorkItemMenuMapsToProviderValues(): void
    {
        $this->assertSame(['jira'], GlobalWorkItemProviderMenu::JiraOnly->toProviderValues());
        $this->assertSame(['linear'], GlobalWorkItemProviderMenu::LinearOnly->toProviderValues());
        $this->assertSame(['jira', 'linear'], GlobalWorkItemProviderMenu::Both->toProviderValues());
    }

    public function testGitMenuRoundTripFromRenderedChoice(): void
    {
        $label = (string) $this->renderer->render(\App\DTO\MessageRef::key('config.init.git_provider.choice_gitlab'));
        $menu = GlobalGitProviderMenu::fromRenderedChoice($label, $this->renderer);
        $this->assertSame(GlobalGitProviderMenu::GitlabOnly, $menu);
    }

    public function testWorkItemMenuFromProviderValues(): void
    {
        $menu = GlobalWorkItemProviderMenu::fromProviderValues(['linear']);
        $this->assertSame(GlobalWorkItemProviderMenu::LinearOnly, $menu);

        $both = GlobalWorkItemProviderMenu::fromProviderValues(['jira', 'linear']);
        $this->assertSame(GlobalWorkItemProviderMenu::Both, $both);
    }

    public function testGitMenuFromProviderValues(): void
    {
        $gitlabOnly = GlobalGitProviderMenu::fromProviderValues(['gitlab']);
        $this->assertSame(GlobalGitProviderMenu::GitlabOnly, $gitlabOnly);

        $both = GlobalGitProviderMenu::fromProviderValues(['github', 'gitlab']);
        $this->assertSame(GlobalGitProviderMenu::Both, $both);
    }

    public function testGitMenuFallsBackToBothForUnknownRenderedChoice(): void
    {
        $menu = GlobalGitProviderMenu::fromRenderedChoice('not-a-real-label', $this->renderer);
        $this->assertSame(GlobalGitProviderMenu::Both, $menu);
    }

    public function testWorkItemMenuFallsBackToBothForUnknownRenderedChoice(): void
    {
        $menu = GlobalWorkItemProviderMenu::fromRenderedChoice('not-a-real-label', $this->renderer);
        $this->assertSame(GlobalWorkItemProviderMenu::Both, $menu);
    }
}
