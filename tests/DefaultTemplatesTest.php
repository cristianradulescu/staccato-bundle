<?php

declare(strict_types=1);

namespace Staccato\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Staccato\Model\Page;
use Staccato\Model\PageListItem;
use Staccato\Model\PaginatedHomeContext;
use Staccato\Tests\Fixtures\TestKernel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Twig\Environment;

final class DefaultTemplatesTest extends TestCase
{
    private TestKernel $kernel;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->kernel = new TestKernel('default_templates');
        $this->kernel->boot();

        /** @var ContainerInterface $testContainer */
        $testContainer = $this->kernel->getContainer()->get('test.service_container');

        /** @var Environment $twig */
        $twig = $testContainer->get(Environment::class);
        $this->twig = $twig;
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        restore_exception_handler();
    }

    #[Test]
    public function itRendersTheDefaultPageTemplate(): void
    {
        $page = new Page(
            sourcePath: 'about.md',
            title: 'About',
            slug: 'about',
            publishedAt: new \DateTimeImmutable('2026-01-15'),
            excerpt: 'Short summary',
            draft: false,
            html: '<p>Hello world.</p>',
            urlPath: '/about/',
        );

        $html = $this->twig->render('@Staccato/page.html.twig', [
            'page' => $page,
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ]);

        self::assertStringContainsString('<h1 class="page-title">About</h1>', $html);
        self::assertStringContainsString('<p>Hello world.</p>', $html);
        self::assertStringContainsString('My Site', $html);
        self::assertStringContainsString('/assets/staccato/staccato.css', $html);
    }

    #[Test]
    public function itOmitsTheStylesheetLinkWhenDefaultStylesAreDisabled(): void
    {
        $page = new Page(
            sourcePath: 'about.md',
            title: 'About',
            slug: 'about',
            publishedAt: null,
            excerpt: '',
            draft: false,
            html: '<p>Hello.</p>',
            urlPath: '/about/',
        );

        $html = $this->twig->render('@Staccato/page.html.twig', [
            'page' => $page,
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => false,
        ]);

        self::assertStringNotContainsString('/assets/staccato/staccato.css', $html);
    }

    #[Test]
    public function itLinksTheHighlighterStylesheetOnlyWhenEnabled(): void
    {
        $page = new Page(
            sourcePath: 'about.md',
            title: 'About',
            slug: 'about',
            publishedAt: null,
            excerpt: '',
            draft: false,
            html: '<p>Hello.</p>',
            urlPath: '/about/',
        );

        $context = [
            'page' => $page,
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ];

        $withHighlighter = $this->twig->render('@Staccato/page.html.twig', $context + ['includeHighlighterStyles' => true]);
        $withoutHighlighter = $this->twig->render('@Staccato/page.html.twig', $context + ['includeHighlighterStyles' => false]);

        self::assertStringContainsString('/assets/staccato/highlighter.css', $withHighlighter);
        self::assertStringNotContainsString('/assets/staccato/highlighter.css', $withoutHighlighter);
    }

    #[Test]
    public function itLinksStylesheetsAfterTheDefaultOnes(): void
    {
        $html = $this->twig->render('@Staccato/404.html.twig', [
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
            'includeHighlighterStyles' => true,
            'stylesheets' => ['custom.css', 'print.css'],
        ]);

        $positions = array_map(
            static fn (string $href): int|false => strpos($html, 'href="'.$href.'"'),
            ['/assets/staccato/staccato.css', '/assets/staccato/highlighter.css', '/assets/staccato/custom.css', '/assets/staccato/print.css'],
        );

        self::assertNotContains(false, $positions);
        $sortedPositions = $positions;
        sort($sortedPositions);
        self::assertSame($sortedPositions, $positions);
    }

    #[Test]
    public function itLinksOwnStylesheetsWhenDefaultStylesAreDisabled(): void
    {
        $html = $this->twig->render('@Staccato/404.html.twig', [
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => false,
            'includeHighlighterStyles' => true,
            'stylesheets' => ['my-theme.css'],
        ]);

        self::assertStringNotContainsString('/assets/staccato/staccato.css', $html);
        self::assertStringContainsString('href="/assets/staccato/highlighter.css"', $html);
        self::assertStringContainsString('href="/assets/staccato/my-theme.css"', $html);
    }

    #[Test]
    public function itRendersTheDefaultHomeTemplate(): void
    {
        $home = new PaginatedHomeContext(
            articles: [
                new PageListItem(
                    title: 'First article',
                    publishedAt: new \DateTimeImmutable('2026-01-01'),
                    excerpt: 'Excerpt text',
                    urlPath: '/first-article/',
                ),
            ],
            currentPage: 1,
            totalPages: 2,
            previousPageUrl: null,
            nextPageUrl: '/page/2/',
            urlPath: '/',
        );

        $html = $this->twig->render('@Staccato/home.html.twig', [
            'home' => $home,
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ]);

        self::assertStringContainsString('First article', $html);
        self::assertStringContainsString('/first-article/', $html);
        self::assertStringContainsString('/page/2/', $html);
        self::assertStringContainsString('Page 1 of 2', $html);
    }

    #[Test]
    public function itPrefixesInternalLinksWithTheBasePathOfASubPathBaseUrl(): void
    {
        $kernel = new TestKernel('default_templates_sub_path', ['base_url' => 'https://example.test/repo/']);
        $kernel->boot();

        /** @var ContainerInterface $testContainer */
        $testContainer = $kernel->getContainer()->get('test.service_container');

        /** @var Environment $twig */
        $twig = $testContainer->get(Environment::class);

        $home = new PaginatedHomeContext(
            articles: [
                new PageListItem(
                    title: 'First article',
                    publishedAt: null,
                    excerpt: '',
                    urlPath: '/first-article/',
                ),
            ],
            currentPage: 2,
            totalPages: 3,
            previousPageUrl: '/',
            nextPageUrl: '/page/3/',
            urlPath: '/page/2/',
        );

        $html = $twig->render('@Staccato/home.html.twig', [
            'home' => $home,
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
            'includeHighlighterStyles' => true,
            'stylesheets' => ['custom.css'],
        ]);

        $kernel->shutdown();

        foreach (['/repo/assets/staccato/staccato.css', '/repo/assets/staccato/highlighter.css', '/repo/assets/staccato/custom.css', '/repo/', '/repo/first-article/', '/repo/page/3/'] as $href) {
            self::assertStringContainsString('href="'.$href.'"', $html);
        }

        self::assertDoesNotMatchRegularExpression('#href="/(?!repo/)#', $html);
    }

    #[Test]
    public function itRendersHeadMetadataForPages(): void
    {
        $html = $this->twig->render('@Staccato/page.html.twig', [
            'page' => $this->createPage(excerpt: 'Short "summary" & more'),
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ]);

        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('<meta name="description" content="Short &quot;summary&quot; &amp; more">', $html);
        self::assertStringContainsString('<link rel="canonical" href="http://localhost/about/">', $html);
        self::assertStringContainsString('<link rel="alternate" type="application/rss+xml" title="My Site" href="http://localhost/rss.xml">', $html);
    }

    #[Test]
    public function itRendersSocialMetadataForUndatedPages(): void
    {
        $html = $this->twig->render('@Staccato/page.html.twig', [
            'page' => $this->createPage(excerpt: 'Short "summary" & more'),
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ]);

        self::assertStringContainsString('<meta property="og:site_name" content="My Site">', $html);
        self::assertStringContainsString('<meta property="og:type" content="website">', $html);
        self::assertStringContainsString('<meta property="og:title" content="About">', $html);
        self::assertStringContainsString('<meta property="og:description" content="Short &quot;summary&quot; &amp; more">', $html);
        self::assertStringContainsString('<meta property="og:url" content="http://localhost/about/">', $html);
        self::assertStringContainsString('<meta name="twitter:card" content="summary">', $html);
        self::assertStringNotContainsString('article:published_time', $html);
    }

    #[Test]
    public function itMarksDatedPagesAsArticles(): void
    {
        $page = new Page(
            sourcePath: 'articles/first.md',
            title: 'First article',
            slug: 'first-article',
            publishedAt: new \DateTimeImmutable('2026-01-15T10:30:00+00:00'),
            excerpt: 'Excerpt',
            draft: false,
            html: '<p>Body</p>',
            urlPath: '/first-article/',
        );

        $html = $this->twig->render('@Staccato/page.html.twig', [
            'page' => $page,
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ]);

        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('<meta property="og:title" content="First article">', $html);
        self::assertMatchesRegularExpression('#<meta property="article:published_time" content="2026-01-15T\d{2}:\d{2}:00[+-]\d{2}:\d{2}">#', $html);
    }

    #[Test]
    public function itRendersSocialMetadataForHomePages(): void
    {
        $home = new PaginatedHomeContext(
            articles: [],
            currentPage: 2,
            totalPages: 2,
            previousPageUrl: '/',
            nextPageUrl: null,
            urlPath: '/page/2/',
        );

        $html = $this->twig->render('@Staccato/home.html.twig', [
            'home' => $home,
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ]);

        self::assertStringContainsString('<meta property="og:type" content="website">', $html);
        self::assertStringContainsString('<meta property="og:title" content="My Site">', $html);
        self::assertStringContainsString('<meta property="og:description" content="Static site generated with Staccato.">', $html);
        self::assertStringContainsString('<meta property="og:url" content="http://localhost/page/2/">', $html);
    }

    #[Test]
    public function itOmitsTheSocialUrlWhenThereIsNoCanonicalPage(): void
    {
        $html = $this->twig->render('@Staccato/404.html.twig', [
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ]);

        self::assertStringContainsString('<meta property="og:title" content="My Site">', $html);
        self::assertStringNotContainsString('og:url', $html);
    }

    #[Test]
    public function itLetsChildTemplatesReplaceTheSocialMetadata(): void
    {
        $template = $this->twig->createTemplate(<<<'TWIG'
            {% extends '@Staccato/base.html.twig' %}
            {% block social_meta %}<meta property="og:image" content="/card.png">{% endblock %}
            TWIG);

        $html = $template->render([
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ]);

        self::assertStringContainsString('<meta property="og:image" content="/card.png">', $html);
        self::assertStringNotContainsString('og:title', $html);
        self::assertStringNotContainsString('twitter:card', $html);
    }

    #[Test]
    public function itFallsBackToTheSiteDescriptionForPagesWithoutExcerpt(): void
    {
        $html = $this->twig->render('@Staccato/page.html.twig', [
            'page' => $this->createPage(excerpt: ''),
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ]);

        self::assertStringContainsString('<meta name="description" content="Static site generated with Staccato.">', $html);
    }

    #[Test]
    public function itRendersTheCanonicalUrlOfPaginatedHomePages(): void
    {
        $home = new PaginatedHomeContext(
            articles: [],
            currentPage: 2,
            totalPages: 2,
            previousPageUrl: '/',
            nextPageUrl: null,
            urlPath: '/page/2/',
        );

        $html = $this->twig->render('@Staccato/home.html.twig', [
            'home' => $home,
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ]);

        self::assertStringContainsString('<link rel="canonical" href="http://localhost/page/2/">', $html);
        self::assertStringContainsString('<meta name="description" content="Static site generated with Staccato.">', $html);
    }

    #[Test]
    public function itUsesTheConfiguredLanguage(): void
    {
        $kernel = new TestKernel('default_templates_language', ['language' => 'pt-BR']);
        $kernel->boot();

        /** @var ContainerInterface $testContainer */
        $testContainer = $kernel->getContainer()->get('test.service_container');

        /** @var Environment $twig */
        $twig = $testContainer->get(Environment::class);

        $html = $twig->render('@Staccato/404.html.twig', [
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ]);
        $xml = $twig->render('@Staccato/rss.xml.twig', [
            'articles' => [],
            'siteTitle' => 'My Site',
            'siteDescription' => 'Description',
            'baseUrl' => 'https://example.test',
            'generatedAt' => new \DateTimeImmutable('2026-01-02'),
        ]);

        $kernel->shutdown();

        self::assertStringContainsString('<html lang="pt-BR">', $html);
        self::assertStringNotContainsString('rel="canonical"', $html);
        self::assertStringContainsString('<language>pt-BR</language>', $xml);
    }

    #[Test]
    public function itRendersTheDefaultLayoutWithoutExtensionBlockContent(): void
    {
        $html = $this->twig->render('@Staccato/404.html.twig', [
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ]);

        self::assertStringContainsString('<a class="site-title" href="/">My Site</a>', $html);
        self::assertStringContainsString('<p>Built with Staccato</p>', $html);
        self::assertStringNotContainsString('<nav', $html);
    }

    #[Test]
    public function itLetsChildTemplatesExtendTheLayoutThroughBlocks(): void
    {
        $template = $this->twig->createTemplate(<<<'TWIG'
            {% extends '@Staccato/base.html.twig' %}
            {% block head_extra %}<script src="/analytics.js"></script>{% endblock %}
            {% block site_title %}<a class="site-title" href="/">~/my-site</a>{% endblock %}
            {% block nav %}<nav class="site-nav"><a href="/about/">About</a></nav>{% endblock %}
            {% block footer_content %}<p>Built with <a href="https://example.test">Staccato</a>.</p>{% endblock %}
            TWIG);

        $html = $template->render([
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ]);

        self::assertMatchesRegularExpression('#<script src="/analytics.js"></script>\s*</head>#', $html);
        self::assertStringContainsString('<a class="site-title" href="/">~/my-site</a>', $html);
        self::assertStringNotContainsString('>My Site</a>', $html);
        self::assertStringContainsString('<nav class="site-nav"><a href="/about/">About</a></nav>', $html);
        self::assertStringContainsString('<p>Built with <a href="https://example.test">Staccato</a>.</p>', $html);
        self::assertStringNotContainsString('<p>Built with Staccato</p>', $html);
    }

    #[Test]
    public function itLetsChildTemplatesReplaceTheWholeHeaderAndFooter(): void
    {
        $template = $this->twig->createTemplate(<<<'TWIG'
            {% extends '@Staccato/base.html.twig' %}
            {% block header %}<header class="custom-header"></header>{% endblock %}
            {% block footer %}{% endblock %}
            TWIG);

        $html = $template->render([
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ]);

        self::assertStringContainsString('<header class="custom-header"></header>', $html);
        self::assertStringNotContainsString('site-header', $html);
        self::assertStringNotContainsString('<footer', $html);
    }

    #[Test]
    public function itRendersTheDefaultNotFoundTemplate(): void
    {
        $html = $this->twig->render('@Staccato/404.html.twig', [
            'siteTitle' => 'My Site',
            'footerText' => 'Built with Staccato',
            'includeDefaultStyles' => true,
        ]);

        self::assertStringContainsString('Page not found', $html);
    }

    #[Test]
    public function itRendersTheDefaultRedirectTemplate(): void
    {
        $kernel = new TestKernel('default_templates_redirect', ['base_url' => 'https://example.test/repo/']);
        $kernel->boot();

        /** @var ContainerInterface $testContainer */
        $testContainer = $kernel->getContainer()->get('test.service_container');

        /** @var Environment $twig */
        $twig = $testContainer->get(Environment::class);

        $html = $twig->render('@Staccato/redirect.html.twig', ['page' => $this->createPage(excerpt: '')]);

        $kernel->shutdown();

        self::assertStringContainsString('<link rel="canonical" href="https://example.test/repo/about/">', $html);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $html);
        self::assertStringContainsString('<meta http-equiv="refresh" content="0; url=/repo/about/">', $html);
        self::assertStringContainsString('<a href="/repo/about/">About</a>', $html);
    }

    #[Test]
    public function itRendersTheDefaultRssTemplate(): void
    {
        $page = new Page(
            sourcePath: 'articles/first.md',
            title: 'First article',
            slug: 'first-article',
            publishedAt: new \DateTimeImmutable('2026-01-01'),
            excerpt: 'Excerpt text',
            draft: false,
            html: '<p>Body</p>',
            urlPath: '/first-article/',
        );

        $xml = $this->twig->render('@Staccato/rss.xml.twig', [
            'articles' => [$page],
            'siteTitle' => 'My Site',
            'siteDescription' => 'Description',
            'baseUrl' => 'https://example.test',
            'generatedAt' => new \DateTimeImmutable('2026-01-02'),
        ]);

        self::assertStringContainsString('<rss version="2.0">', $xml);
        self::assertStringContainsString('<title>First article</title>', $xml);
        self::assertStringContainsString('<link>https://example.test/first-article/</link>', $xml);
    }

    #[Test]
    public function itRendersTheDefaultSitemapTemplate(): void
    {
        $xml = $this->twig->render('@Staccato/sitemap.xml.twig', [
            'urls' => ['/', '/about/'],
            'baseUrl' => 'https://example.test',
        ]);

        self::assertStringContainsString('<loc>https://example.test/</loc>', $xml);
        self::assertStringContainsString('<loc>https://example.test/about/</loc>', $xml);
    }

    private function createPage(string $excerpt): Page
    {
        return new Page(
            sourcePath: 'about.md',
            title: 'About',
            slug: 'about',
            publishedAt: null,
            excerpt: $excerpt,
            draft: false,
            html: '<p>Hello.</p>',
            urlPath: '/about/',
        );
    }
}
