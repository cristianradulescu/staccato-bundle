<?php

declare(strict_types=1);

namespace Staccato\Service;

use Staccato\Model\Page;
use Staccato\Model\PageListItem;
use Staccato\Model\PaginatedHomeContext;
use Staccato\Model\SiteBuildResult;
use Staccato\Model\SiteConfig;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

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
    ) {
    }

    public function build(
        string $contentDirectory = 'content',
        string $buildDirectory = 'build',
        string $assetsDirectory = 'assets',
        bool $clean = true,
        bool $includeDrafts = false,
        bool $useFixtures = false,
    ): SiteBuildResult {
        $startedAt = microtime(true);
        $buildDirectoryPath = $this->projectDir.'/'.$this->normalizeRelativeDirectory($buildDirectory);
        $siteConfig = $this->siteConfig;

        // Resolved before cleaning so a misconfigured path doesn't leave an emptied build directory behind.
        $highlighterCssPath = $siteConfig->includeDefaultStyles ? $this->resolveHighlighterCssPath() : null;
        $includeHighlighterStyles = null !== $highlighterCssPath;

        if ($clean) {
            $this->filesystem->remove($buildDirectoryPath);
        }

        $this->filesystem->mkdir($buildDirectoryPath);

        $sourceContentDirectory = $useFixtures
            ? $this->fixtureContentGenerator->generate()
            : $this->normalizeRelativeDirectory($contentDirectory);

        $allPages = $this->contentLoader->loadPages($sourceContentDirectory);
        $pagesToBuild = [];
        $skippedDraftPages = 0;

        foreach ($allPages as $page) {
            if (!$includeDrafts && $page->draft) {
                ++$skippedDraftPages;

                continue;
            }

            $pagesToBuild[] = $page;
        }

        $detailPages = array_values(array_filter(
            $pagesToBuild,
            static fn (Page $page): bool => '/' !== $page->urlPath,
        ));

        $detailOutputPaths = $this->assertNoDuplicateOutputPaths($detailPages, $buildDirectory);

        foreach ($detailPages as $page) {
            $html = $this->pageRenderer->renderPage($page, $siteConfig->title, $siteConfig->footerText, $siteConfig->includeDefaultStyles, $includeHighlighterStyles);
            $relativeOutputPath = $this->outputPathResolver->resolveOutputPath($page->urlPath, $buildDirectory);
            $absoluteOutputPath = $this->projectDir.'/'.$relativeOutputPath;
            $this->filesystem->mkdir(dirname($absoluteOutputPath));
            $this->filesystem->dumpFile($absoluteOutputPath, $html);
        }

        $homeUrls = $this->buildHomePages($detailPages, $buildDirectory, $siteConfig->title, $siteConfig->footerText, $siteConfig->articlesPerPage, $siteConfig->includeDefaultStyles, $includeHighlighterStyles, $detailOutputPaths);
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
            $this->pageRenderer->renderNotFound($siteConfig->title, $siteConfig->footerText, $siteConfig->includeDefaultStyles, $includeHighlighterStyles),
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

        $assetsCopied = $this->copyAssets($assetsDirectory, $buildDirectory);

        if ($siteConfig->includeDefaultStyles) {
            $this->copyDefaultStyles($buildDirectory, $highlighterCssPath);
            $assetsCopied = true;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return new SiteBuildResult(
            generatedPages: count($detailPages),
            skippedDraftPages: $skippedDraftPages,
            assetsCopied: $assetsCopied,
            durationMs: $durationMs,
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

        $targetAssetsPath = $targetBuildDirectory.'/'.$normalizedAssetsDirectory;
        $this->filesystem->mirror($sourceAssetsPath, $targetAssetsPath);

        return true;
    }

    private function copyDefaultStyles(string $buildDirectory, ?string $highlighterCssPath): void
    {
        $targetBuildDirectory = $this->projectDir.'/'.$this->normalizeRelativeDirectory($buildDirectory);
        $targetAssetsPath = $targetBuildDirectory.'/assets/staccato';
        $this->filesystem->mkdir($targetAssetsPath);

        // Copy default staccato.css
        $sourceStylesheetPath = $this->defaultAssetsDir.'/css/staccato.css';
        if (is_file($sourceStylesheetPath)) {
            $this->filesystem->copy($sourceStylesheetPath, $targetAssetsPath.'/staccato.css', true);
        }

        // Copy extra styles
        foreach ($this->siteConfig->extraDefaultStyles as $extraStyle) {
            $sourcePath = $this->defaultAssetsDir.'/'.$extraStyle;
            if (!is_file($sourcePath)) {
                throw new \InvalidArgumentException('Configured highlighter css file was not found at: '.$this->siteConfig->highlighterCss);
            }
            $this->filesystem->copy($sourcePath, $targetAssetsPath.'/'.basename($extraStyle), true);
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
     * @param list<Page>            $pages
     * @param array<string, string> $existingOutputPaths
     *
     * @return list<string>
     */
    private function buildHomePages(array $pages, string $buildDirectory, string $siteTitle, string $footerText, int $articlesPerPage, bool $includeDefaultStyles, bool $includeHighlighterStyles, array $existingOutputPaths): array
    {
        $articles = $this->sortedArticlePages($pages);

        $items = array_map(
            static fn (Page $page): PageListItem => new PageListItem(
                title: $page->title,
                publishedAt: $page->publishedAt,
                excerpt: $page->excerpt,
                urlPath: $page->urlPath,
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
            $urlPath = 1 === $pageNumber ? '/' : '/page/'.$pageNumber.'/';
            $homeUrls[] = $urlPath;

            $context = new PaginatedHomeContext(
                articles: $chunk,
                currentPage: $pageNumber,
                totalPages: $totalPages,
                previousPageUrl: $pageNumber > 1 ? (2 === $pageNumber ? '/' : '/page/'.($pageNumber - 1).'/') : null,
                nextPageUrl: $pageNumber < $totalPages ? '/page/'.($pageNumber + 1).'/' : null,
            );

            $html = $this->pageRenderer->renderHome($context, $siteTitle, $footerText, $includeDefaultStyles, $includeHighlighterStyles);
            $relativeOutputPath = $this->outputPathResolver->resolveOutputPath($urlPath, $buildDirectory);

            if (isset($existingOutputPaths[$relativeOutputPath])) {
                throw new \RuntimeException(sprintf('Home pagination path "%s" conflicts with content file "%s".', $relativeOutputPath, $existingOutputPaths[$relativeOutputPath]));
            }

            $absoluteOutputPath = $this->projectDir.'/'.$relativeOutputPath;
            $this->filesystem->mkdir(dirname($absoluteOutputPath));
            $this->filesystem->dumpFile($absoluteOutputPath, $html);
        }

        return $homeUrls;
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
