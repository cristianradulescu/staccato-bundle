<?php

declare(strict_types=1);

namespace Staccato\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Staccato\Event\SiteBuiltEvent;
use Staccato\Model\SiteConfig;
use Staccato\Service\ContentLoader;
use Staccato\Service\FixtureContentGenerator;
use Staccato\Service\OutputPathResolver;
use Staccato\Service\PageRenderer;
use Staccato\Service\SiteBuilder;
use Staccato\Service\StylesheetResolver;
use Symfony\Component\EventDispatcher\EventDispatcher;
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
    public function itPassesFrontMatterMetaToHomeListingItems(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/articles/hello.md', "---\nauthor: Jane Doe\n---\n# Hello");

        $this->createBuilder()->build();

        self::assertStringContainsString('<span class="author">Jane Doe</span>', (string) file_get_contents($this->projectDir.'/build/index.html'));
    }

    #[Test]
    public function itDispatchesSiteBuiltEventAfterEveryFileIsWritten(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/about.md', '# About');
        file_put_contents($this->projectDir.'/content/articles/hello.md', '# Hello');
        file_put_contents($this->projectDir.'/content/articles/draft.md', "---\ndraft: true\n---\n# Draft");
        mkdir($this->projectDir.'/static', 0777, true);
        file_put_contents($this->projectDir.'/static/robots.txt', 'User-agent: *');

        $events = [];
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(SiteBuiltEvent::class, function (SiteBuiltEvent $event) use (&$events): void {
            // Everything the build writes is already in place when listeners run.
            self::assertFileExists($event->buildDirectory.'/index.html');
            self::assertFileExists($event->buildDirectory.'/sitemap.xml');
            self::assertFileExists($event->buildDirectory.'/robots.txt');
            self::assertFileExists($event->buildDirectory.'/assets/staccato/staccato.css');

            file_put_contents($event->buildDirectory.'/search.json', '[]');
            $events[] = $event;
        });

        $this->createBuilder(eventDispatcher: $eventDispatcher)->build();

        self::assertCount(1, $events);
        self::assertSame($this->projectDir.'/build', $events[0]->buildDirectory);
        self::assertSame(['about.md', 'articles/hello.md'], array_map(static fn ($page): string => $page->sourcePath, $events[0]->pages));
        self::assertFileExists($this->projectDir.'/build/search.json');
    }

    #[Test]
    public function itDoesNotDispatchSiteBuiltEventWhenTheBuildFails(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/articles/about.md', '# About');
        file_put_contents($this->projectDir.'/content/articles/about-us.md', "---\nslug: about\n---\n# About Us");

        $dispatched = false;
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(SiteBuiltEvent::class, static function () use (&$dispatched): void {
            $dispatched = true;
        });

        try {
            $this->createBuilder(eventDispatcher: $eventDispatcher)->build();
            self::fail('Expected the build to fail.');
        } catch (\RuntimeException) {
        }

        self::assertFalse($dispatched);
    }

    #[Test]
    public function itWritesRedirectStubsForAliases(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        // The first alias differs from the page's URL only by case: allowed, and written next to the page on a
        // case-sensitive filesystem (writeRedirects() fails on the others).
        file_put_contents($this->projectDir.'/content/articles/2022-08-20-github-pages.md', "---\naliases: ['/2022-08-20-Github-pages/', /2022/08/github-pages.html]\n---\n# Pages");
        file_put_contents($this->projectDir.'/content/articles/draft.md', "---\ndraft: true\naliases: [/old-draft/]\n---\n# Draft");

        $result = $this->createBuilder()->build();

        self::assertSame(2, $result->redirectsGenerated);
        $expectedStub = '<meta http-equiv="refresh" content="0; url=/2022-08-20-github-pages/">';
        self::assertSame($expectedStub, file_get_contents($this->projectDir.'/build/2022-08-20-Github-pages/index.html'));
        self::assertSame($expectedStub, file_get_contents($this->projectDir.'/build/2022/08/github-pages.html'));
        self::assertStringContainsString('<h1>', (string) file_get_contents($this->projectDir.'/build/2022-08-20-github-pages/index.html'));
        self::assertDirectoryDoesNotExist($this->projectDir.'/build/old-draft');
        self::assertStringNotContainsString('Github-pages', (string) file_get_contents($this->projectDir.'/build/sitemap.xml'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function conflictingAliases(): iterable
    {
        yield 'own page' => [['post.md' => "---\naliases: [/post/]\n---\n# Post"], 'Alias "/post/" of "post.md" conflicts with the page generated from "post.md".'];
        yield 'other page' => [['about.md' => '# About', 'post.md' => "---\naliases: [/about]\n---\n# Post"], 'Alias "/about/" of "post.md" conflicts with the page generated from "about.md".'];
        yield 'pagination' => [['post.md' => "---\naliases: [/page/2/]\n---\n# Post"] + self::articles(11), 'Alias "/page/2/" of "post.md" conflicts with the homepage listing.'];
        yield 'not found page' => [['post.md' => "---\naliases: [/404.html]\n---\n# Post"], 'Alias "/404.html" of "post.md" conflicts with the generated "404.html".'];
        yield 'index file of the homepage' => [['post.md' => "---\naliases: [/index.html]\n---\n# Post"], 'Alias "/index.html" of "post.md" conflicts with the homepage listing.'];
        yield 'alias of another page' => [['a.md' => "---\naliases: [/old/]\n---\n# A", 'b.md' => "---\naliases: [/old/]\n---\n# B"], 'Alias "/old/" of "b.md" conflicts with an alias of "a.md".'];
        yield 'published assets' => [['post.md' => "---\naliases: [/assets/old.html]\n---\n# Post"], 'Alias "/assets/old.html" of "post.md" conflicts with the published assets.'];
        yield 'inside the feed' => [['post.md' => "---\naliases: [/rss.xml]\n---\n# Post"], 'Alias "/rss.xml/" of "post.md" conflicts with the generated "rss.xml".'];
        yield 'inside the sitemap' => [['post.md' => "---\naliases: [/sitemap.xml]\n---\n# Post"], 'Alias "/sitemap.xml/" of "post.md" conflicts with the generated "sitemap.xml".'];
        yield 'inside the not found page' => [['post.md' => "---\naliases: [/404.html/old.html]\n---\n# Post"], 'Alias "/404.html/old.html" of "post.md" conflicts with the generated "404.html".'];
        yield 'inside the file of a page' => [['about.md' => '# About', 'post.md' => "---\naliases: [/about/index.html/old]\n---\n# Post"], 'Alias "/about/index.html/old/" of "post.md" conflicts with the page generated from "about.md".'];
        yield 'inside an html alias of another page' => [['a.md' => "---\naliases: [/old.html]\n---\n# A", 'b.md' => "---\naliases: [/old.html/sub]\n---\n# B"], 'Alias "/old.html/sub/" of "b.md" conflicts with an alias of "a.md".'];
        yield 'around an alias of another page' => [['a.md' => "---\naliases: [/old.html/sub]\n---\n# A", 'b.md' => "---\naliases: [/old.html]\n---\n# B"], 'Alias "/old.html" of "b.md" conflicts with an alias of "a.md".'];
        yield 'inside an html alias of the same page' => [['post.md' => "---\naliases: [/old.html, /old.html/sub.html]\n---\n# Post"], 'Alias "/old.html/sub.html" of "post.md" conflicts with an alias of "post.md".'];
        // Nesting ignores case: on a case-insensitive filesystem these are inside the file they differ from by case.
        yield 'inside the feed in another case' => [['post.md' => "---\naliases: [/RSS.xml]\n---\n# Post"], 'Alias "/RSS.xml/" of "post.md" conflicts with the generated "rss.xml".'];
        yield 'inside the not found page in another case' => [['post.md' => "---\naliases: [/404.HTML/x/]\n---\n# Post"], 'Alias "/404.HTML/x/" of "post.md" conflicts with the generated "404.html".'];
        yield 'published assets in another case' => [['post.md' => "---\naliases: [/Assets/x/]\n---\n# Post"], 'Alias "/Assets/x/" of "post.md" conflicts with the published assets.'];
        yield 'inside the file of a page in another case' => [['about.md' => '# About', 'post.md' => "---\naliases: [/About/index.html/old]\n---\n# Post"], 'Alias "/About/index.html/old/" of "post.md" conflicts with the page generated from "about.md".'];
        yield 'inside an html alias of another page in another case' => [['a.md' => "---\naliases: [/Old.html]\n---\n# A", 'b.md' => "---\naliases: [/OLD.html/page/]\n---\n# B"], 'Alias "/OLD.html/page/" of "b.md" conflicts with an alias of "a.md".'];
    }

    /**
     * @param array<string, string> $files
     */
    #[Test]
    #[DataProvider('conflictingAliases')]
    public function itFailsBeforeCleaningWhenAnAliasConflicts(array $files, string $expectedMessage): void
    {
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname($this->projectDir.'/content/'.$path))) {
                mkdir(dirname($this->projectDir.'/content/'.$path), 0777, true);
            }

            file_put_contents($this->projectDir.'/content/'.$path, $contents);
        }

        $this->writePreviousBuild();

        try {
            $this->createBuilder()->build();
            self::fail('Expected the build to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }

        self::assertFileExists($this->projectDir.'/build/previous.html');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function aliasesNestedInsideTheFeed(): iterable
    {
        yield 'same case' => ['/rss.xml', 'Alias "/rss.xml/" of "articles/post.md" conflicts with the generated "rss.xml".'];
        yield 'other case' => ['/RSS.xml', 'Alias "/RSS.xml/" of "articles/post.md" conflicts with the generated "rss.xml".'];
    }

    #[Test]
    #[DataProvider('aliasesNestedInsideTheFeed')]
    public function itKeepsThePreviousBuildWhenAnAliasNestsInsideAGeneratedFile(string $alias, string $expectedMessage): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/articles/post.md', '# Post');
        mkdir($this->projectDir.'/static', 0777, true);
        file_put_contents($this->projectDir.'/static/robots.txt', 'User-agent: *');
        $this->createBuilder()->build();
        $previousBuild = $this->directoryContents('build');

        file_put_contents($this->projectDir.'/content/articles/post.md', sprintf("---\naliases: [/feed/, %s]\n---\n# Post", $alias));

        try {
            $this->createBuilder()->build();
            self::fail('Expected the build to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }

        self::assertSame($previousBuild, $this->directoryContents('build'));
    }

    #[Test]
    public function itWritesAliasesNestedInsideDirectoryStyleOutputs(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/a.md', '# A');
        file_put_contents($this->projectDir.'/content/docs.md', '# Docs');
        // /rss.xml.html shares a string prefix with the feed without being inside it. /Docs/Setup/ differs from the
        // /docs/ page by case, and nests inside it on a case-insensitive filesystem as /a/b/ does inside /a/.
        file_put_contents($this->projectDir.'/content/post.md', "---\naliases: [/a/b/, /Docs/Setup/, /old/, /old/sub.html, /rss.xml.html]\n---\n# Post");

        $result = $this->createBuilder()->build();

        self::assertSame(5, $result->redirectsGenerated);
        self::assertStringContainsString('<h1>', (string) file_get_contents($this->projectDir.'/build/a/index.html'));
        self::assertStringContainsString('<h1>', (string) file_get_contents($this->projectDir.'/build/docs/index.html'));
        $expectedStub = '<meta http-equiv="refresh" content="0; url=/post/">';
        self::assertSame($expectedStub, file_get_contents($this->projectDir.'/build/a/b/index.html'));
        self::assertSame($expectedStub, file_get_contents($this->projectDir.'/build/Docs/Setup/index.html'));
        self::assertSame($expectedStub, file_get_contents($this->projectDir.'/build/old/index.html'));
        self::assertSame($expectedStub, file_get_contents($this->projectDir.'/build/old/sub.html'));
        self::assertSame($expectedStub, file_get_contents($this->projectDir.'/build/rss.xml.html'));
        self::assertStringContainsString('<rss version="2.0">', (string) file_get_contents($this->projectDir.'/build/rss.xml'));
    }

    #[Test]
    public function itFailsWhenAStaticFileConflictsWithAnAlias(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/post.md', "---\naliases: [/old.html]\n---\n# Post");
        mkdir($this->projectDir.'/static', 0777, true);
        file_put_contents($this->projectDir.'/static/old.html', 'static');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Static file "old.html" conflicts with the generated "old.html"');

        $this->createBuilder()->build();
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
    public function itCopiesStaticFilesToTheBuildRoot(): void
    {
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/articles/hello.md', '# Hello');
        mkdir($this->projectDir.'/static/.well-known', 0777, true);
        file_put_contents($this->projectDir.'/static/favicon.ico', 'icon');
        file_put_contents($this->projectDir.'/static/robots.txt', 'User-agent: *');
        file_put_contents($this->projectDir.'/static/.nojekyll', '');
        file_put_contents($this->projectDir.'/static/.well-known/security.txt', 'Contact: me');
        file_put_contents($this->projectDir.'/static/hello.txt', 'next to the hello/ page, not inside it');

        $result = $this->createBuilder()->build();

        self::assertSame(5, $result->staticFilesCopied);
        self::assertSame('icon', file_get_contents($this->projectDir.'/build/favicon.ico'));
        self::assertFileExists($this->projectDir.'/build/robots.txt');
        self::assertFileExists($this->projectDir.'/build/.nojekyll');
        self::assertFileExists($this->projectDir.'/build/.well-known/security.txt');
        self::assertFileExists($this->projectDir.'/build/hello.txt');
        self::assertFileExists($this->projectDir.'/build/hello/index.html');
    }

    #[Test]
    public function itSkipsVersionControlFilesInTheStaticDirectory(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        mkdir($this->projectDir.'/static/.git', 0777, true);
        file_put_contents($this->projectDir.'/static/.git/HEAD', 'ref: refs/heads/main');
        file_put_contents($this->projectDir.'/static/robots.txt', 'User-agent: *');

        $result = $this->createBuilder()->build();

        self::assertSame(1, $result->staticFilesCopied);
        self::assertDirectoryDoesNotExist($this->projectDir.'/build/.git');
    }

    #[Test]
    public function itCopiesNoStaticFilesWhenTheStaticDirectoryIsMissing(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);

        $result = $this->createBuilder()->build();

        self::assertSame(0, $result->staticFilesCopied);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function conflictingStaticFiles(): iterable
    {
        yield 'homepage' => ['index.html', '"index.html"'];
        yield 'feed' => ['rss.xml', '"rss.xml"'];
        yield 'sitemap' => ['sitemap.xml', '"sitemap.xml"'];
        yield 'not found page' => ['404.html', '"404.html"'];
        yield 'content page' => ['about/index.html', '"about/index.html"'];
        yield 'file where a page directory goes' => ['about', '"about/index.html"'];
        yield 'published assets' => ['assets/logo.png', '"assets"'];
        // Unlike aliases, static files may not differ from a generated file only by case either.
        yield 'feed in another case' => ['RSS.xml', '"rss.xml"'];
        yield 'content page in another case' => ['About/index.html', '"about/index.html"'];
        yield 'inside the feed in another case' => ['RSS.xml/feed', '"rss.xml"'];
        yield 'published assets in another case' => ['Assets/x.txt', '"assets"'];
    }

    #[Test]
    #[DataProvider('conflictingStaticFiles')]
    public function itFailsBeforeCleaningWhenAStaticFileConflictsWithGeneratedOutput(string $staticFile, string $conflict): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/about.md', '# About');
        mkdir(dirname($this->projectDir.'/static/'.$staticFile), 0777, true);
        file_put_contents($this->projectDir.'/static/'.$staticFile, 'static');
        $this->writePreviousBuild();

        try {
            $this->createBuilder()->build();
            self::fail('Expected the build to fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(sprintf('Static file "%s" conflicts with the generated %s', $staticFile, $conflict), $exception->getMessage());
        }

        self::assertFileExists($this->projectDir.'/build/previous.html');
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
        $this->writePreviousBuild();

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
        $this->writePreviousBuild();

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
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidContent(): iterable
    {
        yield 'invalid front matter' => [['articles/broken.md' => "---\ntitle: [invalid\n---\nbody"], 'Invalid YAML front matter in "articles/broken.md": '];
        yield 'index file' => [['index.md' => '# Home'], 'Content file "index.md" is not supported'];
        yield 'duplicate output path' => [[
            'articles/about.md' => '# About',
            'articles/about-us.md' => "---\nslug: about\n---\n# About Us",
        ], 'Duplicate output path "build/about/index.html"'];

        $paginationConflict = ['page/2.md' => '# Reserved by pagination'];

        for ($index = 1; $index <= 11; ++$index) {
            $paginationConflict[sprintf('articles/article-%02d.md', $index)] = sprintf('# Article %02d', $index);
        }

        yield 'home pagination conflict' => [$paginationConflict, 'Home pagination path "build/page/2/index.html" conflicts with content file "page/2.md".'];
    }

    /**
     * @param array<string, string> $files content-relative path => contents
     */
    #[Test]
    #[DataProvider('invalidContent')]
    public function itKeepsThePreviousBuildWhenContentIsInvalid(array $files, string $expectedMessage): void
    {
        $this->writePreviousBuild();

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
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString($expectedMessage, $exception->getMessage());
        }

        self::assertFileExists($this->projectDir.'/build/previous.html');
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function directoriesThatAreNotAPreviousBuild(): iterable
    {
        yield 'source code' => ['src', ['src/Kernel.php' => '<?php // kernel', 'src/Controller/HomeController.php' => '<?php // controller'], 'The build directory "src" is not empty and does not look like a previous Staccato build (it has no sitemap.xml or 404.html), so a clean build would delete it. Set "staccato.build_dir" to a new directory, or empty or remove "src" yourself.'];
        yield 'version control' => ['.git', ['.git/HEAD' => 'ref: refs/heads/main', '.git/objects/ab/cdef' => 'object'], 'The build directory ".git" is not empty and does not look like a previous Staccato build (it has no sitemap.xml or 404.html), so a clean build would delete it. Set "staccato.build_dir" to a new directory, or empty or remove ".git" yourself.'];
        yield 'hand-written docs' => ['docs', ['docs/index.html' => '<h1>Docs</h1>', 'docs/guide/index.html' => '<h1>Guide</h1>'], 'The build directory "docs" is not empty and does not look like a previous Staccato build (it has no sitemap.xml or 404.html), so a clean build would delete it. Set "staccato.build_dir" to a new directory, or empty or remove "docs" yourself.'];
        yield 'only dot files' => ['build', ['build/.gitkeep' => ''], 'The build directory "build" is not empty and does not look like a previous Staccato build (it has no sitemap.xml or 404.html), so a clean build would delete it. Set "staccato.build_dir" to a new directory, or empty or remove "build" yourself.'];
        yield 'only a sitemap' => ['build', ['build/index.html' => 'previous', 'build/sitemap.xml' => '<urlset></urlset>'], 'The build directory "build" is not empty and does not look like a previous Staccato build (it has no 404.html), so a clean build would delete it. Set "staccato.build_dir" to a new directory, or empty or remove "build" yourself.'];
        yield 'only a not found page' => ['build', ['build/index.html' => 'previous', 'build/404.html' => '404'], 'The build directory "build" is not empty and does not look like a previous Staccato build (it has no sitemap.xml), so a clean build would delete it. Set "staccato.build_dir" to a new directory, or empty or remove "build" yourself.'];
        yield 'directories named like the generated files' => ['build', ['build/sitemap.xml/index.html' => 'page', 'build/404.html/index.html' => 'page'], 'The build directory "build" is not empty and does not look like a previous Staccato build (it has no sitemap.xml or 404.html), so a clean build would delete it. Set "staccato.build_dir" to a new directory, or empty or remove "build" yourself.'];
    }

    /**
     * @param array<string, string> $files project-relative path => contents
     */
    #[Test]
    #[DataProvider('directoriesThatAreNotAPreviousBuild')]
    public function itRefusesToCleanABuildDirectoryThatIsNotAPreviousBuild(string $buildDirectory, array $files, string $expectedMessage): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/about.md', '# About');

        foreach ($files as $path => $contents) {
            if (!is_dir(dirname($this->projectDir.'/'.$path))) {
                mkdir(dirname($this->projectDir.'/'.$path), 0777, true);
            }

            file_put_contents($this->projectDir.'/'.$path, $contents);
        }

        $before = $this->directoryContents($buildDirectory);

        try {
            $this->createBuilder(buildDirectory: $buildDirectory)->build();
            self::fail('Expected the build to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }

        self::assertSame($before, $this->directoryContents($buildDirectory));
    }

    #[Test]
    public function itRefusesToCleanABuildDirectoryThatIsAFile(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/about.md', '# About');
        file_put_contents($this->projectDir.'/build', 'a file');

        try {
            $this->createBuilder()->build();
            self::fail('Expected the build to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The build directory "build" exists but is not a directory, so a clean build would delete it. Set "staccato.build_dir" to a new directory, or remove "build" yourself.', $exception->getMessage());
        }

        self::assertSame('a file', file_get_contents($this->projectDir.'/build'));
    }

    #[Test]
    public function itRefusesToCleanTheVarDirectoryWhenBuildingFromFixtures(): void
    {
        mkdir($this->projectDir.'/var/cache/dev', 0777, true);
        file_put_contents($this->projectDir.'/var/cache/dev/container.php', '<?php // container');

        try {
            $this->createBuilder(buildDirectory: 'var')->build(useFixtures: true);
            self::fail('Expected the build to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The build directory "var" is not empty and does not look like a previous Staccato build (it has no sitemap.xml or 404.html), so a clean build would delete it. Set "staccato.build_dir" to a new directory, or empty or remove "var" yourself.', $exception->getMessage());
        }

        self::assertSame('<?php // container', file_get_contents($this->projectDir.'/var/cache/dev/container.php'));
        self::assertFileExists($this->projectDir.'/var/fixtures/content/articles/article-01.md');
        self::assertFileDoesNotExist($this->projectDir.'/var/index.html');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function missingOrEmptyBuildDirectory(): iterable
    {
        yield 'missing' => [false];
        yield 'empty' => [true];
    }

    #[Test]
    #[DataProvider('missingOrEmptyBuildDirectory')]
    public function itCleansAMissingOrEmptyBuildDirectory(bool $createBuildDirectory): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/about.md', '# About');

        if ($createBuildDirectory) {
            mkdir($this->projectDir.'/build', 0777, true);
        }

        $result = $this->createBuilder()->build();

        self::assertSame(1, $result->generatedPages);
        self::assertFileExists($this->projectDir.'/build/about/index.html');
    }

    #[Test]
    public function itCleansAPreviousBuild(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/about.md', '# About');
        file_put_contents($this->projectDir.'/content/old.md', '# Old');
        $this->createBuilder()->build();
        file_put_contents($this->projectDir.'/build/search.json', '[]');
        unlink($this->projectDir.'/content/old.md');

        $this->createBuilder()->build();

        self::assertFileExists($this->projectDir.'/build/about/index.html');
        self::assertDirectoryDoesNotExist($this->projectDir.'/build/old');
        self::assertFileDoesNotExist($this->projectDir.'/build/search.json');
    }

    #[Test]
    public function itBuildsWithoutCleaningIntoADirectoryThatIsNotAPreviousBuild(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/about.md', '# About');
        mkdir($this->projectDir.'/docs', 0777, true);
        file_put_contents($this->projectDir.'/docs/guide.html', '<h1>Guide</h1>');

        $this->createBuilder(buildDirectory: 'docs')->build(clean: false);

        self::assertSame('<h1>Guide</h1>', file_get_contents($this->projectDir.'/docs/guide.html'));
        self::assertFileExists($this->projectDir.'/docs/about/index.html');
        self::assertFileExists($this->projectDir.'/docs/sitemap.xml');
        self::assertFileExists($this->projectDir.'/docs/404.html');
    }

    #[Test]
    public function itUsesTheConfiguredDirectories(): void
    {
        mkdir($this->projectDir.'/site/pages/articles', 0777, true);
        file_put_contents($this->projectDir.'/site/pages/articles/hello.md', '# Hello');
        mkdir($this->projectDir.'/static/images', 0777, true);
        file_put_contents($this->projectDir.'/static/images/logo.txt', 'logo');

        $builder = $this->createBuilder(contentDirectory: 'site/pages', buildDirectory: 'public_html', assetsDirectory: 'static', staticDirectory: 'root-files');
        $result = $builder->build();

        self::assertSame(1, $result->generatedPages);
        self::assertFileExists($this->projectDir.'/public_html/index.html');
        self::assertFileExists($this->projectDir.'/public_html/hello/index.html');
        self::assertFileExists($this->projectDir.'/public_html/assets/images/logo.txt');
        self::assertFileExists($this->projectDir.'/public_html/assets/staccato/staccato.css');
        self::assertDirectoryDoesNotExist($this->projectDir.'/build');
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
        self::assertStringNotContainsString('rss.xml', (string) file_get_contents($this->projectDir.'/build/sitemap.xml'));
        self::assertStringContainsString('<loc>https://example.test/page/4/</loc>', (string) file_get_contents($this->projectDir.'/build/sitemap.xml'));
        self::assertSame(10, substr_count((string) file_get_contents($this->projectDir.'/build/index.html'), 'Read more -&gt;'));
        self::assertSame(10, substr_count((string) file_get_contents($this->projectDir.'/build/page/2/index.html'), 'Read more -&gt;'));
        self::assertSame(10, substr_count((string) file_get_contents($this->projectDir.'/build/page/3/index.html'), 'Read more -&gt;'));
        self::assertSame(5, substr_count((string) file_get_contents($this->projectDir.'/build/page/4/index.html'), 'Read more -&gt;'));
        self::assertStringContainsString('<link rel="canonical" href="/">', (string) file_get_contents($this->projectDir.'/build/index.html'));
        self::assertStringContainsString('<link rel="canonical" href="/page/3/">', (string) file_get_contents($this->projectDir.'/build/page/3/index.html'));
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
    private function createBuilder(bool $includeDefaultStyles = true, ?string $highlighterCss = null, array $stylesheets = [], string $contentDirectory = 'content', string $buildDirectory = 'build', string $assetsDirectory = 'assets', string $staticDirectory = 'static', ?EventDispatcher $eventDispatcher = null): SiteBuilder
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
            contentDirectory: $contentDirectory,
            buildDirectory: $buildDirectory,
            assetsDirectory: $assetsDirectory,
            staticDirectory: $staticDirectory,
        );
        $twig = new Environment(new ArrayLoader([
            '@Staccato/page.html.twig' => '<html><body><h1>{{ page.title }}</h1>{{ page.html|raw }}</body></html>',
            '@Staccato/home.html.twig' => '<html><body>{% for article in home.articles %}{% if article.meta.author is defined %}<span class="author">{{ article.meta.author }}</span>{% endif %}<a href="{{ article.urlPath }}">Read more -&gt;</a>{% endfor %}{% if home.totalPages > 1 %}page {{ home.currentPage }} of {{ home.totalPages }}{% endif %}<link rel="canonical" href="{{ home.urlPath }}"></body></html>',
            '@Staccato/rss.xml.twig' => '<rss version="2.0">{% for article in articles %}<item><title>{{ article.title }}</title></item>{% endfor %}</rss>',
            '@Staccato/sitemap.xml.twig' => '<urlset>{% for path in urls %}<url><loc>{{ baseUrl }}{{ path }}</loc></url>{% endfor %}</urlset>',
            '@Staccato/404.html.twig' => '<html><body>404</body></html>',
            '@Staccato/redirect.html.twig' => '<meta http-equiv="refresh" content="0; url={{ page.urlPath }}">',
        ]));
        $stylesheetResolver = new StylesheetResolver($this->projectDir, $siteConfig);
        $renderer = new PageRenderer($twig, $siteConfig, $stylesheetResolver);
        $fixtureGenerator = new FixtureContentGenerator($this->projectDir, $filesystem);

        return new SiteBuilder($this->projectDir, $this->defaultAssetsDir, $filesystem, $siteConfig, $loader, $fixtureGenerator, $resolver, $renderer, $eventDispatcher ?? new EventDispatcher(), $stylesheetResolver);
    }

    /**
     * @return array<string, string>
     */
    private static function articles(int $count): array
    {
        $articles = [];

        for ($index = 1; $index <= $count; ++$index) {
            $articles[sprintf('articles/article-%02d.md', $index)] = sprintf("---\ndate: 2026-03-%02d\n---\n# Article %02d", $index, $index);
        }

        return $articles;
    }

    /**
     * Seeds the build directory with what an earlier build leaves there, so a clean build may remove it.
     */
    private function writePreviousBuild(): void
    {
        mkdir($this->projectDir.'/build', 0777, true);
        file_put_contents($this->projectDir.'/build/previous.html', 'previous build');
        file_put_contents($this->projectDir.'/build/sitemap.xml', '<urlset></urlset>');
        file_put_contents($this->projectDir.'/build/404.html', '<html><body>404</body></html>');
    }

    /**
     * @return array<string, string> path relative to the directory => file contents
     */
    private function directoryContents(string $directory): array
    {
        $directoryPath = $this->projectDir.'/'.$directory;
        $contents = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directoryPath, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);
            $contents[substr($file->getPathname(), strlen($directoryPath) + 1)] = (string) file_get_contents($file->getPathname());
        }

        ksort($contents);

        return $contents;
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
