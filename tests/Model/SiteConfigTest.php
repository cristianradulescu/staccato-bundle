<?php

declare(strict_types=1);

namespace Staccato\Tests\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Staccato\Model\SiteConfig;

final class SiteConfigTest extends TestCase
{
    #[Test]
    public function itNormalizesTheDirectories(): void
    {
        $siteConfig = new SiteConfig(
            title: 'Test Site',
            baseUrl: 'https://example.test',
            description: 'Test site description',
            articlesPerPage: 10,
            footerText: 'Built with Staccato',
            includeDefaultStyles: true,
            contentDirectory: ' site/pages/ ',
            buildDirectory: 'var\\out\\site\\',
            assetsDirectory: 'assets/',
            staticDirectory: 'static',
        );

        self::assertSame('site/pages', $siteConfig->contentDirectory);
        self::assertSame('var/out/site', $siteConfig->buildDirectory);
        self::assertSame('assets', $siteConfig->assetsDirectory);
        self::assertSame('static', $siteConfig->staticDirectory);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDirectories(): iterable
    {
        // The build directory is removed on a clean build, so it must never resolve outside the project directory.
        foreach (['', ' ', '.', '..', '../..', 'build/../..', 'var//build', '/var/www/build', 'C:\\site\\build'] as $directory) {
            yield 'buildDirectory '.var_export($directory, true) => ['buildDirectory', $directory];
        }

        yield 'contentDirectory parent segment' => ['contentDirectory', '../content'];
        yield 'assetsDirectory parent segment' => ['assetsDirectory', '../assets'];
        yield 'staticDirectory absolute path' => ['staticDirectory', '/static'];
    }

    #[Test]
    #[DataProvider('invalidDirectories')]
    public function itRejectsDirectoriesThatAreNotRelativeToTheProjectDirectory(string $name, string $directory): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('The SiteConfig "%s" value "%s" must be a path relative to the project directory', $name, $directory));

        new SiteConfig(
            title: 'Test Site',
            baseUrl: 'https://example.test',
            description: 'Test site description',
            articlesPerPage: 10,
            footerText: 'Built with Staccato',
            includeDefaultStyles: true,
            contentDirectory: 'contentDirectory' === $name ? $directory : 'content',
            buildDirectory: 'buildDirectory' === $name ? $directory : 'build',
            assetsDirectory: 'assetsDirectory' === $name ? $directory : 'assets',
            staticDirectory: 'staticDirectory' === $name ? $directory : 'static',
        );
    }
}
