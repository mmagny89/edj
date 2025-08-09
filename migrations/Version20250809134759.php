<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250809134759 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE consumable (id SERIAL NOT NULL, name VARCHAR(255) NOT NULL, price DOUBLE PRECISION NOT NULL, stock INT DEFAULT NULL, type VARCHAR(50) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE consumption (id SERIAL NOT NULL, event_id INT NOT NULL, member_number VARCHAR(10) NOT NULL, consumable_id INT NOT NULL, quantity INT NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_2CFF2DF971F7E88B ON consumption (event_id)');
        $this->addSql('CREATE INDEX IDX_2CFF2DF9B2469D67 ON consumption (member_number)');
        $this->addSql('CREATE INDEX IDX_2CFF2DF9A94ADB61 ON consumption (consumable_id)');
        $this->addSql('CREATE TABLE member (member_number VARCHAR(10) NOT NULL, PRIMARY KEY(member_number))');
        $this->addSql('ALTER TABLE consumption ADD CONSTRAINT FK_2CFF2DF971F7E88B FOREIGN KEY (event_id) REFERENCES event (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE consumption ADD CONSTRAINT FK_2CFF2DF9B2469D67 FOREIGN KEY (member_number) REFERENCES member (member_number) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE consumption ADD CONSTRAINT FK_2CFF2DF9A94ADB61 FOREIGN KEY (consumable_id) REFERENCES consumable (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE consumption DROP CONSTRAINT FK_2CFF2DF971F7E88B');
        $this->addSql('ALTER TABLE consumption DROP CONSTRAINT FK_2CFF2DF9B2469D67');
        $this->addSql('ALTER TABLE consumption DROP CONSTRAINT FK_2CFF2DF9A94ADB61');
        $this->addSql('DROP TABLE consumable');
        $this->addSql('DROP TABLE consumption');
        $this->addSql('DROP TABLE member');
    }
}
