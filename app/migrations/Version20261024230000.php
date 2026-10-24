<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un pointage par personne et par jour, et non par personne et par evenement.
 *
 * On pointe pour la seance du jour ou pour une date passee que l'on saisit : une
 * seance n'a pas besoin d'etre au calendrier. Le lien a l'evenement reste, en
 * facultatif, pour nommer le pointage.
 *
 * L'index unique change de colonnes. Les lignes existantes le respectent : une
 * personne n'y a qu'un pointage par jour (verifie avant d'ecrire cette migration).
 * Un doublon ferait echouer la creation de l'index, sans rien modifier.
 */
final class Version20261024230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Un pointage par personne et par jour.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_event_attendance_personne');
        $this->addSql('CREATE UNIQUE INDEX uniq_event_attendance_jour ON event_attendance (application_id, occurred_on)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_event_attendance_jour');
        // Sans evenement, une ligne ne peut pas retrouver son ancien index : elles
        // restent, et l'index sur (event_id, application_id) ignore les NULL.
        $this->addSql('CREATE UNIQUE INDEX uniq_event_attendance_personne ON event_attendance (event_id, application_id)');
    }
}
