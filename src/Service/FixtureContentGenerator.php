<?php

declare(strict_types=1);

namespace Staccato\Service;

use Faker\Factory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final readonly class FixtureContentGenerator
{
    private const FIXTURES_CONTENT_DIR = 'var/fixtures/content';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private Filesystem $filesystem,
    ) {
    }

    public function generate(int $articles = 35): string
    {
        if ($articles < 1) {
            throw new \RuntimeException('Fixture article count must be at least 1.');
        }

        $fixturesRoot = $this->projectDir.'/'.self::FIXTURES_CONTENT_DIR;
        $articlesPath = $fixturesRoot.'/articles';
        $this->filesystem->remove($fixturesRoot);
        $this->filesystem->mkdir($articlesPath);

        $faker = Factory::create();

        /** @var \Closure(int): string $sentence */
        $sentence = static fn (int $words): string => (string) $faker->sentence($words);
        /** @var \Closure(int): array<int, string> $sentences */
        $sentences = static fn (int $count): array => array_map(
            static fn ($item): string => (string) $item,
            (array) $faker->sentences($count),
        );

        for ($index = 1; $index <= $articles; ++$index) {
            $title = rtrim($sentence(6), '.');
            $excerpt = $sentence(18);
            $date = (new \DateTimeImmutable('today'))->sub(new \DateInterval(sprintf('P%dD', $index * 9)));
            $slug = sprintf('article-%02d', $index);

            $markdown = implode("\n", [
                '---',
                'title: '.$title,
                'excerpt: '.$excerpt,
                'date: '.$date->format('Y-m-d'),
                '---',
                '',
                $this->paragraph($sentences),
                '',
                $this->paragraph($sentences),
                '',
                '## Notes',
                '',
                $this->paragraph($sentences),
                '',
                $this->paragraph($sentences),
                '',
            ]);

            $this->filesystem->dumpFile($articlesPath.'/'.$slug.'.md', $markdown);
        }

        return self::FIXTURES_CONTENT_DIR;
    }

    /**
     * @param \Closure(int): array<int, string> $sentences
     */
    private function paragraph(\Closure $sentences): string
    {
        return implode(' ', $sentences(4));
    }
}
