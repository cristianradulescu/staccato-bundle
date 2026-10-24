<?php

declare(strict_types=1);

namespace Staccato\Model;

final readonly class SiteConfig
{
    /**
     * @param list<string> $stylesheets
     */
    public function __construct(
        public string $title,
        public string $baseUrl,
        public string $description,
        public int $articlesPerPage,
        public string $footerText,
        public bool $includeDefaultStyles,
        public array $stylesheets = [],
        public ?string $highlighterCss = null,
        public string $contentDirectory = 'content',
        public string $buildDirectory = 'build',
        public string $assetsDirectory = 'assets',
    ) {
    }
}
