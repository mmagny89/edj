<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La date de naissance devient facultative en base.
 *
 * Une personne reprise d'un fichier d'adherents peut ne pas en avoir : la
 * refuser a l'import, c'etait refuser la personne. Le bulletin en ligne et la
 * fiche papier la demandent toujours — la regle est dans l'entite, pas dans le
 * schema.
 *
 * Compatible avec l'ancien code, qui continue de servir pendant la migration :
 * il ecrit toujours une date, la colonne qui accepte desormais le vide ne
 * change rien pour lui.
 */
final class Version20261024200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'La date de naissance d\'un bulletin devient facultative.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE membership_application ALTER birth_date DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Une fiche sans date ne peut pas redevenir obligatoire : on la date au
        // 1er janvier 1900, valeur reperable, plutot que de perdre la fiche.
        $this->addSql("UPDATE membership_application SET birth_date = '1900-01-01' WHERE birth_date IS NULL");
        $this->addSql('ALTER TABLE membership_application ALTER birth_date SET NOT NULL');
    }
}
