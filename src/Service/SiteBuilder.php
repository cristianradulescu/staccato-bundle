<?php

declare(strict_types=1);

namespace Staccato\Service;

use Staccato\Event\SiteBuiltEvent;
use Staccato\Model\Page;
use Staccato\Model\PageListItem;
use Staccato\Model\PaginatedHomeContext;
use Staccato\Model\SiteBuildResult;
use Staccato\Model\SiteConfig;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class SiteBuilder implements SiteBuilderInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%staccato.default_assets_dir%')]
        private string $defaultAssetsDir,
        private Filesystem $filesystem,
        private SiteConfig $siteConfig,
        private ContentLoader $contentLoader,
        private FixtureContentGenerator $fixtureContentGenerator,
        private OutputPathResolver $outputPathResolver,
        private PageRenderer $pageRenderer,
        private EventDispatcherInterface $eventDispatcher,
        private StylesheetResolver $stylesheetResolver,
    ) {
    }

    public function build(
        bool $clean = true,
        bool $includeDrafts = false,
        bool $useFixtures = false,
    ): SiteBuildResult {
        $startedAt = microtime(true);
        $siteConfig = $this->siteConfig;
        // SiteConfig only holds normalized directories relative to the project directory, with a build directory
        // apart from the others (see SiteConfig::normalizeDirectory() and overlappingDirectory()), so they are joined
        // to it as they are.
        $buildDirectory = $siteConfig->buildDirectory;
        $buildDirectoryPath = $this->projectDir.'/'.$buildDirectory;
        $assetsDirectoryPath = $this->projectDir.'/'.$siteConfig->assetsDirectory;
        $staticDirectoryPath = $this->projectDir.'/'.$siteConfig->staticDirectory;

        // Configuration and content are loaded and validated before cleaning, so a build that
        // fails on them leaves the previous output in place instead of an emptied build directory.
        $highlighterCssPath = $this->resolveHighlighterCssPath();
        $stylesheetPaths = $this->stylesheetResolver->resolve();

        $sourceContentDirectory = $useFixtures
            ? $this->fixtureContentGenerator->generate()
            : $siteConfig->contentDirectory;

        $allPages = $this->contentLoader->loadPages($sourceContentDirectory);
        $detailPages = [];
        $skippedDraftPages = 0;

        foreach ($allPages as $page) {
            if (!$includeDrafts && $page->draft) {
                ++$skippedDraftPages;

                continue;
            }

            $detailPages[] = $page;
        }

        $detailOutputPaths = $this->assertNoDuplicateOutputPaths($detailPages, $buildDirectory);
        $homeOutputPaths = $this->homeOutputPaths($detailPages, $buildDirectory, $siteConfig->articlesPerPage);
        $this->assertNoHomePaginationConflicts($homeOutputPaths, $detailOutputPaths);
        $aliasOutputPaths = $this->resolveAliasOutputPaths($detailPages, $buildDirectory, $detailOutputPaths, $homeOutputPaths);

        $staticFiles = $this->findStaticFiles($staticDirectoryPath);
        $this->assertNoStaticFileConflicts($staticFiles, [...array_keys($detailOutputPaths), ...$homeOutputPaths, ...array_keys($aliasOutputPaths)], $buildDirectory);

        if ($clean) {
            $this->assertBuildDirectoryCanBeCleaned($buildDirectoryPath, $buildDirectory);
            $this->filesystem->remove($buildDirectoryPath);
        }

        $this->filesystem->mkdir($buildDirectoryPath);

        foreach ($detailPages as $page) {
            $html = $this->pageRenderer->renderPage($page);
            $relativeOutputPath = $this->outputPathResolver->resolveOutputPath($page->urlPath, $buildDirectory);
            $absoluteOutputPath = $this->projectDir.'/'.$relativeOutputPath;
            $this->filesystem->mkdir(dirname($absoluteOutputPath));
            $this->filesystem->dumpFile($absoluteOutputPath, $html);
        }

        $homeUrls = $this->buildHomePages($detailPages, $buildDirectory, $siteConfig->articlesPerPage);
        $articlePages = $this->sortedArticlePages($detailPages);

        $rssXml = $this->pageRenderer->renderRss($articlePages);
        $this->filesystem->dumpFile($buildDirectoryPath.'/rss.xml', $rssXml);

        $this->filesystem->dumpFile(
            $buildDirectoryPath.'/404.html',
            $this->pageRenderer->renderNotFound(),
        );

        $sitemapUrls = [
            ...$homeUrls,
            ...array_map(static fn (Page $page): string => $page->urlPath, $detailPages),
        ];

        $sitemapXml = $this->pageRenderer->renderSitemap($sitemapUrls);
        $this->filesystem->dumpFile($buildDirectoryPath.'/sitemap.xml', $sitemapXml);

        $this->writeRedirects($aliasOutputPaths, [...array_keys($detailOutputPaths), ...$homeOutputPaths], $buildDirectory);

        $assetsCopied = $this->copyAssets($assetsDirectoryPath, $buildDirectoryPath);

        if ($siteConfig->includeDefaultStyles || null !== $highlighterCssPath || [] !== $stylesheetPaths) {
            $this->copyStyles($buildDirectoryPath, $siteConfig->includeDefaultStyles, $highlighterCssPath, $stylesheetPaths);
            $assetsCopied = true;
        }

        $this->copyStaticFiles($staticFiles, $staticDirectoryPath, $buildDirectoryPath);

        $this->eventDispatcher->dispatch(new SiteBuiltEvent($buildDirectoryPath, $detailPages));

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return new SiteBuildResult(
            generatedPages: count($detailPages),
            skippedDraftPages: $skippedDraftPages,
            assetsCopied: $assetsCopied,
            durationMs: $durationMs,
            staticFilesCopied: count($staticFiles),
            redirectsGenerated: count($aliasOutputPaths),
        );
    }

    /**
     * @param list<Page> $pages
     *
     * @return array<string, string>
     */
    private function assertNoDuplicateOutputPaths(array $pages, string $buildDirectory): array
    {
        $seen = [];

        foreach ($pages as $page) {
            $outputPath = $this->outputPathResolver->resolveOutputPath($page->urlPath, $buildDirectory);

            if (isset($seen[$outputPath])) {
                throw new \RuntimeException(sprintf('Duplicate output path "%s" generated by "%s" and "%s".', $outputPath, $seen[$outputPath], $page->sourcePath));
            }

            $seen[$outputPath] = $page->sourcePath;
        }

        return $seen;
    }

    private function copyAssets(string $assetsDirectoryPath, string $buildDirectoryPath): bool
    {
        if (!is_dir($assetsDirectoryPath)) {
            return false;
        }

        // Always published under /assets/, whatever the source directory is called, so template links stay stable.
        $this->filesystem->mirror($assetsDirectoryPath, $buildDirectoryPath.'/assets');

        return true;
    }

    /**
     * @param array<string, string> $stylesheetPaths target file name => absolute source path
     */
    private function copyStyles(string $buildDirectoryPath, bool $includeDefaultStyles, ?string $highlighterCssPath, array $stylesheetPaths): void
    {
        $targetAssetsPath = $buildDirectoryPath.'/assets/staccato';
        $this->filesystem->mkdir($targetAssetsPath);

        // Copy default staccato.css
        $sourceStylesheetPath = $this->defaultAssetsDir.'/css/staccato.css';
        if ($includeDefaultStyles && is_file($sourceStylesheetPath)) {
            $this->filesystem->copy($sourceStylesheetPath, $targetAssetsPath.'/staccato.css', true);
        }

        // Copy configured stylesheets
        foreach ($stylesheetPaths as $fileName => $sourcePath) {
            $this->filesystem->copy($sourcePath, $targetAssetsPath.'/'.$fileName, true);
        }

        // Copy highlighter css
        if (null !== $highlighterCssPath) {
            $this->filesystem->copy($highlighterCssPath, $targetAssetsPath.'/highlighter.css', true);
        }
    }

    private function resolveHighlighterCssPath(): ?string
    {
        if (null === $this->siteConfig->highlighterCss) {
            return null;
        }

        $path = Path::makeAbsolute($this->siteConfig->highlighterCss, $this->projectDir);

        if (!is_file($path)) {
            throw new \InvalidArgumentException(sprintf('Configured highlighter css file was not found at "%s".', $path));
        }

        return $path;
    }

    /**
     * @param list<Page> $pages
     *
     * @return list<string>
     */
    private function buildHomePages(array $pages, string $buildDirectory, int $articlesPerPage): array
    {
        $articles = $this->sortedArticlePages($pages);

        $items = array_map(
            static fn (Page $page): PageListItem => new PageListItem(
                title: $page->title,
                publishedAt: $page->publishedAt,
                excerpt: $page->excerpt,
                urlPath: $page->urlPath,
                meta: $page->meta,
            ),
            $articles,
        );

        $chunks = array_chunk($items, max(1, $articlesPerPage));

        if ([] === $chunks) {
            $chunks = [[]];
        }

        $totalPages = count($chunks);
        $homeUrls = [];

        foreach ($chunks as $index => $chunk) {
            $pageNumber = $index + 1;
            $urlPath = $this->homeUrlPath($pageNumber);
            $homeUrls[] = $urlPath;

            $context = new PaginatedHomeContext(
                articles: $chunk,
                currentPage: $pageNumber,
                totalPages: $totalPages,
                previousPageUrl: $pageNumber > 1 ? $this->homeUrlPath($pageNumber - 1) : null,
                nextPageUrl: $pageNumber < $totalPages ? $this->homeUrlPath($pageNumber + 1) : null,
                urlPath: $urlPath,
            );

            $html = $this->pageRenderer->renderHome($context);
            $relativeOutputPath = $this->outputPathResolver->resolveOutputPath($urlPath, $buildDirectory);
            $absoluteOutputPath = $this->projectDir.'/'.$relativeOutputPath;
            $this->filesystem->mkdir(dirname($absoluteOutputPath));
            $this->filesystem->dumpFile($absoluteOutputPath, $html);
        }

        return $homeUrls;
    }

    /**
     * @param list<Page> $pages
     *
     * @return list<string>
     */
    private function homeOutputPaths(array $pages, string $buildDirectory, int $articlesPerPage): array
    {
        $totalPages = max(1, (int) ceil(count($this->sortedArticlePages($pages)) / max(1, $articlesPerPage)));
        $outputPaths = [];

        for ($pageNumber = 1; $pageNumber <= $totalPages; ++$pageNumber) {
            $outputPaths[] = $this->outputPathResolver->resolveOutputPath($this->homeUrlPath($pageNumber), $buildDirectory);
        }

        return $outputPaths;
    }

    /**
     * @param list<string>          $homeOutputPaths
     * @param array<string, string> $existingOutputPaths
     */
    private function assertNoHomePaginationConflicts(array $homeOutputPaths, array $existingOutputPaths): void
    {
        foreach ($homeOutputPaths as $relativeOutputPath) {
            if (isset($existingOutputPaths[$relativeOutputPath])) {
                throw new \RuntimeException(sprintf('Home pagination path "%s" conflicts with content file "%s".', $relativeOutputPath, $existingOutputPaths[$relativeOutputPath]));
            }
        }
    }

    /**
     * @param list<Page>            $pages
     * @param array<string, string> $detailOutputPaths
     * @param list<string>          $homeOutputPaths
     *
     * @return array<string, Page> alias output path => page it redirects to
     */
    private function resolveAliasOutputPaths(array $pages, string $buildDirectory, array $detailOutputPaths, array $homeOutputPaths): array
    {
        $buildPrefix = $buildDirectory.'/';
        $reservedPaths = [];

        foreach ($detailOutputPaths as $outputPath => $sourcePath) {
            $reservedPaths[$outputPath] = sprintf('the page generated from "%s"', $sourcePath);
        }

        foreach ($homeOutputPaths as $outputPath) {
            $reservedPaths[$outputPath] = 'the homepage listing';
        }

        foreach (['rss.xml', 'sitemap.xml', '404.html'] as $fileName) {
            $reservedPaths[$buildPrefix.$fileName] = sprintf('the generated "%s"', $fileName);
        }

        $reservedPaths[$buildPrefix.'assets'] = 'the published assets';
        $aliasOutputPaths = [];

        foreach ($pages as $page) {
            foreach ($page->aliases as $alias) {
                $outputPath = $this->outputPathResolver->resolveAliasOutputPath($alias, $buildDirectory);

                foreach ($reservedPaths as $reservedPath => $conflict) {
                    if (self::outputPathsCollide($outputPath, $reservedPath)) {
                        throw new \RuntimeException(sprintf('Alias "%s" of "%s" conflicts with %s.', $alias, $page->sourcePath, $conflict));
                    }
                }

                $reservedPaths[$outputPath] = sprintf('an alias of "%s"', $page->sourcePath);
                $aliasOutputPaths[$outputPath] = $page;
            }
        }

        return $aliasOutputPaths;
    }

    /**
     * Two outputs cannot both be written when they are the same path, or when one is a file the other needs as a
     * directory ("rss.xml" and "rss.xml/index.html"). Directory-style outputs nest freely ("a/index.html" and
     * "a/b/index.html"), since neither is inside the other.
     *
     * The nesting check ignores case: on a case-insensitive filesystem (the macOS and Windows default),
     * "RSS.xml/index.html" needs the "rss.xml" file as a directory, and writing it would fail halfway through the
     * build, after the clean step. The equality check does not: an alias that differs from its page's URL only by
     * case ("/About/" for "/about/") is the usual case after a migration, works on a case-sensitive filesystem, and
     * is detected by writeRedirects() on the others. strtolower() is enough, because aliases and generated paths are
     * ASCII-only past the build directory, which both sides share, so only ASCII letters can make a path match one.
     */
    private static function outputPathsCollide(string $path, string $otherPath): bool
    {
        if ($path === $otherPath) {
            return true;
        }

        $path = strtolower($path);
        $otherPath = strtolower($otherPath);

        return str_starts_with($path, $otherPath.'/') || str_starts_with($otherPath, $path.'/');
    }

    /**
     * @param array<string, Page> $aliasOutputPaths
     * @param list<string>        $generatedOutputPaths
     */
    private function writeRedirects(array $aliasOutputPaths, array $generatedOutputPaths, string $buildDirectory): void
    {
        $buildPrefix = $buildDirectory.'/';
        $generatedPathsByLowercase = [];

        foreach ([...$generatedOutputPaths, $buildPrefix.'rss.xml', $buildPrefix.'sitemap.xml', $buildPrefix.'404.html'] as $outputPath) {
            $generatedPathsByLowercase[strtolower($outputPath)] = $outputPath;
        }

        foreach ($aliasOutputPaths as $outputPath => $page) {
            $absoluteOutputPath = $this->projectDir.'/'.$outputPath;

            // An alias that differs from a generated file only by case (the usual case after a migration) is
            // the same file on a case-insensitive filesystem, and writing it would replace the page itself.
            $sameCasePath = $generatedPathsByLowercase[strtolower($outputPath)] ?? null;

            if (null !== $sameCasePath && is_file($absoluteOutputPath) && fileinode($absoluteOutputPath) === fileinode($this->projectDir.'/'.$sameCasePath)) {
                throw new \RuntimeException(sprintf('Alias "%s" of "%s" differs from "%s" only by case, and this filesystem does not tell them apart. Build the site on a case-sensitive filesystem (e.g. Linux or CI), or remove the alias.', $outputPath, $page->sourcePath, $sameCasePath));
            }

            $this->filesystem->dumpFile($absoluteOutputPath, $this->pageRenderer->renderRedirect($page));
        }
    }

    /**
     * @return list<string> file paths relative to the static directory
     */
    private function findStaticFiles(string $staticDirectoryPath): array
    {
        if (!is_dir($staticDirectoryPath)) {
            return [];
        }

        $finder = (new Finder())->files()->in($staticDirectoryPath)->ignoreDotFiles(false)->ignoreVCS(true)->sortByName();
        $files = [];

        foreach ($finder as $file) {
            $files[] = str_replace('\\', '/', $file->getRelativePathname());
        }

        return $files;
    }

    /**
     * Static files are published at the build root, so they must not replace or nest inside a generated file. Unlike
     * an alias, a static file may not differ from a generated file only by case either: static files are not a
     * migration feature, and on a case-insensitive filesystem "RSS.xml", copied last, would replace the generated
     * "rss.xml" without an error.
     *
     * @param list<string> $staticFiles
     * @param list<string> $generatedOutputPaths output paths prefixed with the build directory
     */
    private function assertNoStaticFileConflicts(array $staticFiles, array $generatedOutputPaths, string $buildDirectory): void
    {
        if ([] === $staticFiles) {
            return;
        }

        $buildPrefix = $buildDirectory.'/';
        $reservedPaths = ['rss.xml', 'sitemap.xml', '404.html', 'assets'];

        foreach ($generatedOutputPaths as $outputPath) {
            $reservedPaths[] = substr($outputPath, strlen($buildPrefix));
        }

        foreach ($staticFiles as $staticFile) {
            foreach ($reservedPaths as $reservedPath) {
                if (self::outputPathsCollide($staticFile, $reservedPath) || strtolower($staticFile) === strtolower($reservedPath)) {
                    throw new \RuntimeException(sprintf('Static file "%s" conflicts with the generated "%s" in the build directory.', $staticFile, $reservedPath));
                }
            }
        }
    }

    /**
     * A clean build removes the whole build directory, so it only does so when that directory is missing, empty or a
     * previous build: a build_dir pointing at src/, vendor/, var/ or a hand-written docs/ must not be deleted. Every
     * build writes sitemap.xml and 404.html at its root, so their presence marks one; checking the content instead of
     * a list of known directory names also covers directories no list would name.
     */
    private function assertBuildDirectoryCanBeCleaned(string $buildDirectoryPath, string $buildDirectory): void
    {
        if (!file_exists($buildDirectoryPath) && !is_link($buildDirectoryPath)) {
            return;
        }

        if (!is_dir($buildDirectoryPath)) {
            throw new \RuntimeException(sprintf('The build directory "%s" exists but is not a directory, so a clean build would delete it. Set "staccato.build_dir" to a new directory, or remove "%s" yourself.', $buildDirectory, $buildDirectory));
        }

        if (!(new \FilesystemIterator($buildDirectoryPath))->valid()) {
            return;
        }

        $missingFiles = array_filter(['sitemap.xml', '404.html'], static fn (string $fileName): bool => !is_file($buildDirectoryPath.'/'.$fileName));

        if ([] !== $missingFiles) {
            throw new \RuntimeException(sprintf('The build directory "%s" is not empty and does not look like a previous Staccato build (it has no %s), so a clean build would delete it. Set "staccato.build_dir" to a new directory, or empty or remove "%s" yourself.', $buildDirectory, implode(' or ', $missingFiles), $buildDirectory));
        }
    }

    /**
     * @param list<string> $staticFiles
     */
    private function copyStaticFiles(array $staticFiles, string $staticDirectoryPath, string $buildDirectoryPath): void
    {
        foreach ($staticFiles as $staticFile) {
            $this->filesystem->copy($staticDirectoryPath.'/'.$staticFile, $buildDirectoryPath.'/'.$staticFile, true);
        }
    }

    private function homeUrlPath(int $pageNumber): string
    {
        return 1 === $pageNumber ? '/' : '/page/'.$pageNumber.'/';
    }

    /**
     * @param list<Page> $pages
     *
     * @return list<Page>
     */
    private function sortedArticlePages(array $pages): array
    {
        $articles = array_values(array_filter(
            $pages,
            static fn (Page $page): bool => str_starts_with($page->sourcePath, 'articles/'),
        ));

        usort(
            $articles,
            static function (Page $a, Page $b): int {
                $aTs = $a->publishedAt?->getTimestamp() ?? 0;
                $bTs = $b->publishedAt?->getTimestamp() ?? 0;

                return $bTs <=> $aTs;
            },
        );

        return $articles;
    }
}
