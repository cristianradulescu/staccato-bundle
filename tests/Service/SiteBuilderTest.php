<?php

declare(strict_types=1);

namespace Staccato\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
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
    private string $defaultAssetsDir;

    protected function setUp(): void
    {
        $this->projectDir = rtrim(sys_get_temp_dir(), '/').'/staccato-site-builder-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir, 0777, true);

        $this->defaultAssetsDir = rtrim(sys_get_temp_dir(), '/').'/staccato-default-assets-'.bin2hex(random_bytes(8));
        mkdir($this->defaultAssetsDir.'/css', 0777, true);
        file_put_contents($this->defaultAssetsDir.'/css/staccato.css', 'body { margin: 0; }');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }

        if (is_dir($this->defaultAssetsDir)) {
            $this->removeDirectory($this->defaultAssetsDir);
        }
    }

    #[Test]
    public function itBuildsPagesWithDirectoryStyleOutputPaths(): void
    {
        mkdir($this->projectDir.'/content/articles/docs', 0777, true);
        file_put_contents($this->projectDir.'/content/about.md', "---\ntitle: About\n---\n# About");
        file_put_contents($this->projectDir.'/content/articles/docs/getting-started.md', "---\ndate: 2026-04-20\n---\n# Start");

        $builder = $this->createBuilder();
        $result = $builder->build();

        self::assertSame(2, $result->generatedPages);
        self::assertSame(0, $result->skippedDraftPages);
        self::assertFileExists($this->projectDir.'/build/index.html');
        self::assertFileExists($this->projectDir.'/build/about/index.html');
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
        file_put_contents($this->projectDir.'/content/articles/hello.md', '# Hello');
        mkdir($this->projectDir.'/assets/images', 0777, true);
        file_put_contents($this->projectDir.'/assets/images/logo.txt', 'logo');

        $builder = $this->createBuilder();
        $result = $builder->build();

        self::assertTrue($result->assetsCopied);
        self::assertFileExists($this->projectDir.'/build/assets/images/logo.txt');
    }

    #[Test]
    public function itCopiesDefaultStylesByDefault(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/articles/hello.md', '# Hello');

        $builder = $this->createBuilder();
        $result = $builder->build();

        self::assertTrue($result->assetsCopied);
        self::assertFileExists($this->projectDir.'/build/assets/staccato/staccato.css');
    }

    #[Test]
    public function itSkipsDefaultStylesWhenDisabled(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/articles/hello.md', '# Hello');

        $builder = $this->createBuilder(includeDefaultStyles: false);
        $builder->build();

        self::assertFileDoesNotExist($this->projectDir.'/build/assets/staccato/staccato.css');
    }

    #[Test]
    public function itDoesNotCopyHighlighterStylesWhenNotConfigured(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/articles/hello.md', '# Hello');

        $builder = $this->createBuilder();
        $builder->build();

        self::assertFileExists($this->projectDir.'/build/assets/staccato/staccato.css');
        self::assertFileDoesNotExist($this->projectDir.'/build/assets/staccato/highlighter.css');
    }

    #[Test]
    public function itCopiesHighlighterStylesResolvedFromTheProjectDirectory(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/articles/hello.md', '# Hello');
        mkdir($this->projectDir.'/themes', 0777, true);
        file_put_contents($this->projectDir.'/themes/highlight.css', '.hl-keyword { color: red; }');

        $builder = $this->createBuilder(highlighterCss: 'themes/highlight.css');
        $builder->build();

        self::assertFileExists($this->projectDir.'/build/assets/staccato/highlighter.css');
        self::assertSame('.hl-keyword { color: red; }', file_get_contents($this->projectDir.'/build/assets/staccato/highlighter.css'));
    }

    #[Test]
    public function itFailsBeforeCleaningWhenHighlighterStylesAreMissing(): void
    {
        mkdir($this->projectDir.'/build', 0777, true);
        file_put_contents($this->projectDir.'/build/previous.html', 'previous build');

        $builder = $this->createBuilder(highlighterCss: 'themes/missing.css');

        try {
            $builder->build();
            self::fail('Expected the build to fail for a missing highlighter stylesheet.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString($this->projectDir.'/themes/missing.css', $exception->getMessage());
        }

        self::assertFileExists($this->projectDir.'/build/previous.html');
    }

    #[Test]
    public function itCopiesStylesheetsResolvedFromTheProjectDirectory(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/articles/hello.md', '# Hello');
        mkdir($this->projectDir.'/themes', 0777, true);
        file_put_contents($this->projectDir.'/themes/custom.css', '.custom { color: blue; }');

        $builder = $this->createBuilder(stylesheets: ['themes/custom.css']);
        $builder->build();

        self::assertSame('.custom { color: blue; }', file_get_contents($this->projectDir.'/build/assets/staccato/custom.css'));
    }

    #[Test]
    public function itFailsBeforeCleaningWhenAStylesheetIsMissing(): void
    {
        mkdir($this->projectDir.'/build', 0777, true);
        file_put_contents($this->projectDir.'/build/previous.html', 'previous build');

        $builder = $this->createBuilder(stylesheets: ['themes/missing.css']);

        try {
            $builder->build();
            self::fail('Expected the build to fail for a missing stylesheet.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('stylesheet file', $exception->getMessage());
            self::assertStringContainsString($this->projectDir.'/themes/missing.css', $exception->getMessage());
        }

        self::assertFileExists($this->projectDir.'/build/previous.html');
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function conflictingStylesheets(): iterable
    {
        yield 'same file name twice' => [['themes/a/custom.css', 'themes/b/custom.css']];
        yield 'reserved staccato.css' => [['themes/a/staccato.css']];
        yield 'reserved highlighter.css' => [['themes/a/highlighter.css']];
    }

    /**
     * @param list<string> $stylesheets
     */
    #[Test]
    #[DataProvider('conflictingStylesheets')]
    public function itRejectsStylesheetsWithConflictingFileNames(array $stylesheets): void
    {
        foreach (['a', 'b'] as $directory) {
            mkdir($this->projectDir.'/themes/'.$directory, 0777, true);

            foreach (['custom.css', 'staccato.css', 'highlighter.css'] as $fileName) {
                file_put_contents($this->projectDir.'/themes/'.$directory.'/'.$fileName, 'body {}');
            }
        }

        $builder = $this->createBuilder(stylesheets: $stylesheets);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('conflicts with another stylesheet');

        $builder->build();
    }

    #[Test]
    public function itCopiesOwnStylesheetsWhenDefaultStylesAreDisabled(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/articles/hello.md', '# Hello');
        mkdir($this->projectDir.'/themes', 0777, true);
        file_put_contents($this->projectDir.'/themes/my-theme.css', 'body { color: black; }');
        file_put_contents($this->projectDir.'/themes/highlight.css', '.hl-keyword { color: red; }');

        $builder = $this->createBuilder(includeDefaultStyles: false, highlighterCss: 'themes/highlight.css', stylesheets: ['themes/my-theme.css']);
        $result = $builder->build();

        self::assertTrue($result->assetsCopied);
        self::assertFileDoesNotExist($this->projectDir.'/build/assets/staccato/staccato.css');
        self::assertFileExists($this->projectDir.'/build/assets/staccato/my-theme.css');
        self::assertFileExists($this->projectDir.'/build/assets/staccato/highlighter.css');
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidContent(): iterable
    {
        yield 'invalid front matter' => [['articles/broken.md' => "---\ntitle: [invalid\n---\nbody"]];
        yield 'index file' => [['index.md' => '# Home']];
        yield 'duplicate output path' => [[
            'articles/about.md' => '# About',
            'articles/about-us.md' => "---\nslug: about\n---\n# About Us",
        ]];

        $paginationConflict = ['page/2.md' => '# Reserved by pagination'];

        for ($index = 1; $index <= 11; ++$index) {
            $paginationConflict[sprintf('articles/article-%02d.md', $index)] = sprintf('# Article %02d', $index);
        }

        yield 'home pagination conflict' => [$paginationConflict];
    }

    /**
     * @param array<string, string> $files content-relative path => contents
     */
    #[Test]
    #[DataProvider('invalidContent')]
    public function itKeepsThePreviousBuildWhenContentIsInvalid(array $files): void
    {
        mkdir($this->projectDir.'/build', 0777, true);
        file_put_contents($this->projectDir.'/build/previous.html', 'previous build');

        foreach ($files as $path => $contents) {
            $absolutePath = $this->projectDir.'/content/'.$path;

            if (!is_dir(dirname($absolutePath))) {
                mkdir(dirname($absolutePath), 0777, true);
            }

            file_put_contents($absolutePath, $contents);
        }

        $builder = $this->createBuilder();

        try {
            $builder->build();
            self::fail('Expected the build to fail for invalid content.');
        } catch (\RuntimeException) {
        }

        self::assertFileExists($this->projectDir.'/build/previous.html');
    }

    #[Test]
    public function itFailsWhenTwoPagesResolveToTheSameOutputPath(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/articles/about.md', '# About');
        file_put_contents($this->projectDir.'/content/articles/about-us.md', "---\nslug: about\n---\n# About Us");

        $builder = $this->createBuilder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate output path');

        $builder->build();
    }

    #[Test]
    public function itFailsWhenHomePaginationConflictsWithContentPath(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        mkdir($this->projectDir.'/content/page', 0777, true);
        file_put_contents($this->projectDir.'/content/page/2.md', '# Reserved by pagination');

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

    /**
     * @param list<string> $stylesheets
     */
    private function createBuilder(bool $includeDefaultStyles = true, ?string $highlighterCss = null, array $stylesheets = []): SiteBuilder
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
            includeDefaultStyles: $includeDefaultStyles,
            stylesheets: $stylesheets,
            highlighterCss: $highlighterCss,
        );
        $twig = new Environment(new ArrayLoader([
            '@Staccato/page.html.twig' => '<html><body><h1>{{ page.title }}</h1>{{ page.html|raw }}</body></html>',
            '@Staccato/home.html.twig' => '<html><body>{% for article in home.articles %}<a href="{{ article.urlPath }}">Read more -&gt;</a>{% endfor %}{% if home.totalPages > 1 %}page {{ home.currentPage }} of {{ home.totalPages }}{% endif %}</body></html>',
            '@Staccato/rss.xml.twig' => '<rss version="2.0">{% for article in articles %}<item><title>{{ article.title }}</title></item>{% endfor %}</rss>',
            '@Staccato/sitemap.xml.twig' => '<urlset>{% for path in urls %}<url><loc>{{ baseUrl }}{{ path }}</loc></url>{% endfor %}</urlset>',
            '@Staccato/404.html.twig' => '<html><body>404</body></html>',
        ]));
        $renderer = new PageRenderer($twig);
        $fixtureGenerator = new FixtureContentGenerator($this->projectDir, $filesystem);

        return new SiteBuilder($this->projectDir, $this->defaultAssetsDir, $filesystem, $siteConfig, $loader, $fixtureGenerator, $resolver, $renderer);
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
