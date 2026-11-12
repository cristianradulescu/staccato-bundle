<?php

declare(strict_types=1);

namespace Staccato\Service;

use Staccato\Model\Page;
use Staccato\Model\PaginatedHomeContext;
use Staccato\Model\SiteConfig;
use Twig\Environment;

final readonly class PageRenderer
{
    public function __construct(
        private Environment $twig,
        private SiteConfig $siteConfig,
        private StylesheetResolver $stylesheetResolver,
    ) {
    }

    public function renderPage(Page $page, string $template = '@Staccato/page.html.twig'): string
    {
        return $this->twig->render($template, ['page' => $page, ...$this->layoutContext()]);
    }

    public function renderHome(PaginatedHomeContext $home, string $template = '@Staccato/home.html.twig'): string
    {
        return $this->twig->render($template, ['home' => $home, ...$this->layoutContext()]);
    }

    /**
     * @param list<Page> $articles
     */
    public function renderRss(array $articles, string $template = '@Staccato/rss.xml.twig'): string
    {
        return $this->twig->render($template, [
            'articles' => $articles,
            'siteTitle' => $this->siteConfig->title,
            'siteDescription' => $this->siteConfig->description,
            'baseUrl' => $this->siteConfig->baseUrl,
            'generatedAt' => new \DateTimeImmutable(),
        ]);
    }

    /**
     * @param list<string> $urls
     */
    public function renderSitemap(array $urls, string $template = '@Staccato/sitemap.xml.twig'): string
    {
        return $this->twig->render($template, [
            'urls' => $urls,
            'baseUrl' => $this->siteConfig->baseUrl,
        ]);
    }

    public function renderRedirect(Page $page, string $template = '@Staccato/redirect.html.twig'): string
    {
        return $this->twig->render($template, [
            'page' => $page,
        ]);
    }

    public function renderNotFound(string $template = '@Staccato/404.html.twig'): string
    {
        return $this->twig->render($template, $this->layoutContext());
    }

    /**
     * The site-wide variables of the base.html.twig layout. They predate the "staccato" global and are kept, with the
     * same names and values, because templates overridden by applications read them.
     *
     * @return array{siteTitle: string, footerText: string, includeDefaultStyles: bool, includeHighlighterStyles: bool, stylesheets: list<string>}
     */
    private function layoutContext(): array
    {
        return [
            'siteTitle' => $this->siteConfig->title,
            'footerText' => $this->siteConfig->footerText,
            'includeDefaultStyles' => $this->siteConfig->includeDefaultStyles,
            'includeHighlighterStyles' => null !== $this->siteConfig->highlighterCss,
            // The file names SiteBuilder copies into assets/staccato/.
            'stylesheets' => array_keys($this->stylesheetResolver->resolve()),
        ];
    }
}
