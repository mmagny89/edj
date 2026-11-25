<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Date de depot ou de paiement des cotisations et des euros de seance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE membership_application ADD cashed_on DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE event_attendance ADD cashed_on DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE membership_application DROP cashed_on');
        $this->addSql('ALTER TABLE event_attendance DROP cashed_on');
    }
}
