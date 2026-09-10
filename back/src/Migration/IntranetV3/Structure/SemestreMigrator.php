<?php

namespace App\Migration\IntranetV3\Structure;

use App\Entity\Structure\StructureAnnee;
use App\Entity\Structure\StructureSemestre;
use App\Migration\IntranetV3\AbstractMigrator;
use App\Migration\IntranetV3\Contract\MigratorInterface;
use App\Migration\IntranetV3\MigrationContext;
use App\Migration\IntranetV3\MigrationResult;

final class SemestreMigrator extends AbstractMigrator
{
    public function getName(): string
    {
        return 'semestres';
    }

    /** @return list<class-string<MigratorInterface>> */
    public function getDependencies(): array
    {
        return [AnneeMigrator::class];
    }

    public function migrate(MigrationContext $context): MigrationResult
    {
        $rows = $this->source->fetchAllAssociative(
            'SELECT id, annee_id, libelle, ordre_annee, ordre_lmd, actif, nb_groupes_cm, nb_groupes_td, nb_groupes_tp, code_element FROM semestre ORDER BY id'
        );
        $repository = $this->entityManager->getRepository(StructureSemestre::class);
        $anneeRepository = $this->entityManager->getRepository(StructureAnnee::class);
        $created = $updated = $skipped = $failed = 0;
        $messages = [];

        foreach ($rows as $row) {
            try {
                $annee = $anneeRepository->findOneBy(['oldId' => (int) $row['annee_id']]);
                if (!$annee) {
                    ++$skipped;
                    $messages[] = sprintf('Semestre #%s skipped: annee V3 #%s introuvable.', $row['id'], $row['annee_id']);
                    continue;
                }

                $entity = $repository->findOneBy(['oldId' => (int) $row['id']]);
                $isNew = null === $entity;
                $entity ??= new StructureSemestre();

                $entity
                    ->setOldId((int) $row['id'])
                    ->setAnnee($annee)
                    ->setLibelle((string) $row['libelle'])
                    ->setOrdreAnnee((int) $row['ordre_annee'])
                    ->setOrdreLmd((int) $row['ordre_lmd'])
                    ->setActif((bool) $row['actif'])
                    ->setNbGroupesCm((int) $row['nb_groupes_cm'])
                    ->setNbGroupesTd((int) $row['nb_groupes_td'])
                    ->setNbGroupesTp((int) $row['nb_groupes_tp'])
                    ->setCodeElement($row['code_element'] ?: null);

                if ($isNew) {
                    $this->entityManager->persist($entity);
                    ++$created;
                } else {
                    ++$updated;
                }
            } catch (\Throwable $e) {
                ++$failed;
                $messages[] = sprintf('Semestre #%s: %s', $row['id'], $e->getMessage());
            }
        }

        $this->flush($context);

        return new MigrationResult($created, $updated, $skipped, $failed, $messages);
    }
}
