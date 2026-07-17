<?php

declare(strict_types=1);

namespace Staccato;

use Staccato\Model\SiteConfig;
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
                ->arrayNode('extra_default_styles')
                     ->prototype('scalar')->end()
                     ->defaultValue([])
                ->end()
                ->scalarNode('highlighter_css')
                     ->defaultValue(null)
                ->end()
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
                (array) $config['extra_default_styles'],
                $config['highlighter_css'],
            ]);
    }
}
