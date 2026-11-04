<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Models\Member;
use Luster\Repositories\MemberRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Enthusiast allows an additional field named like "123". On the unprefixed
 * schema that's the column's name too, and PHP turns it into an int array key
 * wherever the column is a key: in the data passed to insert(), and in every
 * row PDO fetches. Both used to reach string-only code and throw a TypeError.
 * (The standard schema's column is additional_123, a string key.)
 */
final class MemberRepoNumericFieldTest extends TestCase
{
    public function testInsertSavesANumericColumnAndGetByEmailReadsItBack(): void
    {
        $db = SqliteDatabase::make("CREATE TABLE fl_moon (
            email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0,
            \"123\" TEXT NOT NULL DEFAULT '')");
        $repo = new MemberRepo($db, $this->config());

        $data = ['email' => 'ann@example.com', 'name' => 'Ann', '123' => '42'] + $repo->approvedColumns();
        self::assertIsInt(array_key_first(array_slice($data, 2, 1, true)), 'PHP made the key an int');
        $repo->insert('fl_moon', $data);

        $member = $repo->getByEmail('fl_moon', 'ann@example.com');
        self::assertNotNull($member);
        self::assertSame('42', $member->additionalFields['123'] ?? null);
        self::assertTrue($member->approved);
        self::assertCount(1, $repo->getApproved('fl_moon'));
    }

    public function testFromRowKeepsANumericColumnAsAnAdditionalField(): void
    {
        $member = Member::fromRow(['email' => 'ann@example.com', 'name' => 'Ann', 'pending' => '0', 123 => '42', 'additional_456' => '7']);

        self::assertSame(['123' => '42', '456' => '7'], $member->additionalFields);
        self::assertTrue($member->approved);
    }

    private function config(): Config
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_numeric_field_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export(['enth_table_prefix' => ''], true) . ';');
        $config = new Config($path);
        unlink($path);
        return $config;
    }
}
