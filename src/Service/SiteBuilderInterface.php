<?php

declare(strict_types=1);

namespace Staccato\Service;

use Staccato\Model\SiteBuildResult;

interface SiteBuilderInterface
{
    public function build(
        bool $clean = true,
        bool $includeDrafts = false,
        bool $useFixtures = false,
    ): SiteBuildResult;
}
