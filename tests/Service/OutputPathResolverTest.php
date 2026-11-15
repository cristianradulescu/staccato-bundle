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
        yield 'dot inside a nested segment' => ['/v1.2/notes/', '/v1.2/notes/', 'build/v1.2/notes/index.html'];
        yield 'dot at the start of a segment' => ['/.well-known/old', '/.well-known/old/', 'build/.well-known/old/index.html'];
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
        yield 'trailing dot' => ['/a./', 'Alias "/a./" in "post.md" may only contain letters, digits, ".", "_", "~" and "-" between slashes, with no segment ending in ".".'];
        yield 'trailing dot in a nested segment' => ['/old/post./', 'with no segment ending in "."'];
        yield 'trailing dot without a trailing slash' => ['/old/post.', 'with no segment ending in "."'];
        yield 'segment of only dots' => ['/.../', 'with no segment ending in "."'];
        yield 'line break at the end of a segment' => ["/old\n/post/", 'may only contain'];
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function slugsSpelledInAscii(): iterable
    {
        yield 'ascii words' => ['Docs/Getting Started', 'docs/getting-started'];
        yield 'ascii punctuation' => ['  --C++ & Rust: v1.2--  ', 'c-rust-v1-2'];
        yield 'ascii underscore' => ['hello_world', 'hello_world'];
        yield 'romanian comma below' => ['știri', 'stiri'];
        yield 'uppercase romanian' => ['Știri-Noi', 'stiri-noi'];
        yield 'french accents' => ['café-crème', 'cafe-creme'];
        yield 'german sharp s' => ['Straße', 'strasse'];
        yield 'danish ligature and slashed o' => ['Ærø', 'aero'];
        yield 'polish stroke and acute' => ['Łódź', 'lodz'];
        yield 'turkish dotted and dotless i' => ['İstanbul-ılık', 'istanbul-ilik'];
        yield 'typographic apostrophe' => ['don’t-stop', 'don-t-stop'];
        yield 'en dash between spaces' => ['a – b', 'a-b'];
        yield 'non-ascii space and symbol' => ["\u{00A0}🎉\u{00A0}party\u{2026}", 'party'];
        yield 'nested segments' => ['docs/Ünïcödé/page', 'docs/unicode/page'];
        yield 'decomposed (NFD) input' => ["s\u{0326}tiri", 'stiri'];
    }

    #[Test]
    #[DataProvider('slugsSpelledInAscii')]
    public function itSpellsSlugsInAscii(string $rawSlug, string $expectedSlug): void
    {
        self::assertSame($expectedSlug, $this->resolver->normalizeSlug($rawSlug, 'post.md'));
        self::assertSame($expectedSlug, $this->resolver->deriveSlugFromSourcePath($rawSlug.'.md'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function slugsWithoutAsciiSpelling(): iterable
    {
        yield 'only letters without an ascii equivalent' => [
            '日本',
            'Cannot derive an ASCII slug from "日本.md": "日" has no ASCII equivalent. Set a "slug" in its front matter.',
            'Cannot derive an ASCII slug from the front matter "slug" of "post.md": "日" has no ASCII equivalent. Write the "slug" in ASCII letters and digits.',
        ];
        yield 'letters without an ascii equivalent next to ascii' => [
            '日本-travel',
            'Cannot derive an ASCII slug from "日本-travel.md": "日" has no ASCII equivalent. Set a "slug" in its front matter.',
            'Cannot derive an ASCII slug from the front matter "slug" of "post.md": "日" has no ASCII equivalent. Write the "slug" in ASCII letters and digits.',
        ];
        yield 'cyrillic' => [
            'привет',
            'Cannot derive an ASCII slug from "привет.md": "п" has no ASCII equivalent. Set a "slug" in its front matter.',
            'Cannot derive an ASCII slug from the front matter "slug" of "post.md": "п" has no ASCII equivalent. Write the "slug" in ASCII letters and digits.',
        ];
        yield 'character named as written' => [
            'news/Йога',
            'Cannot derive an ASCII slug from "news/Йога.md": "Й" has no ASCII equivalent. Set a "slug" in its front matter.',
            'Cannot derive an ASCII slug from the front matter "slug" of "post.md": "Й" has no ASCII equivalent. Write the "slug" in ASCII letters and digits.',
        ];
        yield 'non-ascii digit' => [
            'top-١٠',
            'Cannot derive an ASCII slug from "top-١٠.md": "١" has no ASCII equivalent. Set a "slug" in its front matter.',
            'Cannot derive an ASCII slug from the front matter "slug" of "post.md": "١" has no ASCII equivalent. Write the "slug" in ASCII letters and digits.',
        ];
        yield 'invalid utf-8' => [
            "\xC3\x28",
            "Cannot derive a slug from \"\xC3\x28.md\": the path is not valid UTF-8. Rename the file, or set a \"slug\" in its front matter.",
            'Cannot derive a slug from the front matter "slug" of "post.md": it is not valid UTF-8.',
        ];
    }

    #[Test]
    #[DataProvider('slugsWithoutAsciiSpelling')]
    public function itRejectsSlugsThatCannotBeSpelledInAscii(string $rawSlug, string $expectedDerivedMessage, string $expectedFrontMatterMessage): void
    {
        $attempts = [
            [fn (): string => $this->resolver->deriveSlugFromSourcePath($rawSlug.'.md'), $expectedDerivedMessage],
            [fn (): string => $this->resolver->normalizeSlug($rawSlug, 'post.md'), $expectedFrontMatterMessage],
        ];

        foreach ($attempts as [$attempt, $expectedMessage]) {
            $message = null;

            try {
                $attempt();
            } catch (\RuntimeException $exception) {
                $message = $exception->getMessage();
            }

            self::assertSame($expectedMessage, $message);
        }
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
    public function itNormalizesTheBuildDirectory(): void
    {
        self::assertSame('var/out/site/about/index.html', $this->resolver->resolveOutputPath('/about/', 'var\\out\\site\\'));
        self::assertSame('var/out/site/old.html', $this->resolver->resolveAliasOutputPath('/old.html', 'var/out/site/'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBuildDirectories(): iterable
    {
        yield 'empty' => [''];
        yield 'parent segment' => ['../build'];
        yield 'nested parent segment' => ['build/../..'];
        yield 'empty segment' => ['var//build'];
        yield 'absolute path' => ['/var/www/build'];
    }

    #[Test]
    #[DataProvider('invalidBuildDirectories')]
    public function itRejectsInvalidBuildDirectories(string $buildDirectory): void
    {
        $resolvers = [
            fn (): string => $this->resolver->resolveOutputPath('/about/', $buildDirectory),
            fn (): string => $this->resolver->resolveAliasOutputPath('/old.html', $buildDirectory),
        ];

        foreach ($resolvers as $resolve) {
            try {
                $resolve();
                self::fail('Expected the build directory to be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertSame(sprintf('Invalid build directory "%s".', $buildDirectory), $exception->getMessage());
            }
        }
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
