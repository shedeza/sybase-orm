<?php

declare(strict_types=1);

namespace SybaseORM\Bundle;

use SybaseORM\Bundle\DependencyInjection\RepositoryAutowiringCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class SybaseORMBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RepositoryAutowiringCompilerPass());
    }
}
