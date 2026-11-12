<?php

declare(strict_types=1);

namespace Staccato\Service;

use Staccato\Model\SiteConfig;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

/**
 * Resolves the configured stylesheets to the files copied into assets/staccato/. SiteBuilder copies exactly these files
 * and PageRenderer links exactly these file names, so the links and the copied files cannot drift apart.
 */
final readonly class StylesheetResolver
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private SiteConfig $siteConfig,
    ) {
    }

    /**
     * @return array<string, string> target file name => absolute source path, in configured order
     *
     * @throws \InvalidArgumentException when a stylesheet is missing or its file name collides with another one
     */
    public function resolve(): array
    {
        $paths = [];

        foreach ($this->siteConfig->stylesheets as $stylesheet) {
            $path = Path::makeAbsolute($stylesheet, $this->projectDir);

            if (!is_file($path)) {
                throw new \InvalidArgumentException(sprintf('Configured stylesheet file was not found at "%s".', $path));
            }

            // Every stylesheet is copied flat into assets/staccato/, so file names must not collide.
            $fileName = basename($path);

            if (in_array($fileName, ['staccato.css', 'highlighter.css'], true) || isset($paths[$fileName])) {
                throw new \InvalidArgumentException(sprintf('Configured stylesheet "%s" conflicts with another stylesheet named "%s".', $stylesheet, $fileName));
            }

            $paths[$fileName] = $path;
        }

        return $paths;
    }
}
