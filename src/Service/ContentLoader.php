<?php

declare(strict_types=1);

namespace Staccato\Service;

use League\CommonMark\CommonMarkConverter;
use Staccato\Model\Page;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ContentLoader
{
    private const FRONT_MATTER_DELIMITER = '---';

    /**
     * @var \Closure(string): string
     */
    private readonly \Closure $markdownToHtml;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly OutputPathResolver $outputPathResolver,
    ) {
        $markdownConverter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $this->markdownToHtml = static fn (string $markdown): string => (string) $markdownConverter->convert($markdown);
    }

    /**
     * @return list<Page>
     */
    public function loadPages(string $contentDirectory = 'content'): array
    {
        $contentPath = $this->projectDir.'/'.$contentDirectory;

        if (!is_dir($contentPath)) {
            return [];
        }

        $finder = new Finder();
        $finder
            ->files()
            ->in($contentPath)
            ->name('*.md')
            ->sortByName();

        $pages = [];

        foreach ($finder as $file) {
            $relativePath = $file->getRelativePathname();
            $source = $file->getContents();
            [$frontMatter, $markdownBody] = $this->extractFrontMatter($source, $relativePath);
            $title = $this->resolveTitle($frontMatter, $relativePath);
            $slug = $this->resolveSlug($frontMatter, $relativePath);
            $publishedAt = $this->resolvePublishedAt($frontMatter, $relativePath);
            $draft = $this->resolveDraft($frontMatter, $relativePath);
            $excerpt = $this->resolveExcerpt($frontMatter, $markdownBody, $relativePath);

            $pages[] = new Page(
                sourcePath: $relativePath,
                title: $title,
                slug: $slug,
                publishedAt: $publishedAt,
                excerpt: $excerpt,
                draft: $draft,
                html: ($this->markdownToHtml)($markdownBody),
                urlPath: $this->outputPathResolver->buildUrlPath($slug),
            );
        }

        return $pages;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function extractFrontMatter(string $source, string $sourcePath): array
    {
        if (!str_starts_with($source, self::FRONT_MATTER_DELIMITER."\n")) {
            return [[], $source];
        }

        $closingPos = strpos($source, "\n".self::FRONT_MATTER_DELIMITER."\n", strlen(self::FRONT_MATTER_DELIMITER) + 1);

        if (false === $closingPos) {
            throw new \RuntimeException(sprintf('Invalid front matter in "%s": missing closing delimiter.', $sourcePath));
        }

        $yamlStart = strlen(self::FRONT_MATTER_DELIMITER) + 1;
        $yaml = substr($source, $yamlStart, $closingPos - $yamlStart);
        $bodyStart = $closingPos + strlen("\n".self::FRONT_MATTER_DELIMITER."\n");
        $body = substr($source, $bodyStart);

        if ('' === trim($yaml)) {
            return [[], $body];
        }

        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw new \RuntimeException(sprintf('Invalid YAML front matter in "%s": %s', $sourcePath, $exception->getMessage()), previous: $exception);
        }

        if (!is_array($parsed)) {
            throw new \RuntimeException(sprintf('Front matter in "%s" must be a YAML object.', $sourcePath));
        }

        return [$parsed, $body];
    }

    /**
     * @param array<string, mixed> $frontMatter
     */
    private function resolveTitle(array $frontMatter, string $sourcePath): string
    {
        $title = $frontMatter['title'] ?? null;

        if (null !== $title) {
            if (!is_string($title) || '' === trim($title)) {
                throw new \RuntimeException(sprintf('Front matter key "title" in "%s" must be a non-empty string.', $sourcePath));
            }

            return trim($title);
        }

        return $this->humanizePathTitle($sourcePath);
    }

    /**
     * @param array<string, mixed> $frontMatter
     */
    private function resolveSlug(array $frontMatter, string $sourcePath): string
    {
        $slug = $frontMatter['slug'] ?? null;

        if (null !== $slug) {
            if (!is_string($slug) || '' === trim($slug)) {
                throw new \RuntimeException(sprintf('Front matter key "slug" in "%s" must be a non-empty string.', $sourcePath));
            }

            return $this->outputPathResolver->normalizeSlug($slug, $sourcePath);
        }

        $pathForSlug = str_starts_with($sourcePath, 'articles/') ? (string) substr($sourcePath, strlen('articles/')) : $sourcePath;

        return $this->outputPathResolver->deriveSlugFromSourcePath($pathForSlug);
    }

    /**
     * @param array<string, mixed> $frontMatter
     */
    private function resolvePublishedAt(array $frontMatter, string $sourcePath): ?\DateTimeImmutable
    {
        $date = $frontMatter['date'] ?? null;

        if (null === $date) {
            return null;
        }

        if ($date instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($date);
        }

        if (is_int($date)) {
            return (new \DateTimeImmutable())->setTimestamp($date);
        }

        if (!is_string($date) || '' === trim($date)) {
            throw new \RuntimeException(sprintf('Front matter key "date" in "%s" must be a non-empty string.', $sourcePath));
        }

        try {
            return new \DateTimeImmutable(trim($date));
        } catch (\Exception $exception) {
            throw new \RuntimeException(sprintf('Front matter key "date" in "%s" must be a valid date string.', $sourcePath), previous: $exception);
        }
    }

    /**
     * @param array<string, mixed> $frontMatter
     */
    private function resolveExcerpt(array $frontMatter, string $markdownBody, string $sourcePath): string
    {
        $excerpt = $frontMatter['excerpt'] ?? null;

        if (null !== $excerpt) {
            if (!is_string($excerpt) || '' === trim($excerpt)) {
                throw new \RuntimeException(sprintf('Front matter key "excerpt" in "%s" must be a non-empty string.', $sourcePath));
            }

            return trim($excerpt);
        }

        $plainText = trim(strip_tags((string) ($this->markdownToHtml)($markdownBody)));

        if ('' === $plainText) {
            return '';
        }

        if (mb_strlen($plainText) <= 220) {
            return $plainText;
        }

        return rtrim(mb_substr($plainText, 0, 217)).'...';
    }

    /**
     * @param array<string, mixed> $frontMatter
     */
    private function resolveDraft(array $frontMatter, string $sourcePath): bool
    {
        $draft = $frontMatter['draft'] ?? false;

        if (!is_bool($draft)) {
            throw new \RuntimeException(sprintf('Front matter key "draft" in "%s" must be a boolean.', $sourcePath));
        }

        return $draft;
    }

    private function humanizePathTitle(string $sourcePath): string
    {
        $normalized = str_replace('\\', '/', $sourcePath);
        $withoutExtension = preg_replace('/\.md$/', '', $normalized);

        if (null === $withoutExtension || '' === $withoutExtension) {
            return 'Untitled';
        }

        $segments = explode('/', $withoutExtension);
        $lastSegment = end($segments);

        if ('index' === $lastSegment && count($segments) > 1) {
            $parentSegment = $segments[count($segments) - 2] ?? '';
            $lastSegment = 'index' === $parentSegment ? '' : $parentSegment;
        }

        $candidate = trim(str_replace(['-', '_'], ' ', $lastSegment));

        if ('' === $candidate) {
            return 'Untitled';
        }

        return $this->titleCaseAscii($candidate);
    }

    private function titleCaseAscii(string $value): string
    {
        $words = preg_split('/\s+/', strtolower($value));

        if (false === $words) {
            return $value;
        }

        $titledWords = array_map(static fn (string $word): string => ucfirst($word), $words);

        return implode(' ', $titledWords);
    }
}
