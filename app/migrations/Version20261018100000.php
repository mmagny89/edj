<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Origine d'un bulletin (en ligne ou fiche papier) et administrateur qui l'a
 * saisi. Additive : les bulletins existants sont « en ligne ».
 */
final class Version20261018100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute l'origine des bulletins d'adhesion et l'administrateur qui les a saisis.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE membership_application ADD source VARCHAR(10) DEFAULT 'en_ligne' NOT NULL");
        $this->addSql('ALTER TABLE membership_application ADD entered_by_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE membership_application DROP source');
        $this->addSql('ALTER TABLE membership_application DROP entered_by_id');
    }
}
