<?php

declare(strict_types=1);

namespace Staccato\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Staccato\Service\OutputPathResolver;

final class OutputPathResolverTest extends TestCase
{
    private OutputPathResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new OutputPathResolver();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function validAliases(): iterable
    {
        yield 'mixed case directory' => ['/2022-08-20-Github-pages-with-Jigsaw/', '/2022-08-20-Github-pages-with-Jigsaw/', 'build/2022-08-20-Github-pages-with-Jigsaw/index.html'];
        yield 'missing trailing slash' => ['/old-post', '/old-post/', 'build/old-post/index.html'];
        yield 'nested' => ['/blog/2020/old-post/', '/blog/2020/old-post/', 'build/blog/2020/old-post/index.html'];
        yield 'html file' => ['/2020/01/old-post.html', '/2020/01/old-post.html', 'build/2020/01/old-post.html'];
        yield 'whitespace and backslashes' => [' \\old\\post\\ ', '/old/post/', 'build/old/post/index.html'];
        yield 'dots and tildes' => ['/~user/v1.2/', '/~user/v1.2/', 'build/~user/v1.2/index.html'];
    }

    #[Test]
    #[DataProvider('validAliases')]
    public function itNormalizesAliasesAndResolvesTheirOutputPath(string $alias, string $expectedUrlPath, string $expectedOutputPath): void
    {
        $urlPath = $this->resolver->normalizeAliasUrlPath($alias, 'post.md');

        self::assertSame($expectedUrlPath, $urlPath);
        self::assertSame($expectedOutputPath, $this->resolver->resolveAliasOutputPath($urlPath));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidAliases(): iterable
    {
        yield 'relative' => ['old-post/', 'must be a URL path starting with "/"'];
        yield 'absolute URL' => ['https://example.test/old/', 'must be a URL path starting with "/"'];
        yield 'homepage' => ['/', 'reserved for the generated homepage'];
        yield 'parent segment' => ['/blog/../etc/', 'may only contain'];
        yield 'current segment' => ['/blog/./post/', 'may only contain'];
        yield 'empty segment' => ['/blog//post/', 'may only contain'];
        yield 'query string' => ['/post/?page=2', 'may only contain'];
        yield 'space' => ['/old post/', 'may only contain'];
    }

    #[Test]
    #[DataProvider('invalidAliases')]
    public function itRejectsInvalidAliases(string $alias, string $expectedMessage): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->resolver->normalizeAliasUrlPath($alias, 'post.md');
    }

    #[Test]
    public function itDerivesSlugFromSourcePath(): void
    {
        self::assertSame('docs', $this->resolver->deriveSlugFromSourcePath('docs.md'));
        self::assertSame('docs/getting-started', $this->resolver->deriveSlugFromSourcePath('docs/getting-started.md'));
    }

    #[Test]
    public function itThrowsWhenSlugResolvesToTheHomepage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Slug of "about.md" resolves to "/", which is reserved for the generated homepage.');

        $this->resolver->normalizeSlug('/', 'about.md');
    }

    #[Test]
    public function itNormalizesSlugAndBuildsUrlPath(): void
    {
        $slug = $this->resolver->normalizeSlug('Docs/Getting Started', 'docs/getting-started.md');

        self::assertSame('docs/getting-started', $slug);
        self::assertSame('/docs/getting-started/', $this->resolver->buildUrlPath($slug));
    }

    #[Test]
    public function itResolvesOutputPathsForDirectoryStyleUrls(): void
    {
        self::assertSame('build/index.html', $this->resolver->resolveOutputPath('/'));
        self::assertSame('build/about/index.html', $this->resolver->resolveOutputPath('/about/'));
        self::assertSame('build/docs/getting-started/index.html', $this->resolver->resolveOutputPath('/docs/getting-started/'));
    }

    #[Test]
    public function itThrowsOnInvalidSourceSlugSegment(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid slug segment');

        $this->resolver->normalizeSlug('../etc/passwd', 'hack.md');
    }

    #[Test]
    public function itThrowsOnInvalidOutputUrlPath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid URL path');

        $this->resolver->resolveOutputPath('/../secret/');
    }
}
