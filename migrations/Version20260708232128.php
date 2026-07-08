<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260708232128 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Slice 3: add Person.platformAdmin — an orthogonal, platform-wide '
            . 'capability distinct from the tenant-scoped role column, grantable '
            . 'only via app:grant-platform-admin. Defaults false so existing rows '
            . 'stay non-platform-admin.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE person ADD platform_admin BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE person DROP platform_admin');
    }
}
