<?php

declare(strict_types=1);

namespace Staccato\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Staccato\Service\ContentLoader;
use Staccato\Service\OutputPathResolver;

final class ContentLoaderTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = rtrim(sys_get_temp_dir(), '/').'/staccato-content-loader-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }
    }

    #[Test]
    public function itReturnsNoPagesWhenContentDirectoryIsMissing(): void
    {
        $loader = $this->createLoader();

        self::assertSame([], $loader->loadPages());
    }

    #[Test]
    public function itParsesFrontMatterAndMarkdownBody(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents(
            $this->projectDir.'/content/about.md',
            "---\ntitle: About Staccato\nslug: company/about\ndate: 2026-04-01\nexcerpt: Short summary\ndraft: false\n---\n# Hello\n\nWelcome.",
        );

        $loader = $this->createLoader();
        $pages = $loader->loadPages();

        self::assertCount(1, $pages);
        self::assertSame('about.md', $pages[0]->sourcePath);
        self::assertSame('About Staccato', $pages[0]->title);
        self::assertSame('company/about', $pages[0]->slug);
        self::assertSame('/company/about/', $pages[0]->urlPath);
        self::assertSame('Short summary', $pages[0]->excerpt);
        self::assertSame('2026-04-01', $pages[0]->publishedAt?->format('Y-m-d'));
        self::assertFalse($pages[0]->draft);
        self::assertStringContainsString('<h1 id="hello">Hello</h1>', $pages[0]->html);
        self::assertStringContainsString('<p>Welcome.</p>', $pages[0]->html);
    }

    #[Test]
    public function itHighlightsFencedCodeBlocks(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/code.md', "```php\n\$answer = 42;\n```\n");

        $loader = $this->createLoader();
        $pages = $loader->loadPages();

        self::assertCount(1, $pages);
        self::assertStringContainsString('<pre class="language-php">', $pages[0]->html);
        self::assertStringContainsString('<span class="hl-variable">$answer</span>', $pages[0]->html);
    }

    #[Test]
    public function itDerivesTitleSlugAndExcerptFromSourcePathWithoutFrontMatter(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/about-us.md', "# Home\n");

        $loader = $this->createLoader();
        $pages = $loader->loadPages();

        self::assertCount(1, $pages);
        self::assertSame('About Us', $pages[0]->title);
        self::assertSame('about-us', $pages[0]->slug);
        self::assertSame('/about-us/', $pages[0]->urlPath);
        self::assertSame('Home', $pages[0]->excerpt);
        self::assertNull($pages[0]->publishedAt);
    }

    #[Test]
    public function itMapsSiblingMarkdownFileToDirectoryUrl(): void
    {
        mkdir($this->projectDir.'/content/docs', 0777, true);
        file_put_contents($this->projectDir.'/content/docs.md', "# Docs\n");
        file_put_contents($this->projectDir.'/content/docs/setup.md', "# Setup\n");

        $loader = $this->createLoader();
        $pages = $loader->loadPages();

        self::assertSame(['/docs/', '/docs/setup/'], array_map(static fn ($page): string => $page->urlPath, $pages));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function indexFiles(): iterable
    {
        yield 'root index' => ['index.md', 'the homepage is generated from the articles/ directory'];
        yield 'articles index' => ['articles/index.md', 'the homepage is generated from the articles/ directory'];
        yield 'nested index' => ['docs/index.md', 'Rename it to "docs.md"'];
        yield 'uppercase index' => ['docs/Index.md', 'Rename it to "docs.md"'];
    }

    #[Test]
    #[DataProvider('indexFiles')]
    public function itRejectsIndexFiles(string $sourcePath, string $expectedHint): void
    {
        mkdir(dirname($this->projectDir.'/content/'.$sourcePath), 0777, true);
        file_put_contents($this->projectDir.'/content/'.$sourcePath, "# Index\n");

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Content file "'.preg_quote($sourcePath, '/').'" is not supported: index\.md files are not used.*'.preg_quote($expectedHint, '/').'/');

        $loader->loadPages();
    }

    #[Test]
    public function itRejectsFrontMatterSlugResolvingToTheHomepage(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/home.md', "---\nslug: /\n---\n# Home\n");

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reserved for the generated homepage');

        $loader->loadPages();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function frontMatterVariants(): iterable
    {
        yield 'windows line endings' => ["---\r\ntitle: Parsed\r\n---\r\n# Body\r\n", 'Parsed', '<h1 id="body">Body</h1>'];
        yield 'old mac line endings' => ["---\rtitle: Parsed\r---\r# Body\r", 'Parsed', '<h1 id="body">Body</h1>'];
        yield 'utf-8 byte order mark' => ["\u{FEFF}---\ntitle: Parsed\n---\n# Body\n", 'Parsed', '<h1 id="body">Body</h1>'];
        yield 'trailing whitespace on delimiters' => ["--- \t\ntitle: Parsed\n---  \n# Body\n", 'Parsed', '<h1 id="body">Body</h1>'];
        yield 'closing delimiter at end of file' => ["---\ntitle: Parsed\n---", 'Parsed', ''];
        yield 'empty front matter' => ["---\n---\n# Body\n", 'Variant', '<h1 id="body">Body</h1>'];
    }

    #[Test]
    #[DataProvider('frontMatterVariants')]
    public function itParsesFrontMatterVariants(string $source, string $expectedTitle, string $expectedHtml): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/variant.md', $source);

        $loader = $this->createLoader();
        $pages = $loader->loadPages();

        self::assertCount(1, $pages);
        self::assertSame($expectedTitle, $pages[0]->title);
        self::assertSame($expectedHtml, trim($pages[0]->html));
    }

    #[Test]
    public function itThrowsOnMissingClosingFrontMatterDelimiter(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/unclosed.md', "---\ntitle: Unclosed\n# Body\n");

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid front matter in "unclosed.md": missing closing delimiter.');

        $loader->loadPages();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function derivedExcerpts(): iterable
    {
        yield 'special characters' => ["Tom & Jerry say \"hi\" <3\n\nThe end.", 'Tom & Jerry say "hi" <3 The end.'];
        yield 'html entities' => ['Fish &amp; chips &lt;tasty&gt;', 'Fish & chips <tasty>'];
        yield 'whitespace across blocks' => ["# Title\n\nFirst   line\nwrapped.\n\n- one\n- two", 'Title First line wrapped. one two'];
        yield 'adjacent blocks' => ["Intro.\n```php\n\$a = 1;\n```", 'Intro. $a = 1;'];
        yield 'long text is truncated' => [str_repeat('word ', 60), rtrim(mb_substr(trim(str_repeat('word ', 60)), 0, 217)).'...'];
        yield 'empty body' => ['', ''];
    }

    #[Test]
    #[DataProvider('derivedExcerpts')]
    public function itDerivesThePlainTextExcerptFromTheRenderedBody(string $markdown, string $expectedExcerpt): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/excerpt.md', $markdown);

        $loader = $this->createLoader();
        $pages = $loader->loadPages();

        self::assertSame($expectedExcerpt, $pages[0]->excerpt);
    }

    #[Test]
    public function itThrowsOnInvalidYamlFrontMatter(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/broken.md', "---\ntitle: [invalid\n---\nbody");

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid YAML front matter in "broken.md"');

        $loader->loadPages();
    }

    #[Test]
    public function itThrowsOnInvalidDateFrontMatter(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/broken-date.md', "---\ndate: not-a-date\n---\n# body");

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Front matter key "date" in "broken-date.md" must be a valid date string.');

        $loader->loadPages();
    }

    private function removeDirectory(string $path): void
    {
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $entryPath = $path.'/'.$entry;

            if (is_dir($entryPath)) {
                $this->removeDirectory($entryPath);

                continue;
            }

            unlink($entryPath);
        }

        rmdir($path);
    }

    private function createLoader(): ContentLoader
    {
        return new ContentLoader($this->projectDir, new OutputPathResolver());
    }
}
