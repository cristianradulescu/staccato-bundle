<?php

declare(strict_types=1);

namespace Staccato;

use Staccato\Model\SiteConfig;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class StaccatoBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('title')
                    ->cannotBeEmpty()
                    ->defaultValue('Staccato')
                ->end()
                ->scalarNode('base_url')
                    ->cannotBeEmpty()
                    ->defaultValue('http://localhost')
                    ->validate()
                        ->ifTrue(static fn (string $value): bool => false === filter_var($value, FILTER_VALIDATE_URL))
                        ->thenInvalid('The "staccato.base_url" value "%s" must be a valid URL.')
                    ->end()
                ->end()
                ->scalarNode('description')
                    ->cannotBeEmpty()
                    ->defaultValue('Static site generated with Staccato.')
                ->end()
                ->integerNode('articles_per_page')
                    ->min(1)
                    ->defaultValue(10)
                ->end()
                ->scalarNode('footer_text')
                    ->cannotBeEmpty()
                    ->defaultValue('Built with Staccato')
                ->end()
                ->booleanNode('include_default_styles')
                    ->defaultTrue()
                ->end()
                ->arrayNode('stylesheets')
                     ->prototype('scalar')->end()
                     ->defaultValue([])
                ->end()
                ->scalarNode('highlighter_css')
                     ->defaultValue(null)
                ->end()
            ->end()
            ->append(self::projectDirectoryNode('content_dir', 'content'))
            ->append(self::projectDirectoryNode('build_dir', 'build'))
            ->append(self::projectDirectoryNode('assets_dir', 'assets'))
            ->validate()
                ->always(static function (array $config): array {
                    // build_dir is removed on every clean build, so it must never contain or sit inside the sources.
                    foreach (['content_dir', 'assets_dir'] as $sourceKey) {
                        $build = $config['build_dir'].'/';
                        $source = $config[$sourceKey].'/';

                        if (str_starts_with($build, $source) || str_starts_with($source, $build)) {
                            throw new \InvalidArgumentException(sprintf('The "staccato.build_dir" value "%s" must not overlap with "staccato.%s" ("%s"), because the build directory is removed on every clean build.', $config['build_dir'], $sourceKey, $config[$sourceKey]));
                        }
                    }

                    return $config;
                })
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.yaml');

        $builder->setParameter('staccato.default_assets_dir', $this->getPath().'/public');

        $builder->register(SiteConfig::class)
            ->setArguments([
                trim((string) $config['title']),
                rtrim(trim((string) $config['base_url']), '/'),
                trim((string) $config['description']),
                (int) $config['articles_per_page'],
                trim((string) $config['footer_text']),
                (bool) $config['include_default_styles'],
                (array) $config['stylesheets'],
                $config['highlighter_css'],
                $config['content_dir'],
                $config['build_dir'],
                $config['assets_dir'],
            ]);
    }

    /**
     * A directory relative to %kernel.project_dir%, normalized without leading/trailing slashes.
     */
    private static function projectDirectoryNode(string $name, string $default): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($name);
        $node
            ->cannotBeEmpty()
            ->defaultValue($default)
            ->validate()
                ->always(static function (mixed $value) use ($name): string {
                    $normalized = str_replace('\\', '/', trim((string) $value));
                    $segments = explode('/', trim($normalized, '/'));
                    $isAbsolute = str_starts_with($normalized, '/') || 1 === preg_match('#^[a-zA-Z]:/#', $normalized);

                    if ($isAbsolute || [] !== array_intersect($segments, ['', '.', '..'])) {
                        throw new \InvalidArgumentException(sprintf('The "staccato.%s" value "%s" must be a path relative to the project directory, without "." or ".." segments.', $name, $value));
                    }

                    return implode('/', $segments);
                })
            ->end();

        return $node;
    }
}
