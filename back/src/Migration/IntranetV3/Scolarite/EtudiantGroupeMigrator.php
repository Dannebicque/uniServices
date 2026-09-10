<?php

namespace App\Migration\IntranetV3\Scolarite;

use App\Entity\Etudiant\EtudiantScolarite;
use App\Entity\Etudiant\EtudiantScolariteSemestre;
use App\Entity\Structure\StructureAnneeUniversitaire;
use App\Entity\Structure\StructureGroupe;
use App\Entity\Users\Etudiant;
use App\Migration\IntranetV3\AbstractMigrator;
use App\Migration\IntranetV3\Contract\MigratorInterface;
use App\Migration\IntranetV3\MigrationContext;
use App\Migration\IntranetV3\MigrationResult;
use App\Migration\IntranetV3\Structure\GroupeMigrator;
use App\Migration\IntranetV3\Users\EtudiantMigrator;

final class EtudiantGroupeMigrator extends AbstractMigrator
{
    public function getName(): string
    {
        return 'etudiant-groupes';
    }

    /** @return list<class-string<MigratorInterface>> */
    public function getDependencies(): array
    {
        return [EtudiantMigrator::class, ScolariteMigrator::class, GroupeMigrator::class];
    }

    public function migrate(MigrationContext $context): MigrationResult
    {
        $created = $updated = $skipped = $failed = $processed = 0;
        $messages = [];
        $unresolved = [
            'etudiant' => 0,
            'scolarite' => 0,
            'groupe' => 0,
            'semestre' => 0,
        ];
        $sampleCount = 0;

        $activeYear = $this->entityManager->getRepository(StructureAnneeUniversitaire::class)
            ->findOneBy(['actif' => true]);

        if (null === $activeYear) {
            return new MigrationResult(0, 0, 0, 1, ['Aucune année universitaire active trouvée dans la cible.']);
        }

        $sql = <<<'SQL'
SELECT eg.etudiant_id, eg.groupe_id
FROM etudiant_groupe eg
ORDER BY eg.etudiant_id, eg.groupe_id
SQL;

        foreach ($this->source->executeQuery($sql)->iterateAssociative() as $row) {
            try {
                $etudiant = $this->entityManager->getRepository(Etudiant::class)
                    ->findOneBy(['oldId' => (int) $row['etudiant_id']]);

                if (null === $etudiant) {
                    ++$skipped;
                    ++$unresolved['etudiant'];
                    $this->addSample($messages, $sampleCount, sprintf(
                        'Affectation étudiant V3 #%s / groupe V3 #%s ignorée: étudiant non résolu.',
                        $row['etudiant_id'],
                        $row['groupe_id'],
                    ));
                    ++$processed;
                    $this->flushBatch($context, $processed);
                    continue;
                }

                $scolarite = $this->entityManager->getRepository(EtudiantScolarite::class)
                    ->findOneBy([
                        'etudiant' => $etudiant,
                        'anneeUniversitaire' => $activeYear,
                    ]);

                if (null === $scolarite) {
                    ++$skipped;
                    ++$unresolved['scolarite'];
                    $this->addSample($messages, $sampleCount, sprintf(
                        'Affectation étudiant V3 #%s / groupe V3 #%s ignorée: aucune scolarité pour l\'année universitaire active.',
                        $row['etudiant_id'],
                        $row['groupe_id'],
                    ));
                    ++$processed;
                    $this->flushBatch($context, $processed);
                    continue;
                }

                $groupes = $this->entityManager->createQueryBuilder()
                    ->select('g', 'sem')
                    ->from(StructureGroupe::class, 'g')
                    ->innerJoin('g.semestres', 'sem')
                    ->innerJoin('sem.annee', 'an')
                    ->innerJoin('an.pn', 'pn')
                    ->andWhere('g.oldId = :oldId')
                    ->andWhere('pn.anneeUniversitaire = :anneeUniversitaire')
                    ->setParameter('oldId', (int) $row['groupe_id'])
                    ->setParameter('anneeUniversitaire', $activeYear)
                    ->getQuery()
                    ->getResult();

                if ([] === $groupes) {
                    ++$skipped;
                    ++$unresolved['groupe'];
                    $this->addSample($messages, $sampleCount, sprintf(
                        'Affectation étudiant V3 #%s / groupe V3 #%s ignorée: groupe du snapshot actif non résolu.',
                        $row['etudiant_id'],
                        $row['groupe_id'],
                    ));
                    ++$processed;
                    $this->flushBatch($context, $processed);
                    continue;
                }

                $matched = false;
                foreach ($groupes as $groupe) {
                    foreach ($groupe->getSemestres() as $semestre) {
                        $scolariteSemestre = $this->entityManager->getRepository(EtudiantScolariteSemestre::class)
                            ->findOneBy([
                                'scolarite' => $scolarite,
                                'semestre' => $semestre,
                            ]);

                        if (null === $scolariteSemestre) {
                            continue;
                        }

                        $matched = true;
                        if (!$scolariteSemestre->getGroupes()->contains($groupe)) {
                            $scolariteSemestre->addGroupe($groupe);
                            ++$created;
                        } else {
                            ++$updated;
                        }
                    }
                }

                if (!$matched) {
                    ++$skipped;
                    ++$unresolved['semestre'];
                    $this->addSample($messages, $sampleCount, sprintf(
                        'Affectation étudiant V3 #%s / groupe V3 #%s ignorée: aucune scolarité semestrielle compatible dans le snapshot actif.',
                        $row['etudiant_id'],
                        $row['groupe_id'],
                    ));
                }
            } catch (\Throwable $e) {
                ++$failed;
                $this->addSample($messages, $sampleCount, sprintf(
                    'Affectation étudiant V3 #%s / groupe V3 #%s: %s',
                    $row['etudiant_id'],
                    $row['groupe_id'],
                    $e->getMessage(),
                ));
            }

            ++$processed;
            $this->flushBatch($context, $processed);
        }

        $this->flushAndClear($context);

        if (array_sum($unresolved) > 0) {
            $messages[] = sprintf(
                'Résumé des affectations non résolues: étudiants=%d, scolarités actives=%d, groupes actifs=%d, semestres compatibles=%d.',
                $unresolved['etudiant'],
                $unresolved['scolarite'],
                $unresolved['groupe'],
                $unresolved['semestre'],
            );
        }

        return new MigrationResult($created, $updated, $skipped, $failed, $messages);
    }

    /** @param list<string> $messages */
    private function addSample(array &$messages, int &$sampleCount, string $message): void
    {
        if ($sampleCount >= 20) {
            return;
        }

        $messages[] = $message;
        ++$sampleCount;
    }
}
