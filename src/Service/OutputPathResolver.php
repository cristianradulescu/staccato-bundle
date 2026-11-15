<?php

declare(strict_types=1);

namespace Staccato\Service;

use Staccato\Model\SiteConfig;

final class OutputPathResolver
{
    public function deriveSlugFromSourcePath(string $sourcePath): string
    {
        $pathWithoutExtension = preg_replace('/\.md$/', '', str_replace('\\', '/', $sourcePath));

        if (null === $pathWithoutExtension || '' === $pathWithoutExtension) {
            throw new \RuntimeException(sprintf('Unable to derive slug for "%s".', $sourcePath));
        }

        return $this->normalizeSlug($pathWithoutExtension, $sourcePath);
    }

    public function normalizeSlug(string $rawSlug, string $sourcePath): string
    {
        $normalized = trim(str_replace('\\', '/', strtolower($rawSlug)));
        $normalized = trim($normalized, '/');
        $segments = array_filter(explode('/', $normalized), static fn (string $segment): bool => '' !== $segment);

        if ([] === $segments) {
            throw new \RuntimeException(sprintf('Slug of "%s" resolves to "/", which is reserved for the generated homepage.', $sourcePath));
        }

        $cleanSegments = [];

        foreach ($segments as $segment) {
            $segment = preg_replace('/[^a-z0-9\-_]/', '-', $segment);
            $segment = null === $segment ? '' : trim($segment, '-_');
            $segment = preg_replace('/-+/', '-', $segment);

            if (null === $segment || '' === $segment || '.' === $segment || '..' === $segment) {
                throw new \RuntimeException(sprintf('Invalid slug segment in "%s".', $sourcePath));
            }

            $cleanSegments[] = $segment;
        }

        return implode('/', $cleanSegments);
    }

    public function buildUrlPath(string $slug): string
    {
        if ('' === $slug) {
            return '/';
        }

        return '/'.$slug.'/';
    }

    /**
     * Normalizes an alias (an old URL of a page) to a URL path. Unlike slugs, aliases keep their case and may
     * contain dots, so URLs from other generators (/Old-Post/, /2020/01/post.html) can be kept as they were.
     * A segment may not end in a dot: Windows drops it, so /a./ would be written over the page /a/ there.
     */
    public function normalizeAliasUrlPath(string $alias, string $sourcePath): string
    {
        $normalized = trim(str_replace('\\', '/', $alias));

        if (!str_starts_with($normalized, '/')) {
            throw new \RuntimeException(sprintf('Alias "%s" in "%s" must be a URL path starting with "/".', $alias, $sourcePath));
        }

        $trimmed = trim($normalized, '/');

        if ('' === $trimmed) {
            throw new \RuntimeException(sprintf('Alias "%s" in "%s" resolves to "/", which is reserved for the generated homepage.', $alias, $sourcePath));
        }

        foreach (explode('/', $trimmed) as $segment) {
            // The last character excludes ".", which also rules out "." and "..".
            if (1 !== preg_match('/^[A-Za-z0-9._~-]*[A-Za-z0-9_~-]\z/', $segment)) {
                throw new \RuntimeException(sprintf('Alias "%s" in "%s" may only contain letters, digits, ".", "_", "~" and "-" between slashes, with no segment ending in ".".', $alias, $sourcePath));
            }
        }

        // A path ending in .html names the file itself; anything else is a directory-style URL like page URLs.
        return str_ends_with($trimmed, '.html') ? '/'.$trimmed : '/'.$trimmed.'/';
    }

    public function resolveAliasOutputPath(string $aliasUrlPath, string $buildDirectory = 'build'): string
    {
        $trimmed = trim($aliasUrlPath, '/');

        return $this->normalizeBuildDirectory($buildDirectory).'/'.(str_ends_with($aliasUrlPath, '.html') ? $trimmed : $trimmed.'/index.html');
    }

    public function resolveOutputPath(string $urlPath, string $buildDirectory = 'build'): string
    {
        $normalizedBuildDirectory = $this->normalizeBuildDirectory($buildDirectory);
        $normalizedUrlPath = trim(str_replace('\\', '/', $urlPath));

        if (!str_starts_with($normalizedUrlPath, '/')) {
            throw new \RuntimeException(sprintf('URL path "%s" must start with "/".', $urlPath));
        }

        $trimmedUrlPath = trim($normalizedUrlPath, '/');

        if ('' === $trimmedUrlPath) {
            return $normalizedBuildDirectory.'/index.html';
        }

        $segments = explode('/', $trimmedUrlPath);

        foreach ($segments as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment || !preg_match('/^[a-z0-9][a-z0-9\-_]*$/', $segment)) {
                throw new \RuntimeException(sprintf('Invalid URL path "%s".', $urlPath));
            }
        }

        return $normalizedBuildDirectory.'/'.implode('/', $segments).'/index.html';
    }

    /**
     * SiteBuilder passes SiteConfig::$buildDirectory, which is already normalized; the check is for direct callers,
     * since the returned path is written to.
     */
    private function normalizeBuildDirectory(string $buildDirectory): string
    {
        return SiteConfig::normalizeDirectory($buildDirectory) ?? throw new \RuntimeException(sprintf('Invalid build directory "%s".', $buildDirectory));
    }
}
