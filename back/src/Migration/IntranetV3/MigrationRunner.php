<?php

namespace App\Migration\IntranetV3;

use App\Migration\IntranetV3\Contract\MigratorInterface;

final class MigrationRunner
{
    public function __construct(private readonly MigrationRegistry $registry)
    {
    }

    /**
     * @return array<string, MigrationResult>
     */
    public function run(?string $name, MigrationContext $context): array
    {
        $results = [];
        $executed = [];

        if ($name === null) {
            foreach (array_keys($this->registry->all()) as $migrationName) {
                $this->runOne($migrationName, $context, $results, $executed);
            }

            return $results;
        }

        $this->runOne($name, $context, $results, $executed);

        return $results;
    }

    /**
     * @param array<string, MigrationResult> $results
     * @param array<string, true> $executed
     */
    private function runOne(string $name, MigrationContext $context, array &$results, array &$executed): void
    {
        if (isset($executed[$name])) {
            return;
        }

        $migrator = $this->registry->get($name);

        foreach ($migrator->getDependencies() as $dependencyClass) {
            $dependency = $this->findByClass($dependencyClass);
            $this->runOne($dependency->getName(), $context, $results, $executed);
        }

        $results[$name] = $migrator->migrate($context);
        $executed[$name] = true;
    }

    /**
     * @param class-string<MigratorInterface> $class
     */
    private function findByClass(string $class): MigratorInterface
    {
        foreach ($this->registry->all() as $migrator) {
            if ($migrator instanceof $class) {
                return $migrator;
            }
        }

        throw new \LogicException(sprintf('Migration dependency "%s" is not registered.', $class));
    }
}
