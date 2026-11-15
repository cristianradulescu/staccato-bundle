<?php

declare(strict_types=1);

namespace Staccato\Service;

use Staccato\Model\SiteConfig;

final class OutputPathResolver
{
    /**
     * Letters that do not decompose into an ASCII letter and combining marks, with their usual ASCII spelling. Kept
     * short on purpose: letters of other scripts have no single ASCII spelling and fail with a hint to set a "slug".
     */
    private const ASCII_SPELLINGS = [
        'ß' => 'ss', // German
        'æ' => 'ae', // Danish, Norwegian
        'ø' => 'o', // Danish, Norwegian
        'œ' => 'oe', // French
        'đ' => 'd', // Croatian, Serbian, Vietnamese
        'ð' => 'd', // Icelandic, Faroese
        'ł' => 'l', // Polish
        'þ' => 'th', // Icelandic
        'ı' => 'i', // Turkish dotless i
    ];

    // A letter or digit outside ASCII.
    private const NON_ASCII_LETTER_OR_DIGIT_PATTERN = '/(?![\x00-\x7F])[\p{L}\p{N}]/u';

    public function deriveSlugFromSourcePath(string $sourcePath): string
    {
        $pathWithoutExtension = preg_replace('/\.md$/', '', str_replace('\\', '/', $sourcePath));

        if (null === $pathWithoutExtension || '' === $pathWithoutExtension) {
            throw new \RuntimeException(sprintf('Unable to derive slug for "%s".', $sourcePath));
        }

        return $this->slugify($pathWithoutExtension, $sourcePath, fromFrontMatter: false);
    }

    /**
     * Normalizes the front matter "slug" of $sourcePath.
     */
    public function normalizeSlug(string $rawSlug, string $sourcePath): string
    {
        return $this->slugify($rawSlug, $sourcePath, fromFrontMatter: true);
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

    private function slugify(string $rawSlug, string $sourcePath, bool $fromFrontMatter): string
    {
        $normalized = trim(str_replace('\\', '/', $this->spellInAscii($rawSlug, $sourcePath, $fromFrontMatter)));
        $normalized = trim($normalized, '/');
        $segments = array_filter(explode('/', $normalized), static fn (string $segment): bool => '' !== $segment);

        if ([] === $segments) {
            throw new \RuntimeException(sprintf('Slug of "%s" resolves to "/", which is reserved for the generated homepage.', $sourcePath));
        }

        $cleanSegments = [];

        foreach ($segments as $segment) {
            $segment = preg_replace('/[^a-z0-9\-_]/u', '-', $segment);
            $segment = null === $segment ? '' : trim($segment, '-_');
            $segment = preg_replace('/-+/', '-', $segment);

            if (null === $segment || '' === $segment || '.' === $segment || '..' === $segment) {
                throw new \RuntimeException(sprintf('Invalid slug segment in "%s".', $sourcePath));
            }

            $cleanSegments[] = $segment;
        }

        return implode('/', $cleanSegments);
    }

    /**
     * Lowercases $value and spells its letters in ASCII: accents and other combining marks are dropped after Unicode
     * decomposition ("ș" -> "s", "é" -> "e"), and ASCII_SPELLINGS covers letters that do not decompose ("ß" -> "ss").
     * Other non-ASCII characters (punctuation, symbols, spaces) are kept; the slug rules turn them into "-".
     *
     * The result must not depend on the PHP extensions installed, or the same content would get different URLs on
     * different machines. \Normalizer (intl or symfony/polyfill-intl-normalizer) and mb_strtolower() (mbstring or
     * symfony/polyfill-mbstring) give the same result with either implementation for every letter that has an ASCII
     * spelling; intl's transliterator and iconv's //TRANSLIT, which Symfony's AsciiSlugger uses, are not always
     * installed and spell differently depending on the implementation.
     */
    private function spellInAscii(string $value, string $sourcePath, bool $fromFrontMatter): string
    {
        // Checked first: mb_strtolower() replaces invalid UTF-8 with "?", and intl's \Normalizer returns false, warns or
        // throws depending on intl.error_level and intl.use_exceptions. A /u pattern fails on invalid UTF-8 everywhere.
        if (1 !== preg_match('//u', $value)) {
            if ($fromFrontMatter) {
                throw new \RuntimeException(sprintf('Cannot derive a slug from the front matter "slug" of "%s": it is not valid UTF-8.', $sourcePath));
            }

            throw new \RuntimeException(sprintf('Cannot derive a slug from "%s": the path is not valid UTF-8. Rename the file, or set a "slug" in its front matter.', $sourcePath));
        }

        $spelled = '';

        // One character at a time, so that an error names the character as written ("Й", not "и").
        foreach (mb_str_split($value, 1, 'UTF-8') as $character) {
            $decomposed = (string) \Normalizer::normalize(mb_strtolower($character, 'UTF-8'), \Normalizer::FORM_D);
            $ascii = strtr((string) preg_replace('/\p{Mn}/u', '', $decomposed), self::ASCII_SPELLINGS);

            if (1 === preg_match(self::NON_ASCII_LETTER_OR_DIGIT_PATTERN, $ascii)) {
                if ($fromFrontMatter) {
                    throw new \RuntimeException(sprintf('Cannot derive an ASCII slug from the front matter "slug" of "%s": "%s" has no ASCII equivalent. Write the "slug" in ASCII letters and digits.', $sourcePath, $character));
                }

                throw new \RuntimeException(sprintf('Cannot derive an ASCII slug from "%s": "%s" has no ASCII equivalent. Set a "slug" in its front matter.', $sourcePath, $character));
            }

            $spelled .= $ascii;
        }

        return $spelled;
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
