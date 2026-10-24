<?php

declare(strict_types=1);

namespace Staccato\Command;

use Staccato\Model\SiteConfig;
use Staccato\Service\SiteBuilderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'site:build',
    description: 'Build the static site from Markdown content.',
)]
final class SiteBuildCommand extends Command
{
    public function __construct(
        private readonly SiteBuilderInterface $siteBuilder,
        private readonly SiteConfig $siteConfig,
        #[Autowire('%kernel.environment%')]
        private readonly string $kernelEnvironment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'clean',
                null,
                InputOption::VALUE_NEGATABLE,
                'Clean output directory before building.',
                true,
            )
            ->addOption(
                'use-fixtures',
                null,
                InputOption::VALUE_NONE,
                'Generate and build fixture content (35 Faker-based articles) instead of using the content directory.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $clean = (bool) $input->getOption('clean');
        $useFixtures = (bool) $input->getOption('use-fixtures');
        $includeDrafts = 'prod' !== $this->kernelEnvironment;

        try {
            $result = $this->siteBuilder->build(
                clean: $clean,
                includeDrafts: $includeDrafts,
                useFixtures: $useFixtures,
            );
        } catch (\Throwable $exception) {
            // Let the Console application render the full exception (trace and previous exceptions) on -v and above.
            if ($output->isVerbose()) {
                throw $exception;
            }

            $io->error([$exception->getMessage(), 'Run the command with -v to see the full stack trace.']);

            return Command::FAILURE;
        }

        $io->success('Static site build completed.');
        $io->definitionList(
            ['Environment' => $this->kernelEnvironment],
            ['Output cleaned' => $clean ? 'yes' : 'no'],
            ['Fixtures mode' => $useFixtures ? 'yes' : 'no'],
            ['Draft pages included' => $includeDrafts ? 'yes' : 'no'],
            ['Detail pages generated' => (string) $result->generatedPages],
            ['Draft pages skipped' => (string) $result->skippedDraftPages],
            ['Assets copied' => $result->assetsCopied ? 'yes' : 'no'],
            ['Duration' => $result->durationMs.'ms'],
        );

        if (0 === $result->generatedPages) {
            $io->warning(sprintf('No pages were generated. Add Markdown files under %s/.', $this->siteConfig->contentDirectory));
        }

        return Command::SUCCESS;
    }
}
