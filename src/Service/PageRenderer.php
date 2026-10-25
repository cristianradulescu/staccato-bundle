<?php

declare(strict_types=1);

namespace Staccato\Service;

use Staccato\Model\Page;
use Staccato\Model\PaginatedHomeContext;
use Twig\Environment;

final readonly class PageRenderer
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    /**
     * @param list<string> $stylesheets file names copied into assets/staccato/
     */
    public function renderPage(Page $page, string $siteTitle, string $footerText, bool $includeDefaultStyles = true, bool $includeHighlighterStyles = false, array $stylesheets = [], string $template = '@Staccato/page.html.twig'): string
    {
        return $this->twig->render($template, [
            'page' => $page,
            'siteTitle' => $siteTitle,
            'footerText' => $footerText,
            'includeDefaultStyles' => $includeDefaultStyles,
            'includeHighlighterStyles' => $includeHighlighterStyles,
            'stylesheets' => $stylesheets,
        ]);
    }

    /**
     * @param list<string> $stylesheets file names copied into assets/staccato/
     */
    public function renderHome(PaginatedHomeContext $home, string $siteTitle, string $footerText, bool $includeDefaultStyles = true, bool $includeHighlighterStyles = false, array $stylesheets = [], string $template = '@Staccato/home.html.twig'): string
    {
        return $this->twig->render($template, [
            'home' => $home,
            'siteTitle' => $siteTitle,
            'footerText' => $footerText,
            'includeDefaultStyles' => $includeDefaultStyles,
            'includeHighlighterStyles' => $includeHighlighterStyles,
            'stylesheets' => $stylesheets,
        ]);
    }

    /**
     * @param list<Page> $articles
     */
    public function renderRss(array $articles, string $siteTitle, string $siteDescription, string $baseUrl, string $template = '@Staccato/rss.xml.twig'): string
    {
        return $this->twig->render($template, [
            'articles' => $articles,
            'siteTitle' => $siteTitle,
            'siteDescription' => $siteDescription,
            'baseUrl' => $baseUrl,
            'generatedAt' => new \DateTimeImmutable(),
        ]);
    }

    /**
     * @param list<string> $urls
     */
    public function renderSitemap(array $urls, string $baseUrl, string $template = '@Staccato/sitemap.xml.twig'): string
    {
        return $this->twig->render($template, [
            'urls' => $urls,
            'baseUrl' => $baseUrl,
        ]);
    }

    public function renderRedirect(Page $page, string $template = '@Staccato/redirect.html.twig'): string
    {
        return $this->twig->render($template, [
            'page' => $page,
        ]);
    }

    /**
     * @param list<string> $stylesheets file names copied into assets/staccato/
     */
    public function renderNotFound(string $siteTitle, string $footerText, bool $includeDefaultStyles = true, bool $includeHighlighterStyles = false, array $stylesheets = [], string $template = '@Staccato/404.html.twig'): string
    {
        return $this->twig->render($template, [
            'siteTitle' => $siteTitle,
            'footerText' => $footerText,
            'includeDefaultStyles' => $includeDefaultStyles,
            'includeHighlighterStyles' => $includeHighlighterStyles,
            'stylesheets' => $stylesheets,
        ]);
    }
}
