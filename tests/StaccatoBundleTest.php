<?php

declare(strict_types=1);

namespace Staccato\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame([], $siteConfig->stylesheets);
        self::assertNull($siteConfig->highlighterCss);
        self::assertSame('content', $siteConfig->contentDirectory);
        self::assertSame('build', $siteConfig->buildDirectory);
        self::assertSame('assets', $siteConfig->assetsDirectory);

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
            'stylesheets' => ['assets/css/custom.css'],
            'highlighter_css' => 'vendor/tempest/highlight/src/Themes/Css/github-light.css',
            'content_dir' => 'site/pages/',
            'build_dir' => 'public_html',
            'assets_dir' => 'site\\static',
        ]);
        $kernel->boot();

        $siteConfig = $this->getSiteConfig($kernel);

        self::assertSame('dev-notes', $siteConfig->title);
        self::assertSame('https://example.test', $siteConfig->baseUrl);
        self::assertSame('Example description', $siteConfig->description);
        self::assertSame(12, $siteConfig->articlesPerPage);
        self::assertSame('Built with dev-notes', $siteConfig->footerText);
        self::assertFalse($siteConfig->includeDefaultStyles);
        self::assertSame(['assets/css/custom.css'], $siteConfig->stylesheets);
        self::assertSame('vendor/tempest/highlight/src/Themes/Css/github-light.css', $siteConfig->highlighterCss);
        self::assertSame('site/pages', $siteConfig->contentDirectory);
        self::assertSame('public_html', $siteConfig->buildDirectory);
        self::assertSame('site/static', $siteConfig->assetsDirectory);

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

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidDirectories(): iterable
    {
        yield 'absolute path' => [['content_dir' => '/var/www/content'], 'must be a path relative to the project directory'];
        yield 'windows absolute path' => [['build_dir' => 'C:\\site\\build'], 'must be a path relative to the project directory'];
        yield 'parent segment' => [['assets_dir' => '../assets'], 'must be a path relative to the project directory'];
        yield 'current directory' => [['build_dir' => '.'], 'must be a path relative to the project directory'];
        yield 'build equals content' => [['build_dir' => 'content'], 'must not overlap with "staccato.content_dir"'];
        yield 'build inside content' => [['build_dir' => 'content/build'], 'must not overlap with "staccato.content_dir"'];
        yield 'build contains assets' => [['build_dir' => 'site', 'assets_dir' => 'site/assets'], 'must not overlap with "staccato.assets_dir"'];
    }

    /**
     * @param array<string, string> $config
     */
    #[Test]
    #[DataProvider('invalidDirectories')]
    public function itRejectsUnsafeDirectories(array $config, string $expectedMessage): void
    {
        $kernel = new TestKernel('site_config_invalid_directories_'.md5(serialize($config)), $config);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);

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
