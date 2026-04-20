<?php

declare(strict_types=1);

namespace Staccato\Service;

final class OutputPathResolver
{
    public function deriveSlugFromSourcePath(string $sourcePath): string
    {
        $pathWithoutExtension = preg_replace('/\.md$/', '', str_replace('\\', '/', $sourcePath));

        if (null === $pathWithoutExtension || '' === $pathWithoutExtension) {
            throw new \RuntimeException(sprintf('Unable to derive slug for "%s".', $sourcePath));
        }

        if ('index' === $pathWithoutExtension) {
            return '';
        }

        if (str_ends_with($pathWithoutExtension, '/index')) {
            $pathWithoutExtension = substr($pathWithoutExtension, 0, -strlen('/index'));
        }

        if ('' === $pathWithoutExtension) {
            return '';
        }

        return $this->normalizeSlug($pathWithoutExtension, $sourcePath);
    }

    public function normalizeSlug(string $rawSlug, string $sourcePath): string
    {
        $normalized = trim(str_replace('\\', '/', strtolower($rawSlug)));
        $normalized = trim($normalized, '/');

        if ('' === $normalized || 'index' === $normalized) {
            return '';
        }

        $segments = array_filter(explode('/', $normalized), static fn (string $segment): bool => '' !== $segment);

        if ([] === $segments) {
            return '';
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

    private function normalizeBuildDirectory(string $buildDirectory): string
    {
        $normalized = trim(str_replace('\\', '/', $buildDirectory), '/');

        if ('' === $normalized) {
            throw new \RuntimeException('Build directory cannot be empty.');
        }

        $segments = explode('/', $normalized);

        foreach ($segments as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                throw new \RuntimeException(sprintf('Invalid build directory "%s".', $buildDirectory));
            }
        }

        return $normalized;
    }
}
