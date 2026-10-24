<?php

declare(strict_types=1);

namespace Staccato\Twig;

use Staccato\Model\SiteConfig;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

final class StaccatoExtension extends AbstractExtension implements GlobalsInterface
{
    private readonly string $basePath;

    public function __construct(
        private readonly SiteConfig $siteConfig,
    ) {
        // A base_url like https://user.github.io/repo serves the site from /repo, so internal links need that prefix.
        $this->basePath = rtrim((string) parse_url($siteConfig->baseUrl, PHP_URL_PATH), '/');
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('staccato_path', $this->path(...)),
            new TwigFunction('staccato_url', $this->url(...)),
        ];
    }

    /**
     * Exposes the site configuration to every template as "staccato" (e.g. {{ staccato.language }}).
     */
    public function getGlobals(): array
    {
        return [
            'staccato' => $this->siteConfig,
        ];
    }

    /**
     * Prefixes a site-relative path (e.g. "/about/") with the base path derived from base_url.
     */
    public function path(string $path): string
    {
        return $this->basePath.'/'.ltrim($path, '/');
    }

    /**
     * Turns a site-relative path (e.g. "/about/") into an absolute URL under base_url.
     */
    public function url(string $path): string
    {
        return $this->siteConfig->baseUrl.'/'.ltrim($path, '/');
    }
}
