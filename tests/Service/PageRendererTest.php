<?php

declare(strict_types=1);

namespace Staccato\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Staccato\Model\Page;
use Staccato\Model\PaginatedHomeContext;
use Staccato\Model\SiteConfig;
use Staccato\Service\PageRenderer;
use Staccato\Service\StylesheetResolver;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

/**
 * The variables passed to each template are public API: applications override the bundle's templates and read them.
 */
final class PageRendererTest extends TestCase
{
    private const array TEMPLATES = [
        '@Staccato/page.html.twig',
        '@Staccato/home.html.twig',
        '@Staccato/404.html.twig',
        '@Staccato/rss.xml.twig',
        '@Staccato/sitemap.xml.twig',
        '@Staccato/redirect.html.twig',
        'custom.twig',
    ];

    private string $projectDir;

    private ?string $renderedTemplate = null;

    /**
     * @var array<string, mixed>
     */
    private array $context = [];

    protected function setUp(): void
    {
        $this->projectDir = rtrim(sys_get_temp_dir(), '/').'/staccato-page-renderer-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->projectDir.'/themes/custom.css', '.custom {}');
        $filesystem->dumpFile($this->projectDir.'/themes/print.css', '@media print {}');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    #[Test]
    public function itPassesThePageAndTheLayoutVariablesToThePageTemplate(): void
    {
        $page = $this->createPage();

        $this->createRenderer($this->createSiteConfig())->renderPage($page);

        self::assertSame('@Staccato/page.html.twig', $this->renderedTemplate);
        self::assertSame(['page' => $page] + $this->expectedLayoutVariables(), $this->context);
    }

    #[Test]
    public function itPassesTheHomeContextAndTheLayoutVariablesToTheHomeTemplate(): void
    {
        $home = new PaginatedHomeContext(articles: [], currentPage: 1, totalPages: 1, previousPageUrl: null, nextPageUrl: null, urlPath: '/');

        $this->createRenderer($this->createSiteConfig())->renderHome($home);

        self::assertSame('@Staccato/home.html.twig', $this->renderedTemplate);
        self::assertSame(['home' => $home] + $this->expectedLayoutVariables(), $this->context);
    }

    #[Test]
    public function itPassesTheLayoutVariablesToTheNotFoundTemplate(): void
    {
        $this->createRenderer($this->createSiteConfig())->renderNotFound();

        self::assertSame('@Staccato/404.html.twig', $this->renderedTemplate);
        self::assertSame($this->expectedLayoutVariables(), $this->context);
    }

    #[Test]
    public function itPassesTheLayoutVariablesOfTheDefaultStyling(): void
    {
        $siteConfig = new SiteConfig(
            title: 'My Site',
            baseUrl: 'https://example.test',
            description: 'Site description',
            articlesPerPage: 10,
            footerText: 'Footer text',
            includeDefaultStyles: true,
        );

        $this->createRenderer($siteConfig)->renderNotFound();

        self::assertSame([
            'siteTitle' => 'My Site',
            'footerText' => 'Footer text',
            'includeDefaultStyles' => true,
            'includeHighlighterStyles' => false,
            'stylesheets' => [],
        ], $this->context);
    }

    #[Test]
    public function itPassesTheFileNamesOfTheCopiedStylesheets(): void
    {
        $siteConfig = new SiteConfig(
            title: 'My Site',
            baseUrl: 'https://example.test',
            description: 'Site description',
            articlesPerPage: 10,
            footerText: 'Footer text',
            includeDefaultStyles: true,
            stylesheets: ['./themes/print.css/.', $this->projectDir.'/themes/custom.css'],
        );

        $this->createRenderer($siteConfig)->renderNotFound();

        self::assertSame(['print.css', 'custom.css'], $this->context['stylesheets']);
        self::assertSame(array_keys((new StylesheetResolver($this->projectDir, $siteConfig))->resolve()), $this->context['stylesheets']);
    }

    #[Test]
    public function itPassesTheFeedVariablesToTheRssTemplate(): void
    {
        $articles = [$this->createPage()];
        $before = new \DateTimeImmutable();

        $this->createRenderer($this->createSiteConfig())->renderRss($articles);

        $after = new \DateTimeImmutable();
        $generatedAt = $this->context['generatedAt'] ?? null;
        self::assertInstanceOf(\DateTimeImmutable::class, $generatedAt);
        self::assertGreaterThanOrEqual($before, $generatedAt);
        self::assertLessThanOrEqual($after, $generatedAt);

        self::assertSame('@Staccato/rss.xml.twig', $this->renderedTemplate);
        self::assertSame([
            'articles' => $articles,
            'siteTitle' => 'My Site',
            'siteDescription' => 'Site description',
            'baseUrl' => 'https://example.test/repo',
            'generatedAt' => $generatedAt,
        ], $this->context);
    }

    #[Test]
    public function itPassesTheUrlsAndTheBaseUrlToTheSitemapTemplate(): void
    {
        $this->createRenderer($this->createSiteConfig())->renderSitemap(['/', '/about/']);

        self::assertSame('@Staccato/sitemap.xml.twig', $this->renderedTemplate);
        self::assertSame([
            'urls' => ['/', '/about/'],
            'baseUrl' => 'https://example.test/repo',
        ], $this->context);
    }

    #[Test]
    public function itPassesOnlyThePageToTheRedirectTemplate(): void
    {
        $page = $this->createPage();

        $this->createRenderer($this->createSiteConfig())->renderRedirect($page);

        self::assertSame('@Staccato/redirect.html.twig', $this->renderedTemplate);
        self::assertSame(['page' => $page], $this->context);
    }

    #[Test]
    public function itRendersTheGivenTemplateInsteadOfTheDefaultOne(): void
    {
        $renderer = $this->createRenderer($this->createSiteConfig());
        $page = $this->createPage();
        $home = new PaginatedHomeContext(articles: [], currentPage: 1, totalPages: 1, previousPageUrl: null, nextPageUrl: null, urlPath: '/');

        $renders = [
            'page' => static fn (): string => $renderer->renderPage($page, 'custom.twig'),
            'home' => static fn (): string => $renderer->renderHome($home, 'custom.twig'),
            'not found' => static fn (): string => $renderer->renderNotFound('custom.twig'),
            'rss' => static fn (): string => $renderer->renderRss([], 'custom.twig'),
            'sitemap' => static fn (): string => $renderer->renderSitemap([], 'custom.twig'),
            'redirect' => static fn (): string => $renderer->renderRedirect($page, 'custom.twig'),
        ];

        foreach ($renders as $name => $render) {
            $this->renderedTemplate = null;
            $render();

            self::assertSame('custom.twig', $this->renderedTemplate, $name);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedLayoutVariables(): array
    {
        return [
            'siteTitle' => 'My Site',
            'footerText' => 'Footer text',
            'includeDefaultStyles' => false,
            'includeHighlighterStyles' => true,
            'stylesheets' => ['custom.css', 'print.css'],
        ];
    }

    private function createSiteConfig(): SiteConfig
    {
        return new SiteConfig(
            title: 'My Site',
            baseUrl: 'https://example.test/repo',
            description: 'Site description',
            articlesPerPage: 10,
            footerText: 'Footer text',
            includeDefaultStyles: false,
            stylesheets: ['themes/custom.css', 'themes/print.css'],
            highlighterCss: 'themes/highlight.css',
        );
    }

    /**
     * Every template records its own name and the exact variables it was rendered with.
     */
    private function createRenderer(SiteConfig $siteConfig): PageRenderer
    {
        $templates = [];

        foreach (self::TEMPLATES as $name) {
            $templates[$name] = sprintf("{{ capture('%s') }}", $name);
        }

        $twig = new Environment(new ArrayLoader($templates), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('capture', function (array $context, string $template): string {
            $this->renderedTemplate = $template;
            $this->context = $context;

            return '';
        }, ['needs_context' => true]));

        return new PageRenderer($twig, $siteConfig, new StylesheetResolver($this->projectDir, $siteConfig));
    }

    private function createPage(): Page
    {
        return new Page(
            sourcePath: 'articles/about.md',
            title: 'About',
            slug: 'about',
            publishedAt: new \DateTimeImmutable('2026-01-15'),
            excerpt: 'Short summary',
            draft: false,
            html: '<p>Hello.</p>',
            urlPath: '/about/',
        );
    }
}
