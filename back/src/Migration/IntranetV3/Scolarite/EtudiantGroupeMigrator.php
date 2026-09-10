<?php

namespace App\Migration\IntranetV3\Scolarite;

use App\Entity\Etudiant\EtudiantScolariteSemestre;
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
            'scolariteSemestre' => 0,
            'groupe' => 0,
        ];
        $sampleCount = 0;

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

                /*
                 * V3 ne versionne pas etudiant_groupe par année universitaire.
                 * On rattache donc l'affectation au semestre le plus récent dans lequel
                 * l'étudiant possède une scolarité et où ce groupe V3 existe dans le snapshot.
                 */
                $scolariteSemestre = $this->entityManager->createQueryBuilder()
                    ->select('ss')
                    ->from(EtudiantScolariteSemestre::class, 'ss')
                    ->innerJoin('ss.scolarite', 'sc')
                    ->innerJoin('ss.semestre', 'sem')
                    ->innerJoin('sem.annee', 'an')
                    ->innerJoin('an.pn', 'pn')
                    ->innerJoin('pn.anneeUniversitaire', 'au')
                    ->innerJoin('sem.groupes', 'g')
                    ->andWhere('sc.etudiant = :etudiant')
                    ->andWhere('g.oldId = :groupeOldId')
                    ->setParameter('etudiant', $etudiant)
                    ->setParameter('groupeOldId', (int) $row['groupe_id'])
                    ->orderBy('au.annee', 'DESC')
                    ->addOrderBy('sem.ordreLmd', 'DESC')
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();

                if (null === $scolariteSemestre) {
                    ++$skipped;
                    ++$unresolved['scolariteSemestre'];
                    $this->addSample($messages, $sampleCount, sprintf(
                        'Affectation étudiant V3 #%s / groupe V3 #%s ignorée: aucune scolarité semestrielle compatible trouvée.',
                        $row['etudiant_id'],
                        $row['groupe_id'],
                    ));
                    ++$processed;
                    $this->flushBatch($context, $processed);
                    continue;
                }

                $groupe = null;
                foreach ($scolariteSemestre->getSemestre()->getGroupes() as $candidate) {
                    if ($candidate->getOldId() === (int) $row['groupe_id']) {
                        $groupe = $candidate;
                        break;
                    }
                }

                if (!$groupe instanceof StructureGroupe) {
                    ++$skipped;
                    ++$unresolved['groupe'];
                    $this->addSample($messages, $sampleCount, sprintf(
                        'Affectation étudiant V3 #%s / groupe V3 #%s ignorée: groupe du snapshot résolu introuvable.',
                        $row['etudiant_id'],
                        $row['groupe_id'],
                    ));
                    ++$processed;
                    $this->flushBatch($context, $processed);
                    continue;
                }

                if (!$scolariteSemestre->getGroupes()->contains($groupe)) {
                    $scolariteSemestre->addGroupe($groupe);
                    ++$created;
                } else {
                    ++$updated;
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
                'Résumé des affectations non résolues: étudiants=%d, scolarités semestrielles compatibles=%d, groupes snapshots=%d.',
                $unresolved['etudiant'],
                $unresolved['scolariteSemestre'],
                $unresolved['groupe'],
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
