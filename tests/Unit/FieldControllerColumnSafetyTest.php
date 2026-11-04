<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\FieldController;
use Luster\Core\Application;
use Luster\Core\Database;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\FieldRepo;
use Luster\Tests\Unit\Support\TracingPdo;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The real Add Field and Delete Field actions on both table layouts: a new
 * field can't take a name that would clash, and deleting a field only ever
 * drops that field's own column. Delete Field used to drop whatever column had
 * the field's name, a built-in one included, along with every member's data.
 */
final class FieldControllerColumnSafetyTest extends TestCase
{
    private const OWNED_COLUMNS = "email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
        url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
        showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL";

    private const ENTH_COLUMNS = "id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '',
        country TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', approved INTEGER NOT NULL DEFAULT 1,
        password TEXT NOT NULL DEFAULT '', showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1,
        added TEXT NULL, updated TEXT NULL";

    private TracingPdo $pdo;
    private Application $app;
    private string $logFile;
    private string $previousLog;

    protected function setUp(): void
    {
        // The controller logs database errors; keep them out of the test output.
        $this->logFile     = tempnam(sys_get_temp_dir(), 'lstr_field_log_');
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog);
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /** @param list<array<string, mixed>> $fields */
    private function owned(array $fields = [], string $extraColumns = ''): void
    {
        $this->boot('');
        $this->pdo->exec("CREATE TABLE owned (
            listingid INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', status INTEGER NOT NULL DEFAULT 2,
            dbtable TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '', catid TEXT NOT NULL DEFAULT '',
            category_id INTEGER NULL, opened TEXT NULL)");
        $this->pdo->prepare("INSERT INTO owned (listingid, title, subject, url, dbtable, additional) VALUES (1, 'Moon FL', 'The Moon', 'https://fans.test/moon/', 'fl_moon', ?)")
            ->execute([$fields !== [] ? serialize($fields) : '']);
        $this->pdo->exec('CREATE TABLE fl_moon (' . self::OWNED_COLUMNS . $extraColumns . ')');
        $this->pdo->exec("INSERT INTO fl_moon (email, name) VALUES ('ann@example.com', 'Ann')");
        $this->pdo->statements = [];
    }

    /** @param list<array<string, mixed>> $fields */
    private function enth(array $fields = [], string $extraColumns = '', string $dbprefix = 'fl_moon_'): void
    {
        $this->boot('enth_');
        $this->pdo->exec("CREATE TABLE enth_fanlistings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', dbprefix TEXT NOT NULL DEFAULT '',
            additional_info TEXT NOT NULL DEFAULT '', category INTEGER NOT NULL DEFAULT 0,
            upcoming INTEGER NOT NULL DEFAULT 0, trouble INTEGER NOT NULL DEFAULT 0, opened TEXT NULL)");
        $this->pdo->prepare("INSERT INTO enth_fanlistings (id, name, subject, url, dbprefix, additional_info) VALUES (1, 'Moon FL', 'The Moon', 'https://fans.test/moon/', ?, ?)")
            ->execute([$dbprefix, $fields !== [] ? serialize($fields) : '']);
        $this->pdo->exec('CREATE TABLE fl_moon_members (' . self::ENTH_COLUMNS . $extraColumns . ')');
        $this->pdo->exec("INSERT INTO fl_moon_members (email, name) VALUES ('ann@example.com', 'Ann')");
        $this->pdo->statements = [];
    }

    private function boot(string $enthTablePrefix): void
    {
        $cfgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_field_safety_' . uniqid('', true) . '.php';
        file_put_contents($cfgPath, '<?php return ' . var_export(['enth_table_prefix' => $enthTablePrefix, 'app_url' => 'https://luster.test'], true) . ';');
        $this->app = new Application(dirname(__DIR__, 2), $cfgPath);
        unlink($cfgPath);

        $this->pdo = TracingPdo::memory();
        $prop = new ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($this->app->getDatabase(), $this->pdo);
    }

    private function add(string $name): Response
    {
        return (new FieldController($this->app))->add(
            new Request([], ['name' => $name, 'label' => 'A label', 'type' => 'text', 'required' => '0'], ['REQUEST_METHOD' => 'POST'], [], []),
            ['fid' => '1'],
        );
    }

    private function delete(string $name): Response
    {
        return (new FieldController($this->app))->delete(new Request([], [], ['REQUEST_METHOD' => 'POST'], [], []), ['fid' => '1', 'name' => $name]);
    }

    private static function def(string $name, string $type = 'text', bool $required = false, string $options = ''): array
    {
        return ['name' => $name, 'label' => ucfirst($name), 'type' => $type, 'required' => $required, 'options' => $options];
    }

