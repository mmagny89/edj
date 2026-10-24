<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un pointage survit a la purge de son evenement.
 *
 * La purge nocturne supprime les evenements passes ; avec la cle etrangere en
 * ON DELETE CASCADE de la migration precedente, elle aurait emporte avec eux
 * les euros encaisses, que la tresorerie doit garder. Le pointage porte donc
 * desormais sa propre date et son propre libelle, et son lien a l'evenement
 * se vide (SET NULL) au lieu de disparaitre.
 *
 * Les lignes deja presentes recoivent la date et le titre de leur evenement
 * avant que les colonnes ne deviennent obligatoires : la reprise se fait dans
 * la migration, pas a la main.
 */
final class Version20261024190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Un pointage garde sa date et son libelle, et survit a la purge de son evenement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_attendance ADD occurred_on DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE event_attendance ADD label VARCHAR(150) DEFAULT NULL');
        $this->addSql('UPDATE event_attendance p SET occurred_on = e.starts_at, label = e.title FROM event e WHERE e.id = p.event_id');
        $this->addSql('ALTER TABLE event_attendance ALTER occurred_on SET NOT NULL');
        $this->addSql('ALTER TABLE event_attendance ALTER label SET NOT NULL');
        $this->addSql('CREATE INDEX idx_event_attendance_occurred_on ON event_attendance (occurred_on)');

        $this->addSql('ALTER TABLE event_attendance ALTER event_id DROP NOT NULL');
        $this->addSql('ALTER TABLE event_attendance DROP CONSTRAINT FK_350DD4BE71F7E88B');
        $this->addSql('ALTER TABLE event_attendance ADD CONSTRAINT FK_350DD4BE71F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM event_attendance WHERE event_id IS NULL');
        $this->addSql('ALTER TABLE event_attendance DROP CONSTRAINT FK_350DD4BE71F7E88B');
        $this->addSql('ALTER TABLE event_attendance ADD CONSTRAINT FK_350DD4BE71F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE event_attendance ALTER event_id SET NOT NULL');
        $this->addSql('DROP INDEX idx_event_attendance_occurred_on');
        $this->addSql('ALTER TABLE event_attendance DROP occurred_on');
        $this->addSql('ALTER TABLE event_attendance DROP label');
    }
}
