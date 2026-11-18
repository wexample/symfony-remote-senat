<?php

namespace Wexample\SymfonyRemoteSenat\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Wexample\SymfonyRemoteSenat\Service\SenatDebatesClient;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('wexample_symfony_remote_senat');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('cache_dir')
                    ->defaultValue('%kernel.project_dir%/var/remote/senat')
                    ->info('Where the downloaded archive is unpacked and kept: out of the kernel\'s cache, which a deployment empties.')
                ->end()
                ->arrayNode('debates')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('url')->defaultValue(SenatDebatesClient::DEFAULT_URL)->end()
                        ->scalarNode('calendar_url')->defaultValue(SenatDebatesClient::DEFAULT_CALENDAR_URL)->info('The base of the debates, which dates the reports.')->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
