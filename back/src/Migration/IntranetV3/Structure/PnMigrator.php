<?php

namespace App\Migration\IntranetV3\Structure;

use App\Entity\Structure\StructureDiplome;
use App\Entity\Structure\StructurePn;
use App\Migration\IntranetV3\AbstractMigrator;
use App\Migration\IntranetV3\Contract\MigratorInterface;
use App\Migration\IntranetV3\MigrationContext;
use App\Migration\IntranetV3\MigrationResult;

final class PnMigrator extends AbstractMigrator
{
    public function getName(): string
    {
        return 'pns';
    }

    /** @return list<class-string<MigratorInterface>> */
    public function getDependencies(): array
    {
        return [DiplomeMigrator::class];
    }

    public function migrate(MigrationContext $context): MigrationResult
    {
        $rows = $this->source->fetchAllAssociative('SELECT id, diplome_id, libelle, annee FROM ppn ORDER BY id');
        $repository = $this->entityManager->getRepository(StructurePn::class);
        $diplomeRepository = $this->entityManager->getRepository(StructureDiplome::class);
        $created = $updated = $skipped = $failed = 0;
        $messages = [];

        foreach ($rows as $row) {
            try {
                $diplome = $diplomeRepository->findOneBy(['oldId' => (int) $row['diplome_id']]);
                if (!$diplome) {
                    ++$skipped;
                    $messages[] = sprintf('PN #%s skipped: diplome V3 #%s introuvable.', $row['id'], $row['diplome_id']);
                    continue;
                }

                $entity = $repository->findOneBy(['oldId' => (int) $row['id']]);
                $isNew = null === $entity;
                $entity ??= new StructurePn($diplome);

                $entity
                    ->setOldId((int) $row['id'])
                    ->setDiplome($diplome)
                    ->setLibelle((string) $row['libelle'])
                    ->setAnneePublication((int) $row['annee']);

                if ($isNew) {
                    $this->entityManager->persist($entity);
                    ++$created;
                } else {
                    ++$updated;
                }
            } catch (\Throwable $e) {
                ++$failed;
                $messages[] = sprintf('PN #%s: %s', $row['id'], $e->getMessage());
            }
        }

        $this->flush($context);

        return new MigrationResult($created, $updated, $skipped, $failed, $messages);
    }
}
