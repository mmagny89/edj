<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'association n'a pas de ludotheque : les jeux sont ceux de ses membres.
 *
 * La description par defaut d'un type d'evenement est copiee dans chaque ligne
 * a la creation, si bien que changer le texte du code ne touche pas les
 * evenements deja saisis. Seules les descriptions strictement egales a
 * l'ancien texte par defaut sont remplacees : une description reecrite a la
 * main reste telle quelle.
 */
final class Version20261017180000 extends AbstractMigration
{
    private const REPLACEMENTS = [
        'Une soirée de jeux : tables ouvertes, ludothèque et explications.' => 'Une soirée de jeux : tables ouvertes, jeux apportés par les membres et explications.',
        'Une après-midi de jeux autour de la ludothèque.' => 'Une après-midi de jeux, avec les jeux apportés par les membres.',
    ];

    public function getDescription(): string
    {
        return "Remplace « ludothèque » par les jeux des membres dans les descriptions par défaut des évènements.";
    }

    public function up(Schema $schema): void
    {
        foreach (self::REPLACEMENTS as $old => $new) {
            $this->addSql('UPDATE event SET description = ? WHERE description = ?', [$new, $old]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::REPLACEMENTS as $old => $new) {
            $this->addSql('UPDATE event SET description = ? WHERE description = ?', [$old, $new]);
        }
    }
}
