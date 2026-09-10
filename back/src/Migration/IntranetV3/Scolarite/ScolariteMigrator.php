<?php

namespace App\Migration\IntranetV3\Scolarite;

use App\Entity\Etudiant\EtudiantScolarite;
use App\Entity\Etudiant\EtudiantScolariteSemestre;
use App\Entity\Structure\StructureAnneeUniversitaire;
use App\Entity\Structure\StructureDiplome;
use App\Entity\Structure\StructurePn;
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
        return [EtudiantMigrator::class, AnneeUniversitaireMigrator::class, SemestreMigrator::class];
    }

    public function migrate(MigrationContext $context): MigrationResult
    {
        $created = $updated = $skipped = $failed = $processed = 0;
        $messages = [];

        $sql = <<<'SQL'
SELECT
    sc.id,
    sc.etudiant_id,
    sc.semestre_id,
    sc.annee_universitaire_id,
    a.diplome_id,
    sc.ordre,
    sc.moyenne,
    sc.nb_absences,
    sc.commentaire,
    sc.diffuse,
    SUM(sc.nb_absences) OVER (PARTITION BY sc.etudiant_id, sc.annee_universitaire_id) AS total_nb_absences,
    MAX(sc.diffuse) OVER (PARTITION BY sc.etudiant_id, sc.annee_universitaire_id) AS public_annee
FROM scolarite sc
INNER JOIN semestre s ON s.id = sc.semestre_id
INNER JOIN annee a ON a.id = s.annee_id
ORDER BY sc.annee_universitaire_id, sc.etudiant_id, sc.ordre, sc.id
SQL;

        foreach ($this->source->executeQuery($sql)->iterateAssociative() as $row) {
            try {
                $etudiantRepository = $this->entityManager->getRepository(Etudiant::class);
                $anneeRepository = $this->entityManager->getRepository(StructureAnneeUniversitaire::class);
                $diplomeRepository = $this->entityManager->getRepository(StructureDiplome::class);
                $pnRepository = $this->entityManager->getRepository(StructurePn::class);
                $scolariteRepository = $this->entityManager->getRepository(EtudiantScolarite::class);
                $scolariteSemestreRepository = $this->entityManager->getRepository(EtudiantScolariteSemestre::class);

                $etudiant = $etudiantRepository->findOneBy(['oldId' => (int) $row['etudiant_id']]);
                $anneeUniversitaire = $anneeRepository->findOneBy(['oldId' => (int) $row['annee_universitaire_id']]);
                $diplome = $diplomeRepository->findOneBy(['oldId' => (int) $row['diplome_id']]);
                $pn = null;
                $semestre = null;

                if (null !== $diplome && null !== $anneeUniversitaire) {
                    $pn = $pnRepository->findOneBy([
                        'diplome' => $diplome,
                        'anneeUniversitaire' => $anneeUniversitaire,
                    ]);
                }

                if (null !== $pn) {
                    $semestre = $this->entityManager->createQueryBuilder()
                        ->select('sem')
                        ->from(StructureSemestre::class, 'sem')
                        ->innerJoin('sem.annee', 'an')
                        ->andWhere('sem.oldId = :oldId')
                        ->andWhere('an.pn = :pn')
                        ->setParameter('oldId', (int) $row['semestre_id'])
                        ->setParameter('pn', $pn)
                        ->getQuery()
                        ->getOneOrNullResult();
                }

                if (null === $etudiant || null === $anneeUniversitaire || null === $diplome || null === $pn || null === $semestre) {
                    ++$skipped;
                    $messages[] = sprintf(
                        'Scolarite #%s ignorée: étudiant ou snapshot annuel de structure non résolu.',
                        $row['id'],
                    );
                    ++$processed;
                    $this->flushBatch($context, $processed);
                    continue;
                }

                $scolarite = $scolariteRepository->findOneBy([
                    'etudiant' => $etudiant,
                    'anneeUniversitaire' => $anneeUniversitaire,
                ]);

                if (null === $scolarite) {
                    $scolarite = new EtudiantScolarite();
                    $scolarite
                        ->setEtudiant($etudiant)
                        ->setAnneeUniversitaire($anneeUniversitaire)
                        ->setDepartement($semestre->getAnnee()?->getDepartement())
                        ->setOrdre((int) $row['ordre']);
                    $this->entityManager->persist($scolarite);
                    ++$created;
                } else {
                    ++$updated;
                }

                $scolarite
                    ->setNbAbsences((int) $row['total_nb_absences'])
                    ->setPublic((bool) $row['public_annee']);
                $scolarite->setActif($anneeUniversitaire->isActif() ?? false);

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

            ++$processed;
            $this->flushBatch($context, $processed);
        }

        $this->flushAndClear($context);

        return new MigrationResult($created, $updated, $skipped, $failed, $messages);
    }
}
