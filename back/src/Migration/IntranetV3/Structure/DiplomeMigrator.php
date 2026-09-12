<?php

namespace App\Migration\IntranetV3\Structure;

use App\Entity\Structure\StructureDepartement;
use App\Entity\Structure\StructureDiplome;
use App\Migration\IntranetV3\AbstractMigrator;
use App\Migration\IntranetV3\Contract\MigratorInterface;
use App\Migration\IntranetV3\MigrationContext;
use App\Migration\IntranetV3\MigrationResult;

final class DiplomeMigrator extends AbstractMigrator
{
    public function getName(): string
    {
        return 'diplomes';
    }

    /** @return list<class-string<MigratorInterface>> */
    public function getDependencies(): array
    {
        return [DepartementMigrator::class];
    }

    public function migrate(MigrationContext $context): MigrationResult
    {
        $rows = $this->source->fetchAllAssociative(
            'SELECT id, departement_id, parent_id, libelle, volume_horaire, code_celcat_departement, sigle, actif, logo_partenaire, key_edu_sign FROM diplome ORDER BY id'
        );
        $repository = $this->entityManager->getRepository(StructureDiplome::class);
        $departementRepository = $this->entityManager->getRepository(StructureDepartement::class);
        $created = $updated = $skipped = $failed = 0;
        $messages = [];

        foreach ($rows as $row) {
            try {
                $departement = $departementRepository->findOneBy(['oldId' => (int) $row['departement_id']]);
                if (!$departement) {
                    ++$skipped;
                    $messages[] = sprintf('Diplome #%s skipped: departement V3 #%s introuvable.', $row['id'], $row['departement_id']);
                    continue;
                }

                $entity = $repository->findOneBy(['oldId' => (int) $row['id']]);
                $isNew = null === $entity;
                $entity ??= new StructureDiplome();

                $entity
                    ->setOldId((int) $row['id'])
                    ->setDepartement($departement)
                    ->setLibelle((string) $row['libelle'])
                    ->setVolumeHoraire((int) $row['volume_horaire'])
                    ->setCodeCelcatDepartement(null !== $row['code_celcat_departement'] ? (int) $row['code_celcat_departement'] : null)
                    ->setSigle($row['sigle'] ?: null)
                    ->setLogoPartenaire($row['logo_partenaire'] ?: null)
                    ->setKeyEduSign($row['key_edu_sign'] ?: null);

                if ($isNew) {
                    $this->entityManager->persist($entity);
                    ++$created;
                } else {
                    ++$updated;
                }
            } catch (\Throwable $e) {
                ++$failed;
                $messages[] = sprintf('Diplome #%s: %s', $row['id'], $e->getMessage());
            }
        }

        // Les relations parent/enfant sont faites en second passage afin de ne pas dépendre de l'ordre des ids.
        foreach ($rows as $row) {
            if (null === $row['parent_id']) {
                continue;
            }
            $entity = $repository->findOneBy(['oldId' => (int) $row['id']]);
            $parent = $repository->findOneBy(['oldId' => (int) $row['parent_id']]);
            if ($entity && $parent) {
                $entity->setParent($parent);
            }
        }

        $this->flush($context);

        return new MigrationResult($created, $updated, $skipped, $failed, $messages);
    }
}
