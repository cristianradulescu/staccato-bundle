<?php

declare(strict_types=1);

namespace Staccato\Tests\Service;

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
        self::assertStringContainsString('<h1>Hello</h1>', $pages[0]->html);
        self::assertStringContainsString('<p>Welcome.</p>', $pages[0]->html);
    }

    #[Test]
    public function itDerivesTitleAndRootSlugFromIndexFile(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/index.md', "# Home\n");

        $loader = $this->createLoader();
        $pages = $loader->loadPages();

        self::assertCount(1, $pages);
        self::assertSame('Index', $pages[0]->title);
        self::assertSame('', $pages[0]->slug);
        self::assertSame('/', $pages[0]->urlPath);
        self::assertSame('Home', $pages[0]->excerpt);
        self::assertNull($pages[0]->publishedAt);
    }

    #[Test]
    public function itMapsNestedIndexFileToParentSlug(): void
    {
        mkdir($this->projectDir.'/content/docs', 0777, true);
        file_put_contents($this->projectDir.'/content/docs/index.md', "# Docs\n");

        $loader = $this->createLoader();
        $pages = $loader->loadPages();

        self::assertCount(1, $pages);
        self::assertSame('docs', $pages[0]->slug);
        self::assertSame('/docs/', $pages[0]->urlPath);
        self::assertSame('Docs', $pages[0]->title);
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
