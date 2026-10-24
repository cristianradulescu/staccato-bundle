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
}
