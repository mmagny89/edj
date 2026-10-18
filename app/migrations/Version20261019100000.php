<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'adresse e-mail d'un bulletin devient facultative.
 *
 * Elle reste obligatoire pour un bulletin depose en ligne (la regle est dans
 * l'entite). Mais une fiche papier, ou un fichier d'adherents repris dans le site,
 * decrit des personnes qui n'en ont pas : les refuser, c'etait refuser un tiers
 * des adherents d'un vrai fichier.
 *
 * Compatible avec l'ancien code, qui continue de servir pendant la migration : il
 * ecrit toujours une adresse, la colonne qui accepte desormais le vide ne change
 * rien pour lui.
 */
final class Version20261019100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rend facultative l\'adresse e-mail d\'un bulletin (obligatoire en ligne seulement).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE membership_application ALTER email DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Un retour en arriere ne peut pas inventer des adresses : les bulletins qui
        // n'en ont pas recoivent une adresse reservee, qui ne peut recevoir aucun
        // courrier.
        $this->addSql("UPDATE membership_application SET email = 'sans-adresse-' || id || '@anonyme.invalid' WHERE email IS NULL");
        $this->addSql('ALTER TABLE membership_application ALTER email SET NOT NULL');
    }
}
