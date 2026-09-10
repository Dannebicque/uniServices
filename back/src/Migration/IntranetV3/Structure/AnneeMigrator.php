<?php

namespace App\Migration\IntranetV3\Structure;

use App\Entity\Structure\StructureAnnee;
use App\Entity\Structure\StructurePn;
use App\Migration\IntranetV3\AbstractMigrator;
use App\Migration\IntranetV3\Contract\MigratorInterface;
use App\Migration\IntranetV3\MigrationContext;
use App\Migration\IntranetV3\MigrationResult;

final class AnneeMigrator extends AbstractMigrator
{
    public function getName(): string
    {
        return 'annees';
    }

    /** @return list<class-string<MigratorInterface>> */
    public function getDependencies(): array
    {
        return [PnMigrator::class];
    }

    public function migrate(MigrationContext $context): MigrationResult
    {
        $rows = $this->source->fetchAllAssociative(<<<'SQL'
            SELECT a.id, a.diplome_id, a.libelle, a.ordre, a.libelle_long, a.actif, a.couleur,
                   a.code_version, a.code_etape,
                   COALESCE(
                       (SELECT MIN(s.ppn_actif_id) FROM semestre s WHERE s.annee_id = a.id AND s.ppn_actif_id IS NOT NULL),
                       (SELECT MAX(p.id) FROM ppn p WHERE p.diplome_id = a.diplome_id)
                   ) AS ppn_id
            FROM annee a
            ORDER BY a.id
            SQL);
        $repository = $this->entityManager->getRepository(StructureAnnee::class);
        $pnRepository = $this->entityManager->getRepository(StructurePn::class);
        $created = $updated = $skipped = $failed = 0;
        $messages = [];

        foreach ($rows as $row) {
            try {
                $pn = null === $row['ppn_id'] ? null : $pnRepository->findOneBy(['oldId' => (int) $row['ppn_id']]);
                if (!$pn) {
                    ++$skipped;
                    $messages[] = sprintf('Annee #%s skipped: aucun PN V3 exploitable pour le diplome #%s.', $row['id'], $row['diplome_id']);
                    continue;
                }

                $entity = $repository->findOneBy(['oldId' => (int) $row['id']]);
                $isNew = null === $entity;
                $entity ??= new StructureAnnee();

                $entity
                    ->setOldId((int) $row['id'])
                    ->setPn($pn)
                    ->setLibelle((string) $row['libelle'])
                    ->setOrdre((int) $row['ordre'])
                    ->setLibelleLong($row['libelle_long'] ?: null)
                    ->setActif((bool) $row['actif'])
                    ->setCouleur($row['couleur'] ?: null)
                    ->setApogeeCodeVersion($row['code_version'] ?: null)
                    ->setApogeeCodeEtape($row['code_etape'] ?: null);

                if ($isNew) {
                    $this->entityManager->persist($entity);
                    ++$created;
                } else {
                    ++$updated;
                }
            } catch (\Throwable $e) {
                ++$failed;
                $messages[] = sprintf('Annee #%s: %s', $row['id'], $e->getMessage());
            }
        }

        $this->flush($context);

        return new MigrationResult($created, $updated, $skipped, $failed, $messages);
    }
}
