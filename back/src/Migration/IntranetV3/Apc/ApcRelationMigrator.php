<?php

namespace App\Migration\IntranetV3\Apc;

use App\Entity\Scolarite\ScolEnseignement;
use App\Entity\Structure\StructureSemestre;
use App\Enum\TypeEnseignementEnum;
use App\Migration\IntranetV3\AbstractMigrator;
use App\Migration\IntranetV3\Contract\MigratorInterface;
use App\Migration\IntranetV3\MigrationContext;
use App\Migration\IntranetV3\MigrationResult;

final class ApcRelationMigrator extends AbstractMigrator
{
    public function getName(): string
    {
        return 'apc-relations';
    }

    /** @return list<class-string<MigratorInterface>> */
    public function getDependencies(): array
    {
        return [ApcRessourceMigrator::class, ApcSaeMigrator::class];
    }

    public function migrate(MigrationContext $context): MigrationResult
    {
        $created = 0;
        $updated = $skipped = $failed = 0;
        $messages = [];

        $resourceSemesterTable = $this->resolveJoinTable(['apc_ressource_semestre', 'apc_ressource_semestre_semestre']);
        $saeSemesterTable = $this->resolveJoinTable(['apc_sae_semestre', 'apc_sae_semestre_semestre']);

        foreach ($this->entityManager->getRepository(StructureSemestre::class)->findAll() as $semestre) {
            $semestreOldId = $semestre->getOldId();
            if (null === $semestreOldId) {
                continue;
            }

            try {
                if (null !== $resourceSemesterTable) {
                    $this->linkResourceParents($semestre, $semestreOldId, $resourceSemesterTable, $updated, $skipped, $messages);
                }

                if (null !== $resourceSemesterTable && null !== $saeSemesterTable) {
                    $this->linkSaeResources($semestre, $semestreOldId, $resourceSemesterTable, $saeSemesterTable, $updated, $skipped, $messages);
                }
            } catch (\Throwable $e) {
                ++$failed;
                if (count($messages) < 20) {
                    $messages[] = sprintf('Relations APC / semestre V3 #%s: %s', $semestreOldId, $e->getMessage());
                }
            }

            $this->flush($context);
        }

        $this->flushAndClear($context);

        return new MigrationResult($created, $updated, $skipped, $failed, $messages);
    }

    /** @param list<string> $messages */
    private function linkResourceParents(
        StructureSemestre $semestre,
        int $semestreOldId,
        string $resourceSemesterTable,
        int &$updated,
        int &$skipped,
        array &$messages,
    ): void {
        $sql = sprintf(<<<'SQL'
SELECT re.apc_ressource_parent_id AS parent_id, re.apc_ressource_enfant_id AS child_id
FROM apc_ressource_enfants re
INNER JOIN %1$s rsp ON rsp.apc_ressource_id = re.apc_ressource_parent_id AND rsp.semestre_id = :semestre_id
INNER JOIN %1$s rsc ON rsc.apc_ressource_id = re.apc_ressource_enfant_id AND rsc.semestre_id = :semestre_id
ORDER BY re.id
SQL, $resourceSemesterTable);

        foreach ($this->source->fetchAllAssociative($sql, ['semestre_id' => $semestreOldId]) as $row) {
            $parent = $this->findTeaching((int) $row['parent_id'], TypeEnseignementEnum::TYPE_RESSOURCE, $semestre);
            $child = $this->findTeaching((int) $row['child_id'], TypeEnseignementEnum::TYPE_RESSOURCE, $semestre);

            if (null === $parent || null === $child) {
                ++$skipped;
                continue;
            }

            $child->setParent($parent);
            ++$updated;
        }
    }

    /** @param list<string> $messages */
    private function linkSaeResources(
        StructureSemestre $semestre,
        int $semestreOldId,
        string $resourceSemesterTable,
        string $saeSemesterTable,
        int &$updated,
        int &$skipped,
        array &$messages,
    ): void {
        $sql = sprintf(<<<'SQL'
SELECT sr.sae_id, sr.ressource_id
FROM apc_sae_ressource sr
INNER JOIN %1$s ss ON ss.apc_sae_id = sr.sae_id AND ss.semestre_id = :semestre_id
INNER JOIN %2$s rs ON rs.apc_ressource_id = sr.ressource_id AND rs.semestre_id = :semestre_id
ORDER BY sr.id
SQL, $saeSemesterTable, $resourceSemesterTable);

        $seenResources = [];
        foreach ($this->source->fetchAllAssociative($sql, ['semestre_id' => $semestreOldId]) as $row) {
            $resourceOldId = (int) $row['ressource_id'];
            if (isset($seenResources[$resourceOldId]) && $seenResources[$resourceOldId] !== (int) $row['sae_id']) {
                ++$skipped;
                if (count($messages) < 20) {
                    $messages[] = sprintf(
                        'Ressource APC V3 #%d / semestre #%d liée à plusieurs SAÉ: relation supplémentaire ignorée.',
                        $resourceOldId,
                        $semestreOldId,
                    );
                }
                continue;
            }

            $resource = $this->findTeaching($resourceOldId, TypeEnseignementEnum::TYPE_RESSOURCE, $semestre);
            $sae = $this->findTeaching((int) $row['sae_id'], TypeEnseignementEnum::TYPE_SAE, $semestre);

            if (null === $resource || null === $sae) {
                ++$skipped;
                continue;
            }

            $resource->setSae($sae);
            $seenResources[$resourceOldId] = (int) $row['sae_id'];
            ++$updated;
        }
    }

    private function findTeaching(int $oldId, TypeEnseignementEnum $type, StructureSemestre $semestre): ?ScolEnseignement
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(ScolEnseignement::class, 'e')
            ->innerJoin('e.enseignementUes', 'eu')
            ->innerJoin('eu.ue', 'ue')
            ->andWhere('e.oldId = :oldId')
            ->andWhere('e.type = :type')
            ->andWhere('ue.semestre = :semestre')
            ->setParameter('oldId', $oldId)
            ->setParameter('type', $type)
            ->setParameter('semestre', $semestre)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @param list<string> $candidates */
    private function resolveJoinTable(array $candidates): ?string
    {
        $schema = $this->source->createSchemaManager();
        foreach ($candidates as $candidate) {
            if ($schema->tablesExist([$candidate])) {
                return $candidate;
            }
        }

        return null;
    }
}
