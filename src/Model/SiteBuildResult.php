<?php

declare(strict_types=1);

namespace Staccato\Model;

final readonly class SiteBuildResult
{
    public function __construct(
        public int $generatedPages,
        public int $skippedDraftPages,
        public bool $assetsCopied,
        public int $durationMs,
        public int $staticFilesCopied = 0,
        public int $redirectsGenerated = 0,
    ) {
    }
}