    /** @return list<string> */
    private function columns(string $table = 'fl_moon'): array
    {
        return array_map('strval', array_column($this->pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC), 'name'));
    }

    /** @return list<string> */
    private function fieldNames(): array
    {
        $repo = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        return array_map(static fn($f): string => $f->name, $repo->getFieldsForListing(1));
    }

    /** Asserts the redirect carries this message, and returns nothing. */
    private static function assertSays(string $kind, string $message, Response $response): void
    {
        self::assertSame(
            'https://luster.test/admin/listings/1/fields?' . $kind . '=' . urlencode($message),
            $response->getRedirectUrl(),
        );
    }

    // -----------------------------------------------------------------------
    // The standard layout's table
    // -----------------------------------------------------------------------

    public function testAddAndDeleteUseTheMemberTableOnTheStandardLayout(): void
    {
        $this->enth();

        self::assertSays('success', 'Field added successfully.', $this->add('fave'));
        self::assertContains('additional_fave', $this->columns('fl_moon_members'));
        self::assertSame(['fave'], $this->fieldNames());

        self::assertSays('success', 'Field deleted successfully.', $this->delete('fave'));
        self::assertNotContains('additional_fave', $this->columns('fl_moon_members'));
        self::assertSame([], $this->fieldNames());
        self::assertSame('Ann', $this->pdo->query('SELECT name FROM fl_moon_members')->fetchColumn(), 'The member stays');
    }

    public function testAnEmptyDbprefixAltersNothingOnTheStandardLayout(): void
    {
        $this->enth([self::def('fave')], ', additional_fave TEXT NULL', '');
        $this->pdo->exec('CREATE TABLE members (email TEXT, additional_fave TEXT NULL)');
        $this->pdo->statements = [];

        $added   = $this->add('other');
        $deleted = $this->delete('fave');

        self::assertStringContainsString('error=', (string) $added->getRedirectUrl());
        self::assertStringContainsString('error=', (string) $deleted->getRedirectUrl());
        self::assertSame([], $this->pdo->statementsMatching('/ALTER TABLE/'));
        self::assertSame(['email', 'additional_fave'], $this->columns('members'));
        self::assertSame(['fave'], $this->fieldNames());
    }

    // -----------------------------------------------------------------------
    // New names
    // -----------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function reservedNameProvider(): array
    {
        $names = [];
        foreach (['id', 'email', 'Email', 'EMAIL', 'name', 'country', 'url', 'pending', 'approved', 'password', 'showemail', 'showurl', 'added', 'updated',
                  'additional_fields', 'Additional_Fields', 'additional_x', 'ADDITIONAL_x'] as $name) {
            $names[$name] = [$name];
        }
        return $names;
    }

    /** @dataProvider reservedNameProvider */
    public function testAReservedNameIsRefusedOnBothLayoutsAndNothingChanges(string $name): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $this->{$layout}();
            $table  = $layout === 'owned' ? 'fl_moon' : 'fl_moon_members';
            $before = $this->columns($table);

            self::assertSays('error', "'{$name}' is a reserved name and can't be used for a field.", $this->add($name));
            self::assertSame([], $this->pdo->statementsMatching('/ALTER TABLE/'), $layout);
            self::assertSame($before, $this->columns($table), $layout);
            self::assertSame([], $this->fieldNames(), $layout);
        }
    }

    public function testAFieldDifferingOnlyInCapitalsIsRefusedBeforeAnyChange(): void
    {
        $this->owned([self::def('fave')], ', fave TEXT NULL');

        self::assertSays('error', "A field named 'fave' already exists.", $this->add('Fave'));
        self::assertSame([], $this->pdo->statementsMatching('/ALTER TABLE/'));
        self::assertSame(['fave'], $this->fieldNames());
    }

    public function testALeftoverColumnGetsAFriendlyMessage(): void
    {
        $this->owned();
        $this->pdo->failOn        = '/ADD COLUMN/';
        $this->pdo->failErrorInfo = ['42S21', 1060, "Duplicate column name 'fave'"];

        self::assertSays('error', "This listing's member table already has a column for a field called 'fave'. Try another name.", $this->add('fave'));
        self::assertSame([], $this->fieldNames(), "The definition isn't saved");
        self::assertSame('', file_get_contents($this->logFile), 'An expected refusal is not logged as an error');
    }

    /** @return array<string, array{0: ?array{0: string, 1: int, 2: string}}> */
    public static function otherDatabaseErrorProvider(): array
    {
        return [
            'no error details'      => [null],
            'another error'         => [['42000', 1064, 'Syntax error']],
            'duplicate entry'       => [['23000', 1062, 'Duplicate entry']],
            'right number only'     => [['HY000', 1060, 'Oddity']],
            'right state only'      => [['42S21', 9999, 'Oddity']],
        ];
    }

    /**
     * Only MySQL's exact duplicate-column error means "a column's already there".
     *
     * @dataProvider otherDatabaseErrorProvider
     * @param array{0: string, 1: int, 2: string}|null $errorInfo
     */
    public function testAnyOtherAddFailureIsGenericAndLogged(?array $errorInfo): void
    {
        $this->owned();
        $this->pdo->failOn        = '/ADD COLUMN/';
        $this->pdo->failErrorInfo = $errorInfo;

        self::assertSays('error', "Couldn't add this field because of a database error. Nothing was changed.", $this->add('fave'));
        self::assertSame([], $this->fieldNames());
        self::assertStringContainsString('Add Field', (string) file_get_contents($this->logFile));
    }

    // -----------------------------------------------------------------------
    // Delete: only a field's own column
    // -----------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function builtInDefinitionProvider(): array
    {
        return ['name' => ['name'], 'capitals' => ['Name'], 'email' => ['email'], 'password' => ['password'], 'pending' => ['pending']];
    }

    /**
     * An import can leave a definition named like a built-in column. Deleting it
     * used to drop that column and every member's data in it.
     *
     * @dataProvider builtInDefinitionProvider
     */
    public function testDeletingADefinitionNamedLikeABuiltInKeepsEveryColumn(string $name): void
    {
        $this->owned([self::def($name)]);
        $before = $this->columns();

        self::assertSays('success', "Field removed. Its column wasn't dropped because it's missing or belongs to another field.", $this->delete($name));

        self::assertSame($before, $this->columns());
        self::assertSame([], $this->pdo->statementsMatching('/DROP COLUMN/'));
        self::assertSame([], $this->fieldNames());
        self::assertSame(['email' => 'ann@example.com', 'name' => 'Ann'], $this->pdo->query('SELECT email, name FROM fl_moon')->fetch());
    }

    public function testDeletingABuiltInNamedDefinitionDropsItsOwnAdditionalColumn(): void
    {
        $this->owned([self::def('name')], ', additional_name TEXT NULL');

        self::assertSays('success', 'Field deleted successfully.', $this->delete('name'));

        self::assertNotContains('additional_name', $this->columns());
        self::assertContains('name', $this->columns());
        self::assertSame('Ann', $this->pdo->query('SELECT name FROM fl_moon')->fetchColumn());
        self::assertSame([], $this->fieldNames());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function claimedColumnProvider(): array
    {
        return ['an ordinary name' => ['fave', 'additional_fave'], 'a built-in name' => ['name', 'additional_name']];
    }

    /**
     * A stale field's fallback column can belong to another field. Deleting it
     * used to drop that column, and the other field's answers with it.
     *
     * @dataProvider claimedColumnProvider
     */
    public function testDeletingAStaleFieldKeepsAnotherFieldsColumn(string $stale, string $other): void
    {
        $this->owned([self::def($stale), self::def($other)], ", {$other} TEXT NULL");
        $this->pdo->exec("UPDATE fl_moon SET {$other} = 'The other answer'");

        self::assertSays('success', "Field removed. Its column wasn't dropped because it's missing or belongs to another field.", $this->delete($stale));

        self::assertContains($other, $this->columns());
        self::assertSame('The other answer', $this->pdo->query("SELECT {$other} FROM fl_moon")->fetchColumn());
        self::assertSame([], $this->pdo->statementsMatching('/DROP COLUMN/'));
        self::assertSame([$other], $this->fieldNames(), 'The other field stays');
    }

    public function testDeletingTheFieldThatOwnsTheColumnDropsIt(): void
    {
        $this->owned([self::def('fave'), self::def('additional_fave')], ', additional_fave TEXT NULL');

        self::assertSays('success', 'Field deleted successfully.', $this->delete('additional_fave'));

        self::assertNotContains('additional_fave', $this->columns());
        self::assertSame(['fave'], $this->fieldNames());
    }

    public function testDeletingAFieldOnABareColumnLeavesItsFallbackNeighbourAlone(): void
    {
        $this->owned([self::def('fave'), self::def('additional_fave')], ', fave TEXT NULL, additional_fave TEXT NULL');

        self::assertSays('success', 'Field deleted successfully.', $this->delete('fave'));

        self::assertNotContains('fave', $this->columns());
        self::assertContains('additional_fave', $this->columns());
    }

    public function testTwoDefinitionsClaimingOneColumnKeepIt(): void
    {
        $this->owned([self::def('Fave'), self::def('fave')], ', fave TEXT NULL');

        self::assertSays('success', "Field removed. Its column wasn't dropped because it's missing or belongs to another field.", $this->delete('Fave'));

        self::assertContains('fave', $this->columns());
        self::assertSame(['fave'], $this->fieldNames());
    }

    /** A definition whose column is gone used to end in an uncaught error, so it could never be removed. */
    public function testAStaleFieldCanBeDeletedOnBothLayouts(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $this->{$layout}([self::def('gone')]);

            self::assertSays('success', "Field removed. Its column wasn't dropped because it's missing or belongs to another field.", $this->delete('gone'));
            self::assertSame([], $this->fieldNames(), $layout);
            self::assertSame([], $this->pdo->statementsMatching('/DROP COLUMN/'), $layout);
        }
    }

    public function testOnTheStandardLayoutABuiltInNamedFieldOnlyUsesItsPrefixedColumn(): void
    {
        $this->enth([self::def('name')], ', additional_name TEXT NULL');

        self::assertSays('success', 'Field deleted successfully.', $this->delete('name'));

        self::assertNotContains('additional_name', $this->columns('fl_moon_members'));
        self::assertContains('name', $this->columns('fl_moon_members'));
    }

    // -----------------------------------------------------------------------
    // Delete: failures
    // -----------------------------------------------------------------------

    public function testAFailedColumnReadChangesNothing(): void
    {
        $this->owned([self::def('fave')], ', fave TEXT NULL');
        $this->pdo->failOn = '/PRAGMA table_info/';

        self::assertSays('error', "Couldn't delete this field because of a database error. Nothing was changed.", $this->delete('fave'));

        $this->pdo->failOn = null;
        self::assertContains('fave', $this->columns());
        self::assertSame(['fave'], $this->fieldNames());
        self::assertSame([], $this->pdo->statementsMatching('/DROP COLUMN|UPDATE owned/'));
        self::assertStringContainsString('Delete Field', (string) file_get_contents($this->logFile));
    }

    public function testAFailedDropLeavesTheDefinition(): void
    {
        $this->owned([self::def('fave')], ', fave TEXT NULL');
        $this->pdo->failOn = '/DROP COLUMN/';

        self::assertSays('error', "Couldn't delete this field because of a database error. Nothing was changed.", $this->delete('fave'));

        self::assertSame(['fave'], $this->fieldNames());
        self::assertContains('fave', $this->columns());
        self::assertSame([], $this->pdo->statementsMatching('/UPDATE owned/'));
    }

    public function testIfOnlyTheDefinitionCantBeSavedDeletingAgainFinishesTheJob(): void
    {
        $this->owned([self::def('fave')], ', fave TEXT NULL');
        $this->pdo->failOn = '/UPDATE owned/';

        self::assertSays('error', "This field's definition couldn't be removed because of a database error. Try deleting it again.", $this->delete('fave'));
        self::assertNotContains('fave', $this->columns());
        self::assertSame(['fave'], $this->fieldNames());

        $this->pdo->failOn = null;
        self::assertSays('success', "Field removed. Its column wasn't dropped because it's missing or belongs to another field.", $this->delete('fave'));
        self::assertSame([], $this->fieldNames());
    }

    // -----------------------------------------------------------------------
    // Delete: the other fields
    // -----------------------------------------------------------------------

    /** Deleting a field used to wipe every other field's choices, which could stop anyone joining. */
    public function testDeletingAFieldKeepsEveryDetailOfTheOthers(): void
    {
        $this->owned([
            self::def('mood', 'dropdown', true, 'Happy, Sad'),
            self::def('pet'),
            self::def('likes', 'checkbox', false, 'Tea, Cake'),
        ], ', mood TEXT NULL, pet TEXT NULL, likes TEXT NULL');

        self::assertSays('success', 'Field deleted successfully.', $this->delete('pet'));

        $repo   = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        $fields = $repo->getFieldsForListing(1);
        self::assertCount(2, $fields);

        self::assertSame(['mood', 'Mood', 'dropdown', true, 'Happy, Sad'], [$fields[0]->name, $fields[0]->label, $fields[0]->type, $fields[0]->required, $fields[0]->options]);
        self::assertSame(['likes', 'Likes', 'checkbox', false, 'Tea, Cake'], [$fields[1]->name, $fields[1]->label, $fields[1]->type, $fields[1]->required, $fields[1]->options]);
    }

    public function testDeletingAFieldThatIsNotThereChangesNothing(): void
    {
        $this->owned([self::def('fave')], ', fave TEXT NULL');

        self::assertSays('error', 'Field not found.', $this->delete('other'));
        self::assertSame(['fave'], $this->fieldNames());
        self::assertContains('fave', $this->columns());
    }
}
