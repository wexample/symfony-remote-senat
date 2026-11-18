<?php

namespace Wexample\SymfonyRemoteSenat\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Wexample\SymfonyHelpers\DependencyInjection\AbstractWexampleSymfonyExtension;

class WexampleSymfonyRemoteSenatExtension extends AbstractWexampleSymfonyExtension
{
    public function load(
        array $configs,
        ContainerBuilder $container
    ): void {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $container->setParameter('wexample_symfony_remote_senat.cache_dir', $config['cache_dir']);
        $container->setParameter('wexample_symfony_remote_senat.debates.url', $config['debates']['url']);
        $container->setParameter('wexample_symfony_remote_senat.debates.calendar_url', $config['debates']['calendar_url']);

        $this->loadConfig(
            __DIR__,
            $container
        );
    }
}
