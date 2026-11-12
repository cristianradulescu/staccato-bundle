<?php

declare(strict_types=1);

namespace Staccato\Model;

final readonly class SiteConfig
{
    // Directories relative to %kernel.project_dir%, normalized by normalizeDirectory(). SiteBuilder joins them to the
    // project directory as they are and removes the build directory on a clean build, so every SiteConfig, configured
    // or constructed by hand, holds only directories that passed that rule.
    public string $contentDirectory;
    public string $buildDirectory;
    public string $assetsDirectory;
    public string $staticDirectory;

    /**
     * @param list<string> $stylesheets
     *
     * @throws \InvalidArgumentException when a directory is not a path relative to the project directory
     */
    public function __construct(
        public string $title,
        public string $baseUrl,
        public string $description,
        public int $articlesPerPage,
        public string $footerText,
        public bool $includeDefaultStyles,
        public array $stylesheets = [],
        public ?string $highlighterCss = null,
        string $contentDirectory = 'content',
        string $buildDirectory = 'build',
        string $assetsDirectory = 'assets',
        public string $language = 'en',
        string $staticDirectory = 'static',
    ) {
        $this->contentDirectory = self::projectDirectory('contentDirectory', $contentDirectory);
        $this->buildDirectory = self::projectDirectory('buildDirectory', $buildDirectory);
        $this->assetsDirectory = self::projectDirectory('assetsDirectory', $assetsDirectory);
        $this->staticDirectory = self::projectDirectory('staticDirectory', $staticDirectory);
    }

    /**
     * The only implementation of the directory rule: the staccato configuration tree, this constructor and
     * OutputPathResolver all apply it.
     *
     * @internal
     *
     * @return ?string the directory with forward slashes and without leading or trailing slashes, or null when it is
     *                 empty, absolute, or has an empty, "." or ".." segment
     */
    public static function normalizeDirectory(string $directory): ?string
    {
        $normalized = str_replace('\\', '/', trim($directory));
        $segments = explode('/', trim($normalized, '/'));
        $isAbsolute = str_starts_with($normalized, '/') || 1 === preg_match('#^[a-zA-Z]:/#', $normalized);

        if ($isAbsolute || [] !== array_intersect($segments, ['', '.', '..'])) {
            return null;
        }

        return implode('/', $segments);
    }

    private static function projectDirectory(string $name, string $directory): string
    {
        return self::normalizeDirectory($directory) ?? throw new \InvalidArgumentException(sprintf('The SiteConfig "%s" value "%s" must be a path relative to the project directory, without "." or ".." segments.', $name, $directory));
    }
}
