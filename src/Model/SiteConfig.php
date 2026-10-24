<?php

declare(strict_types=1);

namespace Staccato\Model;

final readonly class SiteConfig
{
    /**
     * @param list<string> $extraDefaultStyles
     */
    public function __construct(
        public string $title,
        public string $baseUrl,
        public string $description,
        public int $articlesPerPage,
        public string $footerText,
        public bool $includeDefaultStyles,
        public array $extraDefaultStyles = [],
        public ?string $highlighterCss = null,
    ) {
    }
}
