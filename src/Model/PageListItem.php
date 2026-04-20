<?php

declare(strict_types=1);

namespace Staccato\Model;

final readonly class PageListItem
{
    public function __construct(
        public string $title,
        public ?\DateTimeImmutable $publishedAt,
        public string $excerpt,
        public string $urlPath,
    ) {
    }
}
