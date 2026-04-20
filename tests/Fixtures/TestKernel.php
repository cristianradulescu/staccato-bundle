<?php

declare(strict_types=1);

namespace Staccato\Tests\Fixtures;

use Staccato\StaccatoBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    /**
     * @param array<string, mixed> $staccatoConfig
     */
    public function __construct(
        string $environment,
        private readonly array $staccatoConfig = [],
    ) {
        parent::__construct($environment, false);
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

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/staccato-bundle-test-cache/'.spl_object_hash($this);
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/staccato-bundle-test-log';
    }
}
