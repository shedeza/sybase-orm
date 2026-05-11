<?php

declare(strict_types=1);

namespace SybaseORM;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use SybaseORM\DependencyInjection\RepositoryAutowiringCompilerPass;

class SybaseORMBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RepositoryAutowiringCompilerPass());
    }
}
