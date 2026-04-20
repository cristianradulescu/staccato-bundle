<?php

declare(strict_types=1);

namespace Staccato\Service;

use Staccato\Model\SiteBuildResult;

interface SiteBuilderInterface
{
    public function build(
        string $contentDirectory = 'content',
        string $buildDirectory = 'build',
        string $assetsDirectory = 'assets',
        bool $clean = true,
        bool $includeDrafts = false,
        bool $useFixtures = false,
    ): SiteBuildResult;
}
