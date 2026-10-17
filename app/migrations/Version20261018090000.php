<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gestion des administrateurs : date de creation, blocage, mot de passe
 * provisoire et derniere connexion. Additive : l'ancien code, qui continue de
 * servir pendant la migration, ignore ces colonnes.
 */
final class Version20261018090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute creation, blocage, mot de passe provisoire et derniere connexion aux comptes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD blocked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD must_change_password BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // La valeur par defaut de created_at ne sert qu'a remplir les lignes
        // existantes : l'entite fournit toujours la date.
        $this->addSql('ALTER TABLE "user" ALTER created_at DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP created_at');
        $this->addSql('ALTER TABLE "user" DROP blocked_at');
        $this->addSql('ALTER TABLE "user" DROP must_change_password');
        $this->addSql('ALTER TABLE "user" DROP last_login_at');
    }
}
