<?php

declare(strict_types=1);

namespace Staccato\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Staccato\Model\SiteConfig;
use Staccato\Service\ContentLoader;
use Staccato\Service\FixtureContentGenerator;
use Staccato\Service\OutputPathResolver;
use Staccato\Service\PageRenderer;
use Staccato\Service\SiteBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class SiteBuilderTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = rtrim(sys_get_temp_dir(), '/').'/staccato-site-builder-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }
    }

    #[Test]
    public function itBuildsPagesWithDirectoryStyleOutputPaths(): void
    {
        mkdir($this->projectDir.'/content/articles/docs', 0777, true);
        file_put_contents($this->projectDir.'/content/index.md', "---\ntitle: Home\n---\n# Welcome");
        file_put_contents($this->projectDir.'/content/articles/docs/getting-started.md', "---\ndate: 2026-04-20\n---\n# Start");

        $builder = $this->createBuilder();
        $result = $builder->build();

        self::assertSame(1, $result->generatedPages);
        self::assertSame(0, $result->skippedDraftPages);
        self::assertFileExists($this->projectDir.'/build/index.html');
        self::assertFileExists($this->projectDir.'/build/docs/getting-started/index.html');
        self::assertFileExists($this->projectDir.'/build/404.html');
        self::assertFileExists($this->projectDir.'/build/rss.xml');
        self::assertFileExists($this->projectDir.'/build/sitemap.xml');
        self::assertStringContainsString('Read more -&gt;', (string) file_get_contents($this->projectDir.'/build/index.html'));
    }

    #[Test]
    public function itSkipsDraftPagesByDefault(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/articles/public.md', '# Public');
        file_put_contents($this->projectDir.'/content/articles/draft.md', "---\ndraft: true\n---\n# Draft");

        $builder = $this->createBuilder();
        $result = $builder->build();

        self::assertSame(1, $result->generatedPages);
        self::assertSame(1, $result->skippedDraftPages);
        self::assertFileExists($this->projectDir.'/build/public/index.html');
        self::assertFileDoesNotExist($this->projectDir.'/build/draft/index.html');
    }

    #[Test]
    public function itCanIncludeDraftPagesWhenRequested(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/articles/draft.md', "---\ndraft: true\n---\n# Draft");

        $builder = $this->createBuilder();
        $result = $builder->build(includeDrafts: true);

        self::assertSame(1, $result->generatedPages);
        self::assertSame(0, $result->skippedDraftPages);
        self::assertFileExists($this->projectDir.'/build/draft/index.html');
    }

    #[Test]
    public function itCopiesAssetsWhenAssetsDirectoryExists(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/articles/index.md', '# Home');
        mkdir($this->projectDir.'/assets/images', 0777, true);
        file_put_contents($this->projectDir.'/assets/images/logo.txt', 'logo');

        $builder = $this->createBuilder();
        $result = $builder->build();

        self::assertTrue($result->assetsCopied);
        self::assertFileExists($this->projectDir.'/build/assets/images/logo.txt');
    }

    #[Test]
    public function itFailsWhenTwoPagesResolveToTheSameOutputPath(): void
    {
        mkdir($this->projectDir.'/content/articles/about', 0777, true);
        file_put_contents($this->projectDir.'/content/articles/about.md', '# About');
        file_put_contents($this->projectDir.'/content/articles/about/index.md', '# About Index');

        $builder = $this->createBuilder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate output path');

        $builder->build();
    }

    #[Test]
    public function itFailsWhenHomePaginationConflictsWithContentPath(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        mkdir($this->projectDir.'/content/page/2', 0777, true);
        file_put_contents($this->projectDir.'/content/page/2/index.md', '# Reserved by pagination');

        for ($index = 1; $index <= 12; ++$index) {
            file_put_contents(
                sprintf('%s/content/articles/article-%02d.md', $this->projectDir, $index),
                sprintf("---\ntitle: Article %02d\ndate: 2026-03-%02d\n---\n# Article %02d\n\nLorem ipsum.", $index, (($index - 1) % 28) + 1, $index),
            );
        }

        $builder = $this->createBuilder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Home pagination path');

        $builder->build();
    }

    #[Test]
    public function itBuildsPaginatedHomeWhenMoreThanTenArticlesExist(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);

        for ($index = 1; $index <= 35; ++$index) {
            file_put_contents(
                sprintf('%s/content/articles/article-%02d.md', $this->projectDir, $index),
                sprintf("---\ntitle: Article %02d\ndate: 2026-03-%02d\n---\n# Article %02d\n\nLorem ipsum.", $index, (($index - 1) % 28) + 1, $index),
            );
        }

        $builder = $this->createBuilder();
        $builder->build();

        self::assertFileExists($this->projectDir.'/build/index.html');
        self::assertFileExists($this->projectDir.'/build/page/2/index.html');
        self::assertFileExists($this->projectDir.'/build/page/3/index.html');
        self::assertFileExists($this->projectDir.'/build/page/4/index.html');
        self::assertStringContainsString('<rss version="2.0">', (string) file_get_contents($this->projectDir.'/build/rss.xml'));
        self::assertStringContainsString('<loc>https://example.test/</loc>', (string) file_get_contents($this->projectDir.'/build/sitemap.xml'));
        self::assertStringContainsString('<loc>https://example.test/rss.xml</loc>', (string) file_get_contents($this->projectDir.'/build/sitemap.xml'));
        self::assertStringContainsString('<loc>https://example.test/page/4/</loc>', (string) file_get_contents($this->projectDir.'/build/sitemap.xml'));
        self::assertSame(10, substr_count((string) file_get_contents($this->projectDir.'/build/index.html'), 'Read more -&gt;'));
        self::assertSame(10, substr_count((string) file_get_contents($this->projectDir.'/build/page/2/index.html'), 'Read more -&gt;'));
        self::assertSame(10, substr_count((string) file_get_contents($this->projectDir.'/build/page/3/index.html'), 'Read more -&gt;'));
        self::assertSame(5, substr_count((string) file_get_contents($this->projectDir.'/build/page/4/index.html'), 'Read more -&gt;'));
    }

    #[Test]
    public function itCanBuildUsingGeneratedFixturesWithoutContentDirectory(): void
    {
        $builder = $this->createBuilder();
        $result = $builder->build(useFixtures: true);

        self::assertSame(35, $result->generatedPages);
        self::assertFileExists($this->projectDir.'/build/index.html');
        self::assertFileExists($this->projectDir.'/build/page/2/index.html');
        self::assertFileExists($this->projectDir.'/build/page/3/index.html');
        self::assertFileExists($this->projectDir.'/build/page/4/index.html');
        self::assertFileExists($this->projectDir.'/var/fixtures/content/articles/article-01.md');
        self::assertFileExists($this->projectDir.'/build/rss.xml');
        self::assertFileExists($this->projectDir.'/build/sitemap.xml');
        self::assertFileExists($this->projectDir.'/build/404.html');
    }

    private function createBuilder(): SiteBuilder
    {
        $resolver = new OutputPathResolver();
        $loader = new ContentLoader($this->projectDir, $resolver);
        $filesystem = new Filesystem();
        $siteConfig = new SiteConfig(
            title: 'Test Site',
            baseUrl: 'https://example.test',
            description: 'Test site description',
            articlesPerPage: 10,
            footerText: 'Built with Staccato',
        );
        $twig = new Environment(new ArrayLoader([
            'page.html.twig' => '<html><body><h1>{{ page.title }}</h1>{{ page.html|raw }}</body></html>',
            'home.html.twig' => '<html><body>{% for article in home.articles %}<a href="{{ article.urlPath }}">Read more -&gt;</a>{% endfor %}{% if home.totalPages > 1 %}page {{ home.currentPage }} of {{ home.totalPages }}{% endif %}</body></html>',
            'rss.xml.twig' => '<rss version="2.0">{% for article in articles %}<item><title>{{ article.title }}</title></item>{% endfor %}</rss>',
            'sitemap.xml.twig' => '<urlset>{% for path in urls %}<url><loc>{{ baseUrl }}{{ path }}</loc></url>{% endfor %}</urlset>',
            '404.html.twig' => '<html><body>404</body></html>',
        ]));
        $renderer = new PageRenderer($twig);
        $fixtureGenerator = new FixtureContentGenerator($this->projectDir, $filesystem);

        return new SiteBuilder($this->projectDir, $filesystem, $siteConfig, $loader, $fixtureGenerator, $resolver, $renderer);
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
}
