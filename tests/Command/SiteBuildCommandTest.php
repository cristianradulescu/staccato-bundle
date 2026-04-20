<?php

declare(strict_types=1);

namespace Staccato\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Staccato\Command\SiteBuildCommand;
use Staccato\Model\SiteBuildResult;
use Staccato\Service\SiteBuilderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SiteBuildCommandTest extends TestCase
{
    private SiteBuilderInterface $siteBuilder;
    private MockObject $siteBuilderMock;

    protected function setUp(): void
    {
        /** @var MockObject&SiteBuilderInterface $mock */
        $mock = $this->createMock(SiteBuilderInterface::class);
        $this->siteBuilder = $mock;
        $this->siteBuilderMock = $mock;
    }

    #[Test]
    public function itBuildsAndShowsSummaryInDevEnvironment(): void
    {
        $this->siteBuilderMock
            ->expects(self::once())
            ->method('build')
            ->with(
                'content',
                'build',
                'assets',
                true,
                true,
                false,
            )
            ->willReturn(new SiteBuildResult(4, 0, true, 125));

        $command = new SiteBuildCommand($this->siteBuilder, 'dev');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Static site build completed.', $display);
        self::assertStringContainsString('Draft pages included', $display);
        self::assertStringContainsString('yes', $display);
        self::assertStringContainsString('Detail pages generated', $display);
        self::assertStringContainsString('4', $display);
    }

    #[Test]
    public function itSkipsDraftsInProdEnvironment(): void
    {
        $this->siteBuilderMock
            ->expects(self::once())
            ->method('build')
            ->with(
                'content',
                'build',
                'assets',
                true,
                false,
                false,
            )
            ->willReturn(new SiteBuildResult(2, 1, false, 80));

        $command = new SiteBuildCommand($this->siteBuilder, 'prod');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString('Draft pages included', $tester->getDisplay());
        self::assertStringContainsString('no', $tester->getDisplay());
    }

    #[Test]
    public function itSupportsDisablingCleanWithFlag(): void
    {
        $this->siteBuilderMock
            ->expects(self::once())
            ->method('build')
            ->with(
                'content',
                'build',
                'assets',
                false,
                true,
                false,
            )
            ->willReturn(new SiteBuildResult(1, 0, false, 50));

        $command = new SiteBuildCommand($this->siteBuilder, 'dev');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--no-clean' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    #[Test]
    public function itReturnsFailureWhenBuildThrows(): void
    {
        $this->siteBuilderMock
            ->expects(self::once())
            ->method('build')
            ->willThrowException(new \RuntimeException('Build failed for test'));

        $command = new SiteBuildCommand($this->siteBuilder, 'dev');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Build failed for test', $tester->getDisplay());
    }

    #[Test]
    public function itWarnsWhenNoPagesWereGenerated(): void
    {
        $this->siteBuilderMock
            ->expects(self::once())
            ->method('build')
            ->willReturn(new SiteBuildResult(0, 0, false, 30));

        $command = new SiteBuildCommand($this->siteBuilder, 'dev');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString('No pages were generated.', $tester->getDisplay());
    }

    #[Test]
    public function itCanBuildUsingGeneratedFixtures(): void
    {
        $this->siteBuilderMock
            ->expects(self::once())
            ->method('build')
            ->with(
                'content',
                'build',
                'assets',
                true,
                true,
                true,
            )
            ->willReturn(new SiteBuildResult(35, 0, false, 95));

        $command = new SiteBuildCommand($this->siteBuilder, 'dev');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--use-fixtures' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Fixtures mode', $tester->getDisplay());
        self::assertStringContainsString('yes', $tester->getDisplay());
    }
}
