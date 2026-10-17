<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Date d'anonymisation d'un bulletin d'adhesion. Ecrite a la main, comme la
 * migration des evenements : make:migration n'est pas utilisable ici.
 */
final class Version20261017170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute la date d'anonymisation aux bulletins d'adhesion.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE membership_application ADD anonymized_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE membership_application DROP anonymized_at');
    }
}
