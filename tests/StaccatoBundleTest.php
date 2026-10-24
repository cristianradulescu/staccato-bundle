<?php

declare(strict_types=1);

namespace Staccato\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Staccato\Model\SiteConfig;
use Staccato\Tests\Fixtures\TestKernel;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class StaccatoBundleTest extends TestCase
{
    protected function tearDown(): void
    {
        restore_exception_handler();
    }

    #[Test]
    public function itRegistersSiteConfigFromDefaults(): void
    {
        $kernel = new TestKernel('site_config_defaults');
        $kernel->boot();

        $siteConfig = $this->getSiteConfig($kernel);

        self::assertSame('Staccato', $siteConfig->title);
        self::assertSame('http://localhost', $siteConfig->baseUrl);
        self::assertSame('Static site generated with Staccato.', $siteConfig->description);
        self::assertSame(10, $siteConfig->articlesPerPage);
        self::assertSame('Built with Staccato', $siteConfig->footerText);
        self::assertTrue($siteConfig->includeDefaultStyles);
        self::assertNull($siteConfig->highlighterCss);

        $kernel->shutdown();
    }

    #[Test]
    public function itRegistersSiteConfigFromExplicitValues(): void
    {
        $kernel = new TestKernel('site_config_explicit', [
            'title' => ' dev-notes ',
            'base_url' => 'https://example.test/',
            'description' => 'Example description',
            'articles_per_page' => 12,
            'footer_text' => 'Built with dev-notes',
            'include_default_styles' => false,
            'highlighter_css' => 'vendor/tempest/highlight/src/Themes/Css/github-light.css',
        ]);
        $kernel->boot();

        $siteConfig = $this->getSiteConfig($kernel);

        self::assertSame('dev-notes', $siteConfig->title);
        self::assertSame('https://example.test', $siteConfig->baseUrl);
        self::assertSame('Example description', $siteConfig->description);
        self::assertSame(12, $siteConfig->articlesPerPage);
        self::assertSame('Built with dev-notes', $siteConfig->footerText);
        self::assertFalse($siteConfig->includeDefaultStyles);
        self::assertSame('vendor/tempest/highlight/src/Themes/Css/github-light.css', $siteConfig->highlighterCss);

        $kernel->shutdown();
    }

    #[Test]
    public function itRejectsAnInvalidBaseUrl(): void
    {
        $kernel = new TestKernel('site_config_invalid_url', [
            'base_url' => 'not-a-url',
        ]);

        $this->expectException(InvalidConfigurationException::class);

        $kernel->boot();
    }

    private function getSiteConfig(TestKernel $kernel): SiteConfig
    {
        /** @var ContainerInterface $testContainer */
        $testContainer = $kernel->getContainer()->get('test.service_container');

        /** @var SiteConfig $siteConfig */
        $siteConfig = $testContainer->get(SiteConfig::class);

        return $siteConfig;
    }
}
