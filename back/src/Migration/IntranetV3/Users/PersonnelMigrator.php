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
        $created = $updated = $failed = 0;
        $messages = [];
        $repository = $this->entityManager->getRepository(Personnel::class);
        $anneeRepository = $this->entityManager->getRepository(StructureAnneeUniversitaire::class);

        $sql = 'SELECT id, username, mail_univ, mail_perso, password, prenom, nom, photo_name, annee_universitaire_id FROM personnel ORDER BY id';
        foreach ($this->source->fetchAllAssociative($sql) as $row) {
            try {
                $entity = $repository->findOneBy(['oldId' => (int) $row['id']]);
                $isNew = null === $entity;
                $entity ??= new Personnel();

                $entity
                    ->setOldId((int) $row['id'])
                    ->setUsername((string) $row['username'])
                    ->setMailUniv((string) $row['mail_univ'])
                    ->setPassword($row['password'])
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
        }

        $this->flush($context);

        return new MigrationResult($created, $updated, 0, $failed, $messages);
    }
}
