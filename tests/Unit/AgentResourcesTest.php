<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Illuminate\Support\ServiceProvider;
use Tsitsishvili\ElasticAudit\ElasticAuditServiceProvider;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class AgentResourcesTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    private const GUIDELINE_DIR = self::ROOT.'/resources/boost/guidelines';

    private const GUIDELINE = self::GUIDELINE_DIR.'/core.blade.php';

    private const SKILL_DIR = self::ROOT.'/resources/boost/skills/elastic-audit-development';

    private const SKILL = self::SKILL_DIR.'/SKILL.md';

    private const AGENTS = self::ROOT.'/AGENTS.md';

    public function test_package_ships_a_boost_guideline(): void
    {
        $this->assertFileExists(self::GUIDELINE);

        $guideline = file_get_contents(self::GUIDELINE);

        $this->assertIsString($guideline);
        $this->assertStringContainsString('## Elastic Audit', $guideline);
        $this->assertStringContainsString('HttpLog::make', $guideline);
        $this->assertStringContainsString('ActivityLog::record', $guideline);
    }

    /**
     * Boost keys third-party guidelines by package name, so a second guideline file would
     * silently replace this one. See Laravel\Boost\Install\GuidelineComposer.
     */
    public function test_boost_guideline_directory_holds_exactly_one_file(): void
    {
        $files = glob(self::GUIDELINE_DIR.'/*');

        $this->assertIsArray($files);
        $this->assertCount(1, $files, 'A second guideline file would replace core.blade.php in Boost output.');
        $this->assertSame(realpath(self::GUIDELINE), realpath($files[0]));
    }

    /**
     * Boost renders @boostsnippet into a fenced code block. The <code-snippet> tag is not a
     * Boost directive and would leak into the composed guidelines verbatim.
     */
    public function test_boost_guideline_uses_the_supported_snippet_directive(): void
    {
        $guideline = file_get_contents(self::GUIDELINE);

        $this->assertIsString($guideline);
        $this->assertStringContainsString('@boostsnippet', $guideline);
        $this->assertStringContainsString('@endboostsnippet', $guideline);
        $this->assertStringNotContainsString('<code-snippet', $guideline);
    }

    public function test_package_ships_a_valid_agent_skill(): void
    {
        $this->assertFileExists(self::SKILL);

        $skill = file_get_contents(self::SKILL);

        $this->assertIsString($skill);
        $this->assertMatchesRegularExpression(
            '/\A---\Rname: elastic-audit-development\Rdescription: [^\r\n]+\R---\R/',
            $skill,
        );
        $this->assertStringContainsString('# Elastic Audit Development', $skill);
        $this->assertStringNotContainsString('TODO', $skill);
    }

    public function test_package_ships_an_agents_guide_for_applications_without_boost(): void
    {
        $this->assertFileExists(self::AGENTS);

        $agents = file_get_contents(self::AGENTS);

        $this->assertIsString($agents);
        $this->assertStringContainsString('HttpLog::make', $agents);
        $this->assertStringContainsString('ActivityLog::record', $agents);
        $this->assertStringNotContainsString('TODO', $agents);
    }

    /**
     * The security invariants must survive edits to either delivery path.
     */
    public function test_agent_resources_state_the_security_invariants(): void
    {
        foreach ([self::GUIDELINE, self::SKILL, self::AGENTS] as $path) {
            $content = file_get_contents($path);

            $this->assertIsString($content);
            $this->assertStringContainsString('vendor/', $content, "{$path} should warn against editing vendor/");
            $this->assertStringContainsString('redaction.allow', $content, "{$path} should cover redaction.allow");
            $this->assertMatchesRegularExpression(
                '/[Nn]ever/',
                $content,
                "{$path} should keep its explicit prohibitions",
            );
        }
    }

    public function test_ai_publish_tag_exposes_the_agent_resources(): void
    {
        $paths = ServiceProvider::pathsToPublish(ElasticAuditServiceProvider::class, 'elastic-audit-ai');

        $this->assertNotEmpty($paths, 'The elastic-audit-ai publish tag should be registered.');

        $sources = array_map('realpath', array_keys($paths));

        $this->assertContains(realpath(self::SKILL_DIR), $sources);
        $this->assertContains(realpath(self::AGENTS), $sources);

        $targets = array_values($paths);

        $this->assertContains(base_path('.ai/skills/elastic-audit-development'), $targets);
        $this->assertContains(base_path('AGENTS.elastic-audit.md'), $targets);
    }
}
