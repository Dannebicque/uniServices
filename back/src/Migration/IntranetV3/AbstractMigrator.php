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
        // En dry-run le runner ouvre une transaction globale puis la rollback.
        // On flush tout de même afin que les migrateurs dépendants puissent
        // retrouver les entités créées précédemment dans la même exécution.
        $this->entityManager->flush();
    }

    protected function clear(): void
    {
        $this->entityManager->clear();
    }
}
