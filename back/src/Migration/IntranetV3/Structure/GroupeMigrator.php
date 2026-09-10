<?php

namespace App\Migration\IntranetV3\Structure;

use App\Entity\Structure\StructureGroupe;
use App\Entity\Structure\StructureSemestre;
use App\Enum\TypeGroupeEnum;
use App\Migration\IntranetV3\AbstractMigrator;
use App\Migration\IntranetV3\Contract\MigratorInterface;
use App\Migration\IntranetV3\MigrationContext;
use App\Migration\IntranetV3\MigrationResult;

final class GroupeMigrator extends AbstractMigrator
{
    public function getName(): string
    {
        return 'groupes';
    }

    /** @return list<class-string<MigratorInterface>> */
    public function getDependencies(): array
    {
        return [SemestreMigrator::class];
    }

    public function migrate(MigrationContext $context): MigrationResult
    {
        $rows = $this->source->fetchAllAssociative(<<<'SQL'
            SELECT g.id, g.type_groupe_id, g.parent_id, g.libelle, g.code_apogee, g.ordre,
                   tg.type, tg.semestre_id
            FROM groupe g
            INNER JOIN type_groupe tg ON tg.id = g.type_groupe_id
            ORDER BY g.id
            SQL);

        $repository = $this->entityManager->getRepository(StructureGroupe::class);
        $semestreRepository = $this->entityManager->getRepository(StructureSemestre::class);
        $created = $updated = $failed = 0;
        $messages = [];

        foreach ($rows as $row) {
            try {
                $entity = $repository->findOneBy(['oldId' => (int) $row['id']]);
                $isNew = null === $entity;
                $entity ??= new StructureGroupe();

                $type = TypeGroupeEnum::tryFrom((string) $row['type']) ?? TypeGroupeEnum::TYPE_GROUPE_AUTRE;
                $entity
                    ->setOldId((int) $row['id'])
                    ->setLibelle((string) $row['libelle'])
                    ->setType($type)
                    ->setOrdre(null !== $row['ordre'] ? (int) $row['ordre'] : null)
                    ->setCodeApogee($row['code_apogee'] ?: null);

                if ($isNew) {
                    $this->entityManager->persist($entity);
                    ++$created;
                } else {
                    ++$updated;
                }
            } catch (\Throwable $e) {
                ++$failed;
                $messages[] = sprintf('Groupe #%s: %s', $row['id'], $e->getMessage());
            }
        }

        // Relations parent/enfant après création de tous les groupes.
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

        // En V3 la relation groupe/semestre est portée par TypeGroupe.
        $schemaManager = $this->source->createSchemaManager();
        if ($schemaManager->tablesExist(['type_groupe_semestre'])) {
            $links = $this->source->fetchAllAssociative(
                'SELECT g.id AS groupe_id, tgs.semestre_id FROM groupe g INNER JOIN type_groupe_semestre tgs ON tgs.type_groupe_id = g.type_groupe_id'
            );
        } else {
            $links = array_values(array_filter(array_map(
                static fn (array $row): ?array => null === $row['semestre_id'] ? null : ['groupe_id' => $row['id'], 'semestre_id' => $row['semestre_id']],
                $rows
            )));
        }

        foreach ($links as $link) {
            $groupe = $repository->findOneBy(['oldId' => (int) $link['groupe_id']]);
            $semestre = $semestreRepository->findOneBy(['oldId' => (int) $link['semestre_id']]);
            if ($groupe && $semestre) {
                $groupe->addSemestre($semestre);
            }
        }

        $this->flush($context);

        return new MigrationResult($created, $updated, 0, $failed, $messages);
    }
}
