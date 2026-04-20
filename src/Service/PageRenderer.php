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

    public function renderPage(Page $page, string $siteTitle, string $footerText, string $template = 'page.html.twig'): string
    {
        return $this->twig->render($template, [
            'page' => $page,
            'siteTitle' => $siteTitle,
            'footerText' => $footerText,
        ]);
    }

    public function renderHome(PaginatedHomeContext $home, string $siteTitle, string $footerText, string $template = 'home.html.twig'): string
    {
        return $this->twig->render($template, [
            'home' => $home,
            'siteTitle' => $siteTitle,
            'footerText' => $footerText,
        ]);
    }

    /**
     * @param list<Page> $articles
     */
    public function renderRss(array $articles, string $siteTitle, string $siteDescription, string $baseUrl, string $template = 'rss.xml.twig'): string
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
    public function renderSitemap(array $urls, string $baseUrl, string $template = 'sitemap.xml.twig'): string
    {
        return $this->twig->render($template, [
            'urls' => $urls,
            'baseUrl' => $baseUrl,
        ]);
    }

    public function renderNotFound(string $siteTitle, string $footerText, string $template = '404.html.twig'): string
    {
        return $this->twig->render($template, [
            'siteTitle' => $siteTitle,
            'footerText' => $footerText,
        ]);
    }
}
