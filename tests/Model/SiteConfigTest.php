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
    public static function validDirectories(): iterable
    {
        yield 'dot at the start of a segment' => ['site/.output', 'site/.output'];
        yield 'dot inside a segment' => ['v1.2/build', 'v1.2/build'];
        yield 'space inside a segment' => ['my site/build', 'my site/build'];
        yield 'trailing space of the whole value' => ['build ', 'build'];
    }

    #[Test]
    #[DataProvider('validDirectories')]
    public function itAcceptsDotsAndSpacesThatDoNotEndASegment(string $directory, string $expected): void
    {
        $siteConfig = new SiteConfig(
            title: 'Test Site',
            baseUrl: 'https://example.test',
            description: 'Test site description',
            articlesPerPage: 10,
            footerText: 'Built with Staccato',
            includeDefaultStyles: true,
            buildDirectory: $directory,
        );

        self::assertSame($expected, $siteConfig->buildDirectory);
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

        // Windows drops trailing periods and spaces from path names, so these could name another directory there.
        foreach (['content.', 'build/out.', 'a/.../b', 'build /out', '.. ', 'build/.. /out'] as $directory) {
            yield 'buildDirectory '.var_export($directory, true) => ['buildDirectory', $directory];
        }

        yield 'contentDirectory parent segment' => ['contentDirectory', '../content'];
        yield 'contentDirectory trailing dot' => ['contentDirectory', 'site/pages.'];
        yield 'assetsDirectory parent segment' => ['assetsDirectory', '../assets'];
        yield 'staticDirectory absolute path' => ['staticDirectory', '/static'];
        yield 'staticDirectory trailing space in a segment' => ['staticDirectory', 'static /root'];
    }

    #[Test]
    #[DataProvider('invalidDirectories')]
    public function itRejectsDirectoriesThatAreNotRelativeToTheProjectDirectory(string $name, string $directory): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('The SiteConfig "%s" value "%s" must be a path relative to the project directory, with no segment that is empty or ends in "." or a space (such as "..").', $name, $directory));

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
