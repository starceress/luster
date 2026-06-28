<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\EmailTemplateRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class DisabledByDefaultEmailTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_email_templates (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id INTEGER NOT NULL DEFAULT 0,
    type          TEXT    NOT NULL DEFAULT '',
    subject       TEXT    NOT NULL DEFAULT '',
    body          TEXT    NOT NULL DEFAULT '',
    enabled       INTEGER NOT NULL DEFAULT 1,
    UNIQUE(fanlisting_id, type)
)
SQL;

    public function testMissingRowResolvesToEmptyForDisabledByDefaultType(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new EmailTemplateRepo($db);

        [$subject, $body] = $repo->resolveForListing(null, EmailTemplateRepo::TYPE_AFFILIATE_REJECTED, []);

        self::assertSame('', $subject);
        self::assertSame('', $body);
    }

    public function testMissingRowResolvesToDefaultForNormalType(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new EmailTemplateRepo($db);

        [$subject, $body] = $repo->resolveForListing(null, EmailTemplateRepo::TYPE_AFFILIATE_APPROVED, []);

        self::assertNotSame('', $subject);
        self::assertNotSame('', $body);
    }

    public function testExplicitlyEnabledRejectionTemplateResolves(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new EmailTemplateRepo($db);

        // Collective scope: affiliate_rejected resolves from fanlisting_id = -1
        $db->pdo()->exec(
            "INSERT INTO lstr_email_templates (fanlisting_id, type, subject, body, enabled)
             VALUES (-1, 'affiliate_rejected', 'Rejected', 'Sorry.', 1)"
        );

        [$subject, $body] = $repo->resolveForListing(null, EmailTemplateRepo::TYPE_AFFILIATE_REJECTED, []);

        self::assertSame('Rejected', $subject);
        self::assertSame('Sorry.', $body);
    }

    public function testDisabledRejectionTemplateDoesNotResolve(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new EmailTemplateRepo($db);

        // Collective scope: adoption_rejected resolves from fanlisting_id = -1
        $db->pdo()->exec(
            "INSERT INTO lstr_email_templates (fanlisting_id, type, subject, body, enabled)
             VALUES (-1, 'adoption_rejected', 'Rejected', 'Sorry.', 0)"
        );

        [$subject, $body] = $repo->resolveForListing(null, EmailTemplateRepo::TYPE_ADOPTION_REJECTED, []);

        self::assertSame('', $subject);
        self::assertSame('', $body);
    }
}
