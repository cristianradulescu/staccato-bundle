<?php

declare(strict_types=1);

namespace Staccato\Model;

final readonly class PageListItem
{
    /**
     * @param array<string, mixed> $meta front matter keys other than the ones Staccato interprets itself
     */
    public function __construct(
        public string $title,
        public ?\DateTimeImmutable $publishedAt,
        public string $excerpt,
        public string $urlPath,
        public array $meta = [],
    ) {
    }
}
