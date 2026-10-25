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
    ) {
    }

    public function build(
        bool $clean = true,
        bool $includeDrafts = false,
        bool $useFixtures = false,
    ): SiteBuildResult {
        $startedAt = microtime(true);
        $siteConfig = $this->siteConfig;
        $contentDirectory = $siteConfig->contentDirectory;
        $buildDirectory = $siteConfig->buildDirectory;
        $assetsDirectory = $siteConfig->assetsDirectory;
        $buildDirectoryPath = $this->projectDir.'/'.$this->normalizeRelativeDirectory($buildDirectory);

        // Configuration and content are loaded and validated before cleaning, so a build that
        // fails on them leaves the previous output in place instead of an emptied build directory.
        $highlighterCssPath = $this->resolveHighlighterCssPath();
        $includeHighlighterStyles = null !== $highlighterCssPath;
        $stylesheetPaths = $this->resolveStylesheetPaths();
        $stylesheets = array_keys($stylesheetPaths);

        $sourceContentDirectory = $useFixtures
            ? $this->fixtureContentGenerator->generate()
            : $this->normalizeRelativeDirectory($contentDirectory);

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

        $staticFiles = $this->findStaticFiles($siteConfig->staticDirectory);
        $this->assertNoStaticFileConflicts($staticFiles, [...array_keys($detailOutputPaths), ...$homeOutputPaths, ...array_keys($aliasOutputPaths)], $buildDirectory);

        if ($clean) {
            $this->filesystem->remove($buildDirectoryPath);
        }

        $this->filesystem->mkdir($buildDirectoryPath);

        foreach ($detailPages as $page) {
            $html = $this->pageRenderer->renderPage($page, $siteConfig->title, $siteConfig->footerText, $siteConfig->includeDefaultStyles, $includeHighlighterStyles, $stylesheets);
            $relativeOutputPath = $this->outputPathResolver->resolveOutputPath($page->urlPath, $buildDirectory);
            $absoluteOutputPath = $this->projectDir.'/'.$relativeOutputPath;
            $this->filesystem->mkdir(dirname($absoluteOutputPath));
            $this->filesystem->dumpFile($absoluteOutputPath, $html);
        }

        $homeUrls = $this->buildHomePages($detailPages, $buildDirectory, $siteConfig->title, $siteConfig->footerText, $siteConfig->articlesPerPage, $siteConfig->includeDefaultStyles, $includeHighlighterStyles, $stylesheets);
        $articlePages = $this->sortedArticlePages($detailPages);

        $rssXml = $this->pageRenderer->renderRss(
            articles: $articlePages,
            siteTitle: $siteConfig->title,
            siteDescription: $siteConfig->description,
            baseUrl: $siteConfig->baseUrl,
        );
        $this->filesystem->dumpFile($this->projectDir.'/'.$this->normalizeRelativeDirectory($buildDirectory).'/rss.xml', $rssXml);

        $this->filesystem->dumpFile(
            $this->projectDir.'/'.$this->normalizeRelativeDirectory($buildDirectory).'/404.html',
            $this->pageRenderer->renderNotFound($siteConfig->title, $siteConfig->footerText, $siteConfig->includeDefaultStyles, $includeHighlighterStyles, $stylesheets),
        );

        $sitemapUrls = array_values(array_unique(array_merge(
            $homeUrls,
            array_map(static fn (Page $page): string => $page->urlPath, $detailPages),
            ['/rss.xml'],
        )));

        $sitemapXml = $this->pageRenderer->renderSitemap(
            urls: $sitemapUrls,
            baseUrl: $siteConfig->baseUrl,
        );
        $this->filesystem->dumpFile($this->projectDir.'/'.$this->normalizeRelativeDirectory($buildDirectory).'/sitemap.xml', $sitemapXml);

        $this->writeRedirects($aliasOutputPaths, [...array_keys($detailOutputPaths), ...$homeOutputPaths], $buildDirectory);

        $assetsCopied = $this->copyAssets($assetsDirectory, $buildDirectory);

        if ($siteConfig->includeDefaultStyles || null !== $highlighterCssPath || [] !== $stylesheetPaths) {
            $this->copyStyles($buildDirectory, $siteConfig->includeDefaultStyles, $highlighterCssPath, $stylesheetPaths);
            $assetsCopied = true;
        }

        $this->copyStaticFiles($staticFiles, $siteConfig->staticDirectory, $buildDirectory);

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

    private function copyAssets(string $assetsDirectory, string $buildDirectory): bool
    {
        $normalizedAssetsDirectory = $this->normalizeRelativeDirectory($assetsDirectory);
        $sourceAssetsPath = $this->projectDir.'/'.$normalizedAssetsDirectory;
        $targetBuildDirectory = $this->projectDir.'/'.$this->normalizeRelativeDirectory($buildDirectory);

        if (!is_dir($sourceAssetsPath)) {
            return false;
        }

        // Always published under /assets/, whatever the source directory is called, so template links stay stable.
        $targetAssetsPath = $targetBuildDirectory.'/assets';
        $this->filesystem->mirror($sourceAssetsPath, $targetAssetsPath);

        return true;
    }

    /**
     * @param array<string, string> $stylesheetPaths target file name => absolute source path
     */
    private function copyStyles(string $buildDirectory, bool $includeDefaultStyles, ?string $highlighterCssPath, array $stylesheetPaths): void
    {
        $targetBuildDirectory = $this->projectDir.'/'.$this->normalizeRelativeDirectory($buildDirectory);
        $targetAssetsPath = $targetBuildDirectory.'/assets/staccato';
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
     * @return array<string, string> target file name => absolute source path
     */
    private function resolveStylesheetPaths(): array
    {
        $paths = [];

        foreach ($this->siteConfig->stylesheets as $stylesheet) {
            $path = Path::makeAbsolute($stylesheet, $this->projectDir);

            if (!is_file($path)) {
                throw new \InvalidArgumentException(sprintf('Configured stylesheet file was not found at "%s".', $path));
            }

            // Every stylesheet is copied flat into assets/staccato/, so file names must not collide.
            $fileName = basename($path);

            if (in_array($fileName, ['staccato.css', 'highlighter.css'], true) || isset($paths[$fileName])) {
                throw new \InvalidArgumentException(sprintf('Configured stylesheet "%s" conflicts with another stylesheet named "%s".', $stylesheet, $fileName));
            }

            $paths[$fileName] = $path;
        }

        return $paths;
    }

    /**
     * @param list<Page>   $pages
     * @param list<string> $stylesheets
     *
     * @return list<string>
     */
    private function buildHomePages(array $pages, string $buildDirectory, string $siteTitle, string $footerText, int $articlesPerPage, bool $includeDefaultStyles, bool $includeHighlighterStyles, array $stylesheets): array
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

            $html = $this->pageRenderer->renderHome($context, $siteTitle, $footerText, $includeDefaultStyles, $includeHighlighterStyles, $stylesheets);
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
        $buildPrefix = $this->normalizeRelativeDirectory($buildDirectory).'/';
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

        $aliasOutputPaths = [];

        foreach ($pages as $page) {
            foreach ($page->aliases as $alias) {
                $outputPath = $this->outputPathResolver->resolveAliasOutputPath($alias, $buildDirectory);

                $conflict = match (true) {
                    isset($reservedPaths[$outputPath]) => $reservedPaths[$outputPath],
                    isset($aliasOutputPaths[$outputPath]) => sprintf('an alias of "%s"', $aliasOutputPaths[$outputPath]->sourcePath),
                    str_starts_with($outputPath, $buildPrefix.'assets/') => 'the published assets',
                    default => null,
                };

                if (null !== $conflict) {
                    throw new \RuntimeException(sprintf('Alias "%s" of "%s" conflicts with %s.', $alias, $page->sourcePath, $conflict));
                }

                $aliasOutputPaths[$outputPath] = $page;
            }
        }

        return $aliasOutputPaths;
    }

    /**
     * @param array<string, Page> $aliasOutputPaths
     * @param list<string>        $generatedOutputPaths
     */
    private function writeRedirects(array $aliasOutputPaths, array $generatedOutputPaths, string $buildDirectory): void
    {
        $buildPrefix = $this->normalizeRelativeDirectory($buildDirectory).'/';
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
    private function findStaticFiles(string $staticDirectory): array
    {
        $sourceStaticPath = $this->projectDir.'/'.$this->normalizeRelativeDirectory($staticDirectory);

        if (!is_dir($sourceStaticPath)) {
            return [];
        }

        $finder = (new Finder())->files()->in($sourceStaticPath)->ignoreDotFiles(false)->ignoreVCS(true)->sortByName();
        $files = [];

        foreach ($finder as $file) {
            $files[] = str_replace('\\', '/', $file->getRelativePathname());
        }

        return $files;
    }

    /**
     * Static files are published at the build root, so they must not replace or nest inside a generated file.
     *
     * @param list<string> $staticFiles
     * @param list<string> $generatedOutputPaths output paths prefixed with the build directory
     */
    private function assertNoStaticFileConflicts(array $staticFiles, array $generatedOutputPaths, string $buildDirectory): void
    {
        if ([] === $staticFiles) {
            return;
        }

        $buildPrefix = $this->normalizeRelativeDirectory($buildDirectory).'/';
        $reservedPaths = ['rss.xml', 'sitemap.xml', '404.html', 'assets'];

        foreach ($generatedOutputPaths as $outputPath) {
            $reservedPaths[] = substr($outputPath, strlen($buildPrefix));
        }

        foreach ($staticFiles as $staticFile) {
            foreach ($reservedPaths as $reservedPath) {
                if ($staticFile === $reservedPath || str_starts_with($staticFile, $reservedPath.'/') || str_starts_with($reservedPath, $staticFile.'/')) {
                    throw new \RuntimeException(sprintf('Static file "%s" conflicts with the generated "%s" in the build directory.', $staticFile, $reservedPath));
                }
            }
        }
    }

    /**
     * @param list<string> $staticFiles
     */
    private function copyStaticFiles(array $staticFiles, string $staticDirectory, string $buildDirectory): void
    {
        $sourceStaticPath = $this->projectDir.'/'.$this->normalizeRelativeDirectory($staticDirectory);
        $targetBuildDirectory = $this->projectDir.'/'.$this->normalizeRelativeDirectory($buildDirectory);

        foreach ($staticFiles as $staticFile) {
            $this->filesystem->copy($sourceStaticPath.'/'.$staticFile, $targetBuildDirectory.'/'.$staticFile, true);
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

    private function normalizeRelativeDirectory(string $directory): string
    {
        $normalized = trim(str_replace('\\', '/', $directory), '/');

        if ('' === $normalized) {
            throw new \RuntimeException('Directory cannot be empty.');
        }

        foreach (explode('/', $normalized) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                throw new \RuntimeException(sprintf('Invalid directory "%s".', $directory));
            }
        }

        return $normalized;
    }
}
