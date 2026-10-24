<?php

declare(strict_types=1);

namespace Staccato\Model;

final readonly class PaginatedHomeContext
{
    /**
     * @param list<PageListItem> $articles
     */
    public function __construct(
        public array $articles,
        public int $currentPage,
        public int $totalPages,
        public ?string $previousPageUrl,
        public ?string $nextPageUrl,
        public string $urlPath,
    ) {
    }
}
