<?php

declare(strict_types=1);

namespace Staccato\Event;

use Staccato\Model\Page;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by SiteBuilder after every file of a successful build has been written,
 * so listeners can add their own files (redirects, search indexes, extra feeds) to the build directory.
 */
final class SiteBuiltEvent extends Event
{
    /**
     * @param string     $buildDirectory absolute path of the build directory
     * @param list<Page> $pages          every page written by this build (drafts only when they were included)
     */
    public function __construct(
        public readonly string $buildDirectory,
        public readonly array $pages,
    ) {
    }
}
