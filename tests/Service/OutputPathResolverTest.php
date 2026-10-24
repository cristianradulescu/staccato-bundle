<?php

declare(strict_types=1);

namespace Staccato\Tests\Service;

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
