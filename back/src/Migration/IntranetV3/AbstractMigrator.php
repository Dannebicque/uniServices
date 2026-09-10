<?php

namespace App\Migration\IntranetV3;

use App\Migration\IntranetV3\Contract\MigratorInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

abstract class AbstractMigrator implements MigratorInterface
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.copy_connection')]
        protected readonly Connection $source,
        protected readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getDependencies(): array
    {
        return [];
    }

    protected function flush(MigrationContext $context): void
    {
        if (!$context->dryRun) {
            $this->entityManager->flush();
        }
    }

    protected function clear(): void
    {
        $this->entityManager->clear();
    }
}
