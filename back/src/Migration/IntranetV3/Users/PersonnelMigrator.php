<?php

namespace App\Migration\IntranetV3\Users;

use App\Entity\Structure\StructureAnneeUniversitaire;
use App\Entity\Users\Personnel;
use App\Migration\IntranetV3\AbstractMigrator;
use App\Migration\IntranetV3\MigrationContext;
use App\Migration\IntranetV3\MigrationResult;
use App\Migration\IntranetV3\Structure\AnneeUniversitaireMigrator;

final class PersonnelMigrator extends AbstractMigrator
{
    public function getName(): string
    {
        return 'personnels';
    }

    public function getDependencies(): array
    {
        return [AnneeUniversitaireMigrator::class];
    }

    public function migrate(MigrationContext $context): MigrationResult
    {
        $created = $updated = $failed = $processed = 0;
        $messages = [];

        $sql = 'SELECT id, username, mail_univ, mail_perso, prenom, nom, photo_name, annee_universitaire_id FROM personnel ORDER BY id';
        $rows = $this->source->executeQuery($sql)->iterateAssociative();

        foreach ($rows as $row) {
            try {
                $repository = $this->entityManager->getRepository(Personnel::class);
                $anneeRepository = $this->entityManager->getRepository(StructureAnneeUniversitaire::class);

                $entity = $repository->findOneBy(['oldId' => (int) $row['id']]);
                $isNew = null === $entity;
                $entity ??= new Personnel();

                $entity
                    ->setOldId((int) $row['id'])
                    ->setUsername((string) $row['username'])
                    ->setMailUniv((string) $row['mail_univ'])
                    ->setPrenom((string) $row['prenom'])
                    ->setNom((string) $row['nom'])
                    ->setPhotoName($row['photo_name']);

                if (method_exists($entity, 'setMailPerso')) {
                    $entity->setMailPerso($row['mail_perso']);
                }

                if (null !== $row['annee_universitaire_id']) {
                    $entity->setAnneeUniversitaire($anneeRepository->findOneBy(['oldId' => (int) $row['annee_universitaire_id']]));
                }

                if ($isNew) {
                    $this->entityManager->persist($entity);
                    ++$created;
                } else {
                    ++$updated;
                }
            } catch (\Throwable $e) {
                ++$failed;
                $messages[] = sprintf('Personnel #%s: %s', $row['id'], $e->getMessage());
            }

            ++$processed;
            $this->flushBatch($context, $processed);
        }

        $this->flushAndClear($context);

        return new MigrationResult($created, $updated, 0, $failed, $messages);
    }
}
