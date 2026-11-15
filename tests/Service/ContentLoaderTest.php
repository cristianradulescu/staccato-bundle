<?php

declare(strict_types=1);

namespace Staccato\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Staccato\Service\ContentLoader;
use Staccato\Service\OutputPathResolver;

final class ContentLoaderTest extends TestCase
{
    private string $projectDir;
    private string $defaultTimeZone;

    protected function setUp(): void
    {
        $this->projectDir = rtrim(sys_get_temp_dir(), '/').'/staccato-content-loader-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir, 0777, true);
        $this->defaultTimeZone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimeZone);

        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }
    }

    #[Test]
    public function itReturnsNoPagesWhenContentDirectoryIsMissing(): void
    {
        $loader = $this->createLoader();

        self::assertSame([], $loader->loadPages());
    }

    #[Test]
    public function itParsesFrontMatterAndMarkdownBody(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents(
            $this->projectDir.'/content/about.md',
            "---\ntitle: About Staccato\nslug: company/about\ndate: 2026-04-01\nexcerpt: Short summary\ndraft: false\n---\n# Hello\n\nWelcome.",
        );

        $loader = $this->createLoader();
        $pages = $loader->loadPages();

        self::assertCount(1, $pages);
        self::assertSame('about.md', $pages[0]->sourcePath);
        self::assertSame('About Staccato', $pages[0]->title);
        self::assertSame('company/about', $pages[0]->slug);
        self::assertSame('/company/about/', $pages[0]->urlPath);
        self::assertSame('Short summary', $pages[0]->excerpt);
        self::assertSame('2026-04-01', $pages[0]->publishedAt?->format('Y-m-d'));
        self::assertFalse($pages[0]->draft);
        self::assertStringContainsString('<h1 id="hello">Hello</h1>', $pages[0]->html);
        self::assertStringContainsString('<p>Welcome.</p>', $pages[0]->html);
        self::assertSame([], $pages[0]->meta);
    }

    #[Test]
    public function itExposesUnknownFrontMatterKeysAsMeta(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents(
            $this->projectDir.'/content/about.md',
            "---\ntitle: About\ndate: 2026-04-01\nauthor: Jane Doe\ndescription: A longer description\ntags: [php, symfony]\nseries:\n  name: Basics\n  part: 2\n---\n# About",
        );
        file_put_contents($this->projectDir.'/content/plain.md', '# Plain');

        $pages = $this->createLoader()->loadPages();

        self::assertSame([
            'author' => 'Jane Doe',
            'description' => 'A longer description',
            'tags' => ['php', 'symfony'],
            'series' => ['name' => 'Basics', 'part' => 2],
        ], $pages[0]->meta);
        self::assertSame([], $pages[1]->meta);
    }

    #[Test]
    public function itHighlightsFencedCodeBlocks(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/code.md', "```php\n\$answer = 42;\n```\n");

        $loader = $this->createLoader();
        $pages = $loader->loadPages();

        self::assertCount(1, $pages);
        self::assertStringContainsString('<pre class="language-php">', $pages[0]->html);
        self::assertStringContainsString('<span class="hl-variable">$answer</span>', $pages[0]->html);
    }

    #[Test]
    public function itDerivesTitleSlugAndExcerptFromSourcePathWithoutFrontMatter(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/about-us.md', "# Home\n");

        $loader = $this->createLoader();
        $pages = $loader->loadPages();

        self::assertCount(1, $pages);
        self::assertSame('About Us', $pages[0]->title);
        self::assertSame('about-us', $pages[0]->slug);
        self::assertSame('/about-us/', $pages[0]->urlPath);
        self::assertSame('Home', $pages[0]->excerpt);
        self::assertNull($pages[0]->publishedAt);
    }

    #[Test]
    public function itMapsSiblingMarkdownFileToDirectoryUrl(): void
    {
        mkdir($this->projectDir.'/content/docs', 0777, true);
        file_put_contents($this->projectDir.'/content/docs.md', "# Docs\n");
        file_put_contents($this->projectDir.'/content/docs/setup.md', "# Setup\n");

        $loader = $this->createLoader();
        $pages = $loader->loadPages();

        self::assertSame(['/docs/', '/docs/setup/'], array_map(static fn ($page): string => $page->urlPath, $pages));
    }

    #[Test]
    public function itUsesForwardSlashesInTheSourcePathWhenFinderReturnsBackslashes(): void
    {
        // On Windows this writes articles/hello.md and Finder returns "articles\hello.md". On Linux and macOS
        // it is a single file literally named "articles\hello.md", which gives the loader that same string.
        mkdir($this->projectDir.'/content/articles', 0777, true);
        file_put_contents($this->projectDir.'/content/articles\\hello.md', "# Hello\n");

        $pages = $this->createLoader()->loadPages();

        self::assertCount(1, $pages);
        self::assertSame('articles/hello.md', $pages[0]->sourcePath);
        self::assertSame('Hello', $pages[0]->title);
        self::assertSame('hello', $pages[0]->slug);
        self::assertSame('/hello/', $pages[0]->urlPath);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function indexFiles(): iterable
    {
        yield 'root index' => ['index.md', 'the homepage is generated from the articles/ directory'];
        yield 'articles index' => ['articles/index.md', 'the homepage is generated from the articles/ directory'];
        yield 'nested index' => ['docs/index.md', 'Rename it to "docs.md"'];
        yield 'uppercase index' => ['docs/Index.md', 'Rename it to "docs.md"'];
    }

    #[Test]
    #[DataProvider('indexFiles')]
    public function itRejectsIndexFiles(string $sourcePath, string $expectedHint): void
    {
        mkdir(dirname($this->projectDir.'/content/'.$sourcePath), 0777, true);
        file_put_contents($this->projectDir.'/content/'.$sourcePath, "# Index\n");

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Content file "'.preg_quote($sourcePath, '/').'" is not supported: index\.md files are not used.*'.preg_quote($expectedHint, '/').'/');

        $loader->loadPages();
    }

    #[Test]
    public function itRejectsFrontMatterSlugResolvingToTheHomepage(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/home.md', "---\nslug: /\n---\n# Home\n");

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reserved for the generated homepage');

        $loader->loadPages();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function frontMatterVariants(): iterable
    {
        yield 'windows line endings' => ["---\r\ntitle: Parsed\r\n---\r\n# Body\r\n", 'Parsed', '<h1 id="body">Body</h1>'];
        yield 'old mac line endings' => ["---\rtitle: Parsed\r---\r# Body\r", 'Parsed', '<h1 id="body">Body</h1>'];
        yield 'utf-8 byte order mark' => ["\u{FEFF}---\ntitle: Parsed\n---\n# Body\n", 'Parsed', '<h1 id="body">Body</h1>'];
        yield 'trailing whitespace on delimiters' => ["--- \t\ntitle: Parsed\n---  \n# Body\n", 'Parsed', '<h1 id="body">Body</h1>'];
        yield 'closing delimiter at end of file' => ["---\ntitle: Parsed\n---", 'Parsed', ''];
        yield 'empty front matter' => ["---\n---\n# Body\n", 'Variant', '<h1 id="body">Body</h1>'];
    }

    #[Test]
    #[DataProvider('frontMatterVariants')]
    public function itParsesFrontMatterVariants(string $source, string $expectedTitle, string $expectedHtml): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/variant.md', $source);

        $loader = $this->createLoader();
        $pages = $loader->loadPages();

        self::assertCount(1, $pages);
        self::assertSame($expectedTitle, $pages[0]->title);
        self::assertSame($expectedHtml, trim($pages[0]->html));
    }

    #[Test]
    public function itThrowsOnMissingClosingFrontMatterDelimiter(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/unclosed.md', "---\ntitle: Unclosed\n# Body\n");

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid front matter in "unclosed.md": missing closing delimiter.');

        $loader->loadPages();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function derivedExcerpts(): iterable
    {
        yield 'special characters' => ["Tom & Jerry say \"hi\" <3\n\nThe end.", 'Tom & Jerry say "hi" <3 The end.'];
        yield 'html entities' => ['Fish &amp; chips &lt;tasty&gt;', 'Fish & chips <tasty>'];
        yield 'whitespace across blocks' => ["# Title\n\nFirst   line\nwrapped.\n\n- one\n- two", 'Title First line wrapped. one two'];
        yield 'adjacent blocks' => ["Intro.\n```php\n\$a = 1;\n```", 'Intro. $a = 1;'];
        yield 'long text is truncated' => [str_repeat('word ', 60), rtrim(mb_substr(trim(str_repeat('word ', 60)), 0, 217)).'...'];
        yield 'empty body' => ['', ''];
    }

    #[Test]
    #[DataProvider('derivedExcerpts')]
    public function itDerivesThePlainTextExcerptFromTheRenderedBody(string $markdown, string $expectedExcerpt): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/excerpt.md', $markdown);

        $loader = $this->createLoader();
        $pages = $loader->loadPages();

        self::assertSame($expectedExcerpt, $pages[0]->excerpt);
    }

    #[Test]
    public function itParsesAliasesFromFrontMatter(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/a-list.md', "---\naliases: ['/Old-Post/', /old.html, /Old-Post]\n---\n# List");
        file_put_contents($this->projectDir.'/content/b-string.md', "---\naliases: /previous-name\n---\n# String");
        file_put_contents($this->projectDir.'/content/c-none.md', '# None');

        $pages = $this->createLoader()->loadPages();

        self::assertSame(['/Old-Post/', '/old.html'], $pages[0]->aliases);
        self::assertSame(['/previous-name/'], $pages[1]->aliases);
        self::assertSame([], $pages[2]->aliases);
        self::assertSame([], $pages[0]->meta);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidAliasFrontMatter(): iterable
    {
        yield 'map' => ["aliases:\n  old: /old/", 'Front matter key "aliases" in "post.md" must be a list of URL paths.'];
        yield 'number in list' => ['aliases: [/old/, 42]', 'Front matter key "aliases" in "post.md" must be a list of URL paths.'];
        yield 'relative path' => ['aliases: [old/]', 'Alias "old/" in "post.md" must be a URL path starting with "/".'];
        yield 'unquoted date' => ['aliases: 2021-01-01', 'Front matter key "aliases" in "post.md" must be a list of URL paths.'];
        yield 'unquoted date in list' => ['aliases: [/old/, 2021-01-01]', 'Front matter key "aliases" in "post.md" must be a list of URL paths.'];
        yield 'trailing dot' => ['aliases: [/a./]', 'Alias "/a./" in "post.md" may only contain letters, digits, ".", "_", "~" and "-" between slashes, with no segment ending in ".".'];
        yield 'segment of only dots' => ['aliases: [/.../]', 'Alias "/.../" in "post.md" may only contain letters, digits, ".", "_", "~" and "-" between slashes, with no segment ending in ".".'];
        yield 'trailing dot in a nested segment' => ['aliases: [/old/post./]', 'Alias "/old/post./" in "post.md" may only contain letters, digits, ".", "_", "~" and "-" between slashes, with no segment ending in ".".'];
        yield 'line break in a double-quoted alias' => ['aliases: ["/old\\n/post/"]', "Alias \"/old\n/post/\" in \"post.md\" may only contain letters, digits, \".\", \"_\", \"~\" and \"-\" between slashes, with no segment ending in \".\"."];
    }

    #[Test]
    #[DataProvider('invalidAliasFrontMatter')]
    public function itRejectsInvalidAliases(string $frontMatter, string $expectedMessage): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/post.md', "---\n".$frontMatter."\n---\n# Post");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->createLoader()->loadPages();
    }

    #[Test]
    public function itThrowsOnInvalidYamlFrontMatter(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/broken.md', "---\ntitle: [invalid\n---\nbody");

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid YAML front matter in "broken.md"');

        $loader->loadPages();
    }

    #[Test]
    public function itThrowsOnInvalidDateFrontMatter(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/broken-date.md', "---\ndate: not-a-date\n---\n# body");

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Front matter key "date" in "broken-date.md" must be a valid date string.');

        $loader->loadPages();
    }

    #[Test]
    public function itThrowsOnEmptyDateFrontMatter(): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/post.md', "---\ndate: '  '\n---\n# Post");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Front matter key "date" in "post.md" must be a non-empty string.');

        $this->createLoader()->loadPages();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dateFrontMatterThatIsNotADate(): iterable
    {
        yield 'year' => ['date: 2021', '2021'];
        yield 'Unix timestamp' => ['date: 1700000000', '1700000000'];
        yield 'float' => ['date: 2021.5', '2021.5'];
        yield 'boolean' => ['date: true', 'true'];
        yield 'list' => ['date: [2021-01-01]', 'a list'];
        yield 'map' => ['date: {a: 1}', 'a map'];
    }

    #[Test]
    #[DataProvider('dateFrontMatterThatIsNotADate')]
    public function itRejectsDateFrontMatterThatIsNotADate(string $frontMatter, string $expectedValue): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/post.md', "---\n".$frontMatter."\n---\n# Post");

        try {
            $this->createLoader()->loadPages();
            self::fail('Expected the date to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Front matter key "date" in "post.md" must be a date such as 2021-08-27, got '.$expectedValue.'.', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function datesWithoutOffset(): iterable
    {
        yield 'date' => ['2021-08-27', '2021-08-27 00:00:00'];
        yield 'date-time' => ['2021-08-27 10:30:00', '2021-08-27 10:30:00'];
        yield 'ISO 8601 date-time' => ['2021-08-27T10:30:00', '2021-08-27 10:30:00'];
    }

    #[Test]
    #[DataProvider('datesWithoutOffset')]
    public function itReadsAnUnquotedDateWithoutOffsetInTheDefaultTimeZoneLikeAQuotedOne(string $date, string $expectedLocalTime): void
    {
        date_default_timezone_set('America/New_York');
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/a-unquoted.md', "---\ndate: ".$date."\n---\n# Unquoted");
        file_put_contents($this->projectDir.'/content/b-quoted.md', "---\ndate: '".$date."'\n---\n# Quoted");

        $pages = $this->createLoader()->loadPages();

        self::assertSame($expectedLocalTime.' America/New_York', $pages[0]->publishedAt?->format('Y-m-d H:i:s e'));
        self::assertSame($pages[1]->publishedAt?->format('Y-m-d H:i:s e'), $pages[0]->publishedAt->format('Y-m-d H:i:s e'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function datesWithOffset(): iterable
    {
        yield 'numeric offset' => ['2021-08-27T01:00:00+02:00', '2021-08-26T23:00:00+00:00'];
        yield 'Z suffix' => ['2021-08-27T10:00:00Z', '2021-08-27T10:00:00+00:00'];
    }

    #[Test]
    #[DataProvider('datesWithOffset')]
    public function itKeepsTheInstantOfAnUnquotedDateWithAnOffset(string $date, string $expectedUtcTime): void
    {
        date_default_timezone_set('America/New_York');
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/a-unquoted.md', "---\ndate: ".$date."\n---\n# Unquoted");
        file_put_contents($this->projectDir.'/content/b-quoted.md', "---\ndate: '".$date."'\n---\n# Quoted");

        $pages = $this->createLoader()->loadPages();

        self::assertSame($expectedUtcTime, $pages[0]->publishedAt?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));
        self::assertSame($pages[1]->publishedAt?->getTimestamp(), $pages[0]->publishedAt->getTimestamp());
    }

    #[Test]
    public function itKeepsTheFractionalSecondsOfAnUnquotedDate(): void
    {
        date_default_timezone_set('America/New_York');
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/post.md', "---\ndate: 2021-08-27 10:00:00.5\n---\n# Post");

        $pages = $this->createLoader()->loadPages();

        self::assertSame('2021-08-27 10:00:00.500000 America/New_York', $pages[0]->publishedAt?->format('Y-m-d H:i:s.u e'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function reservedKeysWithAnUnquotedDate(): iterable
    {
        yield 'title' => ['title', 'must be a non-empty string.'];
        yield 'slug' => ['slug', 'must be a non-empty string.'];
        yield 'excerpt' => ['excerpt', 'must be a non-empty string.'];
        yield 'draft' => ['draft', 'must be a boolean.'];
    }

    #[Test]
    #[DataProvider('reservedKeysWithAnUnquotedDate')]
    public function itRejectsAnUnquotedDateInAReservedKeyThatIsNotADate(string $key, string $expectedRequirement): void
    {
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/post.md', "---\n".$key.": 2021-01-01\n---\n# Post");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Front matter key "'.$key.'" in "post.md" '.$expectedRequirement);

        $this->createLoader()->loadPages();
    }

    #[Test]
    #[DataProvider('datesWithoutOffset')]
    public function itReadsAnUnquotedMetaDateWithoutOffsetInTheDefaultTimeZone(string $date, string $expectedLocalTime): void
    {
        date_default_timezone_set('America/New_York');
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/post.md', "---\nupdated: ".$date."\n---\n# Post");

        $updated = $this->createLoader()->loadPages()[0]->meta['updated'];

        self::assertIsInt($updated);
        self::assertSame($expectedLocalTime, date('Y-m-d H:i:s', $updated));
    }

    #[Test]
    public function itConvertsUnquotedMetaDatesAtAnyDepth(): void
    {
        date_default_timezone_set('America/New_York');
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents(
            $this->projectDir.'/content/post.md',
            "---\nupdated: 2026-04-01\nrevisions: [2026-01-15, 2026-02-20 08:30:00]\nreview:\n  by: Jane\n  due: 2026-05-01\n  rounds:\n    - at: 2026-06-01\n---\n# Post",
        );

        $meta = $this->createLoader()->loadPages()[0]->meta;

        self::assertSame('April 1, 2026', date('F j, Y', $meta['updated']));
        self::assertSame([
            'updated' => strtotime('2026-04-01 00:00:00'),
            'revisions' => [strtotime('2026-01-15 00:00:00'), strtotime('2026-02-20 08:30:00')],
            'review' => [
                'by' => 'Jane',
                'due' => strtotime('2026-05-01 00:00:00'),
                'rounds' => [['at' => strtotime('2026-06-01 00:00:00')]],
            ],
        ], $meta);
    }

    #[Test]
    #[DataProvider('datesWithOffset')]
    public function itKeepsTheInstantOfAnUnquotedMetaDateWithAnOffset(string $date, string $expectedUtcTime): void
    {
        date_default_timezone_set('America/New_York');
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/post.md', "---\nupdated: ".$date."\n---\n# Post");

        $pages = $this->createLoader()->loadPages();

        self::assertSame((new \DateTimeImmutable($expectedUtcTime))->getTimestamp(), $pages[0]->meta['updated']);
    }

    #[Test]
    public function itKeepsTheFractionalSecondsOfAnUnquotedMetaDate(): void
    {
        date_default_timezone_set('America/New_York');
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents($this->projectDir.'/content/post.md', "---\nupdated: 2021-08-27 10:00:00.25\n---\n# Post");

        $updated = $this->createLoader()->loadPages()[0]->meta['updated'];

        self::assertIsFloat($updated);
        self::assertSame(strtotime('2021-08-27 10:00:00') + 0.25, $updated);
    }

    #[Test]
    public function itKeepsMetaValuesThatAreNotUnquotedDates(): void
    {
        date_default_timezone_set('America/New_York');
        mkdir($this->projectDir.'/content', 0777, true);
        file_put_contents(
            $this->projectDir.'/content/post.md',
            "---\nauthor: Jane\nsingle: '2026-04-01'\ndouble: \"2026-04-01 10:30:00\"\nyear: 2026\nrating: 4.5\nfeatured: true\nseries: ~\ntags: [php, '2026-04-01', 3]\n---\n# Post",
        );

        $pages = $this->createLoader()->loadPages();

        self::assertSame([
            'author' => 'Jane',
            'single' => '2026-04-01',
            'double' => '2026-04-01 10:30:00',
            'year' => 2026,
            'rating' => 4.5,
            'featured' => true,
            'series' => null,
            'tags' => ['php', '2026-04-01', 3],
        ], $pages[0]->meta);
    }

    private function removeDirectory(string $path): void
    {
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $entryPath = $path.'/'.$entry;

            if (is_dir($entryPath)) {
                $this->removeDirectory($entryPath);

                continue;
            }

            unlink($entryPath);
        }

        rmdir($path);
    }

    private function createLoader(): ContentLoader
    {
        return new ContentLoader($this->projectDir, new OutputPathResolver());
    }
}
