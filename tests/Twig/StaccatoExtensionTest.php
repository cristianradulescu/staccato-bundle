<?php

declare(strict_types=1);

namespace Staccato\Tests\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Staccato\Model\SiteConfig;
use Staccato\Twig\StaccatoExtension;

final class StaccatoExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function paths(): iterable
    {
        yield 'root site, homepage' => ['https://example.test', '/', '/'];
        yield 'root site, page' => ['https://example.test', '/about/', '/about/'];
        yield 'sub-path site, homepage' => ['https://example.test/repo', '/', '/repo/'];
        yield 'sub-path site, page' => ['https://example.test/repo', '/about/', '/repo/about/'];
        yield 'sub-path site, asset' => ['https://example.test/repo', '/assets/staccato/staccato.css', '/repo/assets/staccato/staccato.css'];
        yield 'nested sub-path site' => ['https://example.test/a/b', '/page/2/', '/a/b/page/2/'];
        yield 'path without leading slash' => ['https://example.test/repo', 'about/', '/repo/about/'];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function urls(): iterable
    {
        yield 'root site, homepage' => ['https://example.test', '/', 'https://example.test/'];
        yield 'root site, page' => ['https://example.test', '/about/', 'https://example.test/about/'];
        yield 'sub-path site, page' => ['https://example.test/repo', '/about/', 'https://example.test/repo/about/'];
    }

    #[Test]
    #[DataProvider('urls')]
    public function itBuildsAbsoluteUrlsUnderTheBaseUrl(string $baseUrl, string $path, string $expected): void
    {
        self::assertSame($expected, $this->createExtension($baseUrl)->url($path));
    }

    #[Test]
    public function itExposesTheSiteConfigAsAGlobal(): void
    {
        $extension = $this->createExtension('https://example.test');

        self::assertSame('https://example.test', $extension->getGlobals()['staccato']->baseUrl);
    }

    #[Test]
    #[DataProvider('paths')]
    public function itPrefixesPathsWithTheBasePathOfTheBaseUrl(string $baseUrl, string $path, string $expected): void
    {
        self::assertSame($expected, $this->createExtension($baseUrl)->path($path));
    }

    private function createExtension(string $baseUrl): StaccatoExtension
    {
        return new StaccatoExtension(new SiteConfig(
            title: 'Test Site',
            baseUrl: $baseUrl,
            description: 'Test site description',
            articlesPerPage: 10,
            footerText: 'Built with Staccato',
            includeDefaultStyles: true,
        ));
    }
}
