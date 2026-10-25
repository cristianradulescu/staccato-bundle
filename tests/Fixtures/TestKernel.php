<?php

declare(strict_types=1);

namespace Staccato\Tests\Fixtures;

use Staccato\StaccatoBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    // Unique per instance: object hashes are reused across tests and runs, and with debug off a reused
    // cache dir would silently serve a container compiled for another config or an older version of the code.
    private readonly string $cacheId;

    /**
     * @param array<string, mixed> $staccatoConfig
     * @param ?string              $projectDir     overrides the project directory, for tests that build a site
     */
    public function __construct(
        string $environment,
        private readonly array $staccatoConfig = [],
        private readonly ?string $projectDir = null,
    ) {
        $this->cacheId = bin2hex(random_bytes(8));

        parent::__construct($environment, false);
    }

    public function __destruct()
    {
        (new Filesystem())->remove([$this->getCacheDir(), $this->getLogDir()]);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new StaccatoBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $staccatoConfig = $this->staccatoConfig;

        $loader->load(static function (ContainerBuilder $container) use ($staccatoConfig): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
            ]);

            $container->loadFromExtension('twig', []);

            $container->loadFromExtension('staccato', $staccatoConfig);
        });
    }

    public function getProjectDir(): string
    {
        return $this->projectDir ?? parent::getProjectDir();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/staccato-bundle-test-cache/'.$this->cacheId;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/staccato-bundle-test-log/'.$this->cacheId;
    }
}
