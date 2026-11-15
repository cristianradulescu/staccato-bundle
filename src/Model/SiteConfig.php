<?php

declare(strict_types=1);

namespace Staccato\Model;

final readonly class SiteConfig
{
    // Directories relative to %kernel.project_dir%, normalized by normalizeDirectory(), with a build directory that
    // overlappingDirectory() keeps apart from the other three. SiteBuilder joins them to the project directory as they
    // are and removes the build directory on a clean build, so every SiteConfig, configured or constructed by hand,
    // holds only directories that passed both rules.
    public string $contentDirectory;
    public string $buildDirectory;
    public string $assetsDirectory;
    public string $staticDirectory;

    /**
     * @param list<string> $stylesheets
     *
     * @throws \InvalidArgumentException when a directory is not a path relative to the project directory, or when the
     *                                   build directory equals, contains or sits inside one of the other directories
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

        $sourceDirectories = [
            'contentDirectory' => $this->contentDirectory,
            'assetsDirectory' => $this->assetsDirectory,
            'staticDirectory' => $this->staticDirectory,
        ];
        $overlapping = self::overlappingDirectory($this->buildDirectory, $sourceDirectories);

        if (null !== $overlapping) {
            throw new \InvalidArgumentException(sprintf('The SiteConfig "buildDirectory" value "%s" must not overlap with "%s" ("%s"), because the build directory is removed on every clean build.', $this->buildDirectory, $overlapping, $sourceDirectories[$overlapping]));
        }
    }

    /**
     * The only implementation of the directory rule: the staccato configuration tree, this constructor and
     * OutputPathResolver all apply it. The configuration tree and this constructor then apply the overlap rule,
     * overlappingDirectory(), to the normalized directories.
     *
     * @internal
     *
     * @return ?string the directory with forward slashes and without leading or trailing slashes, or null when it is
     *                 empty, absolute, or has a segment that is empty or ends in "." or a space ("." and ".." included)
     */
    public static function normalizeDirectory(string $directory): ?string
    {
        $normalized = str_replace('\\', '/', trim($directory));
        $segments = explode('/', trim($normalized, '/'));
        $isAbsolute = str_starts_with($normalized, '/') || 1 === preg_match('#^[a-zA-Z]:/#', $normalized);

        if ($isAbsolute) {
            return null;
        }

        foreach ($segments as $segment) {
            // Windows drops trailing periods and spaces from path names, so "content." is the "content" directory
            // there, which overlappingDirectory() would not see. Rejected on every platform; covers "." and "..".
            if ('' === $segment || str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
                return null;
            }
        }

        return implode('/', $segments);
    }

    /**
     * The only implementation of the overlap rule: the staccato configuration tree and this constructor both apply it.
     * The build directory is removed on every clean build, so it must not equal, contain or sit inside a source
     * directory. Whole segments are compared ("build" does not overlap with "builder"), ignoring case: on a
     * case-insensitive filesystem (the macOS and Windows default) "Content" is the "content" directory. Case is
     * lowered with mb_strtolower(), because strtolower() only lowers ASCII letters and "Über" is "über" there too.
     *
     * @internal
     *
     * @param string                $buildDirectory    a directory normalized by normalizeDirectory()
     * @param array<string, string> $sourceDirectories directories normalized by normalizeDirectory(), keyed by name
     *
     * @return ?string the key of the first source directory the build directory equals, contains or sits inside, or
     *                 null when there is none
     */
    public static function overlappingDirectory(string $buildDirectory, array $sourceDirectories): ?string
    {
        // The trailing slash makes the prefix check match whole segments only.
        $build = mb_strtolower($buildDirectory, 'UTF-8').'/';

        foreach ($sourceDirectories as $name => $sourceDirectory) {
            $source = mb_strtolower($sourceDirectory, 'UTF-8').'/';

            if (str_starts_with($build, $source) || str_starts_with($source, $build)) {
                return $name;
            }
        }

        return null;
    }

    private static function projectDirectory(string $name, string $directory): string
    {
        return self::normalizeDirectory($directory) ?? throw new \InvalidArgumentException(sprintf('The SiteConfig "%s" value "%s" must be a path relative to the project directory, with no segment that is empty or ends in "." or a space (such as "..").', $name, $directory));
    }
}
