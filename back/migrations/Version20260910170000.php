<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Support intranet V3 structure snapshots and enforce one PN per diploma and academic year.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE structure_annee ADD old_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_STRUCTURE_ANNEE_OLD_ID ON structure_annee (old_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STRUCTURE_PN_DIPLOME_ANNEE_UNIV ON structure_pn (diplome_id, annee_universitaire_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_STRUCTURE_PN_DIPLOME_ANNEE_UNIV ON structure_pn');
        $this->addSql('DROP INDEX IDX_STRUCTURE_ANNEE_OLD_ID ON structure_annee');
        $this->addSql('ALTER TABLE structure_annee DROP old_id');
    }
}
