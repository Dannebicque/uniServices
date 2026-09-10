<?php

namespace App\Migration\IntranetV3\Scolarite;

use App\Entity\Etudiant\EtudiantScolarite;
use App\Entity\Etudiant\EtudiantScolariteSemestre;
use App\Entity\Structure\StructureAnneeUniversitaire;
use App\Entity\Structure\StructureSemestre;
use App\Entity\Users\Etudiant;
use App\Migration\IntranetV3\AbstractMigrator;
use App\Migration\IntranetV3\MigrationContext;
use App\Migration\IntranetV3\MigrationResult;
use App\Migration\IntranetV3\Structure\AnneeUniversitaireMigrator;
use App\Migration\IntranetV3\Structure\SemestreMigrator;
use App\Migration\IntranetV3\Users\EtudiantMigrator;

final class ScolariteMigrator extends AbstractMigrator
{
    public function getName(): string
    {
        return 'scolarites';
    }

    public function getDependencies(): array
    {
        return [
            EtudiantMigrator::class,
            AnneeUniversitaireMigrator::class,
            SemestreMigrator::class,
        ];
    }

    public function migrate(MigrationContext $context): MigrationResult
    {
        $created = $updated = $skipped = $failed = 0;
        $messages = [];

        $etudiantRepository = $this->entityManager->getRepository(Etudiant::class);
        $anneeRepository = $this->entityManager->getRepository(StructureAnneeUniversitaire::class);
        $semestreRepository = $this->entityManager->getRepository(StructureSemestre::class);
        $scolariteRepository = $this->entityManager->getRepository(EtudiantScolarite::class);
        $scolariteSemestreRepository = $this->entityManager->getRepository(EtudiantScolariteSemestre::class);

        $sql = <<<'SQL'
SELECT id, etudiant_id, semestre_id, annee_universitaire_id, ordre, moyenne, nb_absences, commentaire, diffuse,
       SUM(nb_absences) OVER (PARTITION BY etudiant_id, annee_universitaire_id) AS total_nb_absences
FROM scolarite
ORDER BY annee_universitaire_id, etudiant_id, ordre, id
SQL;

        foreach ($this->source->fetchAllAssociative($sql) as $row) {
            try {
                $etudiant = $etudiantRepository->findOneBy(['oldId' => (int) $row['etudiant_id']]);
                $anneeUniversitaire = $anneeRepository->findOneBy(['oldId' => (int) $row['annee_universitaire_id']]);
                $semestre = $semestreRepository->findOneBy(['oldId' => (int) $row['semestre_id']]);

                if (null === $etudiant || null === $anneeUniversitaire || null === $semestre) {
                    ++$skipped;
                    $messages[] = sprintf('Scolarite #%s ignorée: étudiant, année universitaire ou semestre non résolu.', $row['id']);
                    continue;
                }

                $scolarite = $scolariteRepository->findOneBy([
                    'etudiant' => $etudiant,
                    'anneeUniversitaire' => $anneeUniversitaire,
                ]);

                $newScolarite = null === $scolarite;
                if ($newScolarite) {
                    $scolarite = new EtudiantScolarite();
                    $scolarite
                        ->setEtudiant($etudiant)
                        ->setAnneeUniversitaire($anneeUniversitaire)
                        ->setDepartement($semestre->getAnnee()?->getDepartement())
                        ->setOrdre((int) $row['ordre']);
                    $scolarite->setActif($anneeUniversitaire->isActif() ?? false);
                    $this->entityManager->persist($scolarite);
                    ++$created;
                } else {
                    ++$updated;
                }

                $scolarite
                    ->setNbAbsences((int) $row['total_nb_absences'])
                    ->setPublic($scolarite->isPublic() || (bool) $row['diffuse']);

                if (null === $scolarite->getDepartement()) {
                    $scolarite->setDepartement($semestre->getAnnee()?->getDepartement());
                }

                $scolariteSemestre = $scolariteSemestreRepository->findOneBy([
                    'scolarite' => $scolarite,
                    'semestre' => $semestre,
                ]);

                if (null === $scolariteSemestre) {
                    $scolariteSemestre = new EtudiantScolariteSemestre();
                    $scolariteSemestre
                        ->setScolarite($scolarite)
                        ->setSemestre($semestre);
                    $this->entityManager->persist($scolariteSemestre);
                }

                $scolariteSemestre->setMoyenne(null !== $row['moyenne'] ? (float) $row['moyenne'] : null);

                if (!empty($row['commentaire']) && empty($scolarite->getCommentaire())) {
                    $scolarite->setCommentaire((string) $row['commentaire']);
                }
            } catch (\Throwable $e) {
                ++$failed;
                $messages[] = sprintf('Scolarite #%s: %s', $row['id'], $e->getMessage());
            }
        }

        $this->flush($context);

        return new MigrationResult($created, $updated, $skipped, $failed, $messages);
    }
}
