<?php

declare(strict_types=1);

namespace Staccato\Model;

final readonly class Page
{
    /**
     * @param array<string, mixed> $meta front matter keys other than the ones Staccato interprets itself
     */
    public function __construct(
        public string $sourcePath,
        public string $title,
        public string $slug,
        public ?\DateTimeImmutable $publishedAt,
        public string $excerpt,
        public bool $draft,
        public string $html,
        public string $urlPath,
        public array $meta = [],
    ) {
    }
}
