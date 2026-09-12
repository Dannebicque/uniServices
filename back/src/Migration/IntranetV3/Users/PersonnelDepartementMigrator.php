<?php

namespace App\Migration\IntranetV3\Users;

use App\Entity\Structure\StructureDepartement;
use App\Entity\Structure\StructureDepartementPersonnel;
use App\Entity\Users\Personnel;
use App\Migration\IntranetV3\AbstractMigrator;
use App\Migration\IntranetV3\MigrationContext;
use App\Migration\IntranetV3\MigrationResult;
use App\Migration\IntranetV3\Structure\DepartementMigrator;

final class PersonnelDepartementMigrator extends AbstractMigrator
{
    public function getName(): string
    {
        return 'personnel-departements';
    }

    public function getDependencies(): array
    {
        return [PersonnelMigrator::class, DepartementMigrator::class];
    }

    public function migrate(MigrationContext $context): MigrationResult
    {
        $created = $updated = $skipped = $failed = 0;
        $messages = [];
        $personnelRepository = $this->entityManager->getRepository(Personnel::class);
        $departementRepository = $this->entityManager->getRepository(StructureDepartement::class);
        $repository = $this->entityManager->getRepository(StructureDepartementPersonnel::class);

        foreach ($this->source->fetchAllAssociative('SELECT id, personnel_id, departement_id, roles, defaut FROM personnel_departement ORDER BY id') as $row) {
            try {
                $personnel = $personnelRepository->findOneBy(['oldId' => (int) $row['personnel_id']]);
                $departement = $departementRepository->findOneBy(['oldId' => (int) $row['departement_id']]);
                if (null === $personnel || null === $departement) {
                    ++$skipped;
                    $messages[] = sprintf('PersonnelDepartement #%s ignoré: relation source non résolue.', $row['id']);
                    continue;
                }

                $entity = $repository->findOneBy(['personnel' => $personnel, 'departement' => $departement]);
                $isNew = null === $entity;
                $entity ??= new StructureDepartementPersonnel();

                $permissions = [];
                if (!empty($row['roles'])) {
                    $decoded = json_decode((string) $row['roles'], true);
                    if (is_array($decoded)) {
                        $permissions = array_values(array_unique(array_map('strval', $decoded)));
                    }
                }

                $entity
                    ->setPersonnel($personnel)
                    ->setDepartement($departement)
                    ->setDefaut((bool) $row['defaut'])
                    ->setPackages(['intranet'])
                    ->setPermissions($permissions)
                    ->setAffectation(true);

                if ($isNew) {
                    $this->entityManager->persist($entity);
                    ++$created;
                } else {
                    ++$updated;
                }
            } catch (\Throwable $e) {
                ++$failed;
                $messages[] = sprintf('PersonnelDepartement #%s: %s', $row['id'], $e->getMessage());
            }
        }

        $this->flush($context);

        return new MigrationResult($created, $updated, $skipped, $failed, $messages);
    }
}
