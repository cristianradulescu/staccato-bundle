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

    /**
     * @return iterable<string, array{array<string, string>, string, string, string}>
     */
    public static function overlappingDirectories(): iterable
    {
        // The build directory is removed on a clean build, so it must never equal, contain or sit inside a source.
        yield 'build equals content' => [['buildDirectory' => 'content'], 'content', 'contentDirectory', 'content'];
        yield 'build equals assets' => [['buildDirectory' => 'assets'], 'assets', 'assetsDirectory', 'assets'];
        yield 'build equals static' => [['buildDirectory' => 'static'], 'static', 'staticDirectory', 'static'];
        yield 'build inside content' => [['buildDirectory' => 'content/build'], 'content/build', 'contentDirectory', 'content'];
        yield 'assets inside build' => [['assetsDirectory' => 'build/assets'], 'build', 'assetsDirectory', 'build/assets'];
        yield 'build equals content once normalized' => [['buildDirectory' => ' content\\'], 'content', 'contentDirectory', 'content'];

        // On a case-insensitive filesystem (the macOS and Windows default) these name the same directory.
        yield 'build differs from content only by case' => [['buildDirectory' => 'Content'], 'Content', 'contentDirectory', 'content'];
        yield 'static inside build, differing only by case' => [['buildDirectory' => 'site', 'staticDirectory' => 'SITE/static'], 'site', 'staticDirectory', 'SITE/static'];
        yield 'build inside content, differing only by the case of a non-ASCII letter' => [['contentDirectory' => 'Über', 'buildDirectory' => 'über/build'], 'über/build', 'contentDirectory', 'Über'];
    }

    /**
     * @param array<string, string> $directories
     */
    #[Test]
    #[DataProvider('overlappingDirectories')]
    public function itRejectsABuildDirectoryThatOverlapsWithAnotherDirectory(array $directories, string $buildDirectory, string $name, string $directory): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('The SiteConfig "buildDirectory" value "%s" must not overlap with "%s" ("%s"), because the build directory is removed on every clean build.', $buildDirectory, $name, $directory));

        $this->createSiteConfig($directories);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function separateDirectories(): iterable
    {
        // Only whole segments overlap, so a shared prefix is fine.
        yield 'build starts with the content name' => [['buildDirectory' => 'content-build']];
        yield 'build extends the content name' => [['buildDirectory' => 'contents']];
        yield 'assets extends the build name' => [['assetsDirectory' => 'builder']];
        yield 'assets extends the build name, in another case' => [['assetsDirectory' => 'Builder/assets']];
        yield 'siblings in one parent' => [['contentDirectory' => 'site/content', 'buildDirectory' => 'site/build', 'assetsDirectory' => 'site/assets', 'staticDirectory' => 'site/static']];
    }

    /**
     * @param array<string, string> $directories
     */
    #[Test]
    #[DataProvider('separateDirectories')]
    public function itAcceptsABuildDirectoryThatOnlySharesAPrefixWithAnotherDirectory(array $directories): void
    {
        $siteConfig = $this->createSiteConfig($directories);

        self::assertSame($directories['buildDirectory'] ?? 'build', $siteConfig->buildDirectory);
    }

    /**
     * @param array<string, string> $directories
     */
    private function createSiteConfig(array $directories): SiteConfig
    {
        return new SiteConfig(
            title: 'Test Site',
            baseUrl: 'https://example.test',
            description: 'Test site description',
            articlesPerPage: 10,
            footerText: 'Built with Staccato',
            includeDefaultStyles: true,
            contentDirectory: $directories['contentDirectory'] ?? 'content',
            buildDirectory: $directories['buildDirectory'] ?? 'build',
            assetsDirectory: $directories['assetsDirectory'] ?? 'assets',
            staticDirectory: $directories['staticDirectory'] ?? 'static',
        );
    }
}
