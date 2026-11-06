<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\JoinedController;
use Luster\Core\Application;
use Luster\Core\Database;
use Luster\Core\Request;
use Luster\Core\Response;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real Add and Edit Joined saves. Custom has to be a choice there, and typing
 * a new network under "Other" has to save a real category under that network, the way
 * the owned-listing form always has. It used to save the network's name as a category
 * under Custom instead, which never made the network a choice.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class JoinedCustomCategoryTest extends TestCase
{
    private PDO $pdo;
    private Application $app;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        if (!defined('LUSTER_BASE')) {
            define('LUSTER_BASE', $root);
        }

        $cfgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_joined_ctl_' . uniqid('', true) . '.php';
        file_put_contents($cfgPath, '<?php return ' . var_export([
            'enth_table_prefix' => '',
            'app_url'           => 'https://luster.test',
            'site_url'          => 'https://fans.test',
            'site_name'         => 'Test Collective',
        ], true) . ';');
        $this->app = new Application($root, $cfgPath);
        unlink($cfgPath);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
        $prop = new \ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($this->app->getDatabase(), $this->pdo);

        $this->pdo->exec("CREATE TABLE lstr_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL DEFAULT '',
            parent_id INTEGER NULL, source TEXT NOT NULL DEFAULT 'custom',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (source, name), UNIQUE (source, slug))");
        $this->pdo->exec("CREATE TABLE lstr_joined (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, url TEXT NOT NULL, image TEXT NULL,
            category TEXT NOT NULL DEFAULT '', categories_json TEXT NULL, date_joined TEXT NULL,
            active INTEGER NOT NULL DEFAULT 1, status TEXT NOT NULL DEFAULT 'approved', sort_order INTEGER NOT NULL DEFAULT 0,
            category_id INTEGER NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, description TEXT NULL, comments TEXT NULL,
            link_status TEXT NOT NULL DEFAULT 'unchecked', link_checked_at TEXT NULL, link_redirect_url TEXT NULL,
            listed_status TEXT NOT NULL DEFAULT 'unchecked', listed_checked_at TEXT NULL, listed_members_url TEXT NULL)");
        $this->pdo->exec('CREATE TABLE lstr_site_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NULL, updated_at TEXT NULL)');
        $this->pdo->exec("CREATE TABLE lstr_activity_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT, admin_user_id INTEGER NULL, action TEXT NOT NULL DEFAULT '',
            description TEXT NULL, ip_address TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $this->pdo->exec("CREATE TABLE owned (
            listingid INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', status INTEGER NOT NULL DEFAULT 2,
            imagefile TEXT NULL, category_id INTEGER NULL, opened TEXT NULL, catid TEXT NOT NULL DEFAULT '',
            additional TEXT NOT NULL DEFAULT '', dbtable TEXT NOT NULL DEFAULT '')");
        $this->pdo->exec("INSERT INTO lstr_categories (name, slug, source) VALUES ('Games: Characters 0-M', 'games', 'custom')");

        $_SESSION = ['lstr_admin_id' => 1, 'lstr_admin_username' => 'admin'];
    }

    /** @param array<string,mixed> $rows */
    private function save(array $rows, array $fields = ['name' => 'Moon FL', 'url' => 'https://fans.test/moon'], ?int $id = null): Response
    {
        $request = new Request([], $fields + $rows + ['_csrf' => 'token', 'status' => 'approved'], [
            'REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'luster.test',
        ], [], []);
        $controller = new JoinedController($this->app);

        return $id === null ? $controller->add($request, []) : $controller->edit($request, ['id' => (string) $id]);
    }

    /** @return list<array{source: string, name: string}> */
    private function categories(): array
    {
        return $this->pdo->query('SELECT source, name FROM lstr_categories ORDER BY id')->fetchAll();
    }

    /** @return list<array{network: string, name: string}> */
    private function savedEntries(): array
    {
        return json_decode((string) $this->pdo->query('SELECT categories_json FROM lstr_joined ORDER BY id DESC LIMIT 1')->fetchColumn(), true);
    }

    private const GAMES = ['source' => 'custom', 'name' => 'Games: Characters 0-M'];

    public function testTheFormOffersCustomAndItsCategories(): void
    {
        $page = (new JoinedController($this->app))
            ->add(new Request([], [], ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'luster.test'], [], []), [])
            ->getBody();

        self::assertSame(1, preg_match("/data-custom='([^']*)'/", $page, $networks));
        self::assertSame(['Custom'], json_decode(html_entity_decode($networks[1]), true));
        self::assertSame(1, preg_match("/data-custom-cats='([^']*)'/", $page, $cats));
        $decoded = json_decode(html_entity_decode($cats[1]), true);
        self::assertSame('Games: Characters 0-M', $decoded['Custom'][0]['name'] ?? null);
    }

    public function testPickingACustomCategorySavesItWithoutMakingAnother(): void
    {
        $this->save(['cat_network' => ['Custom'], 'cat_name' => ['Games: Characters 0-M'], 'cat_network_other' => ['']]);

        self::assertSame([['network' => 'Custom', 'name' => 'Games: Characters 0-M']], $this->savedEntries());
        self::assertSame([self::GAMES], $this->categories());
    }

    /** A saved row can name a category that's been deleted. Saving the form can't bring it back. */
    public function testAPickedRowNeverMakesACategory(): void
    {
        $this->save(['cat_network' => ['Custom', 'TFL'], 'cat_name' => ['Deleted One', 'Also Deleted'], 'cat_network_other' => ['', '']]);

        self::assertCount(2, $this->savedEntries());
        self::assertSame([self::GAMES], $this->categories());
    }

    public function testATypedNetworkBecomesARealCategoryUnderIt(): void
    {
        $this->save(['cat_network' => ['other'], 'cat_name' => ['Ships'], 'cat_network_other' => ['My Network']]);

        self::assertSame([['network' => 'My Network', 'name' => 'Ships']], $this->savedEntries());
        self::assertSame([self::GAMES, ['source' => 'My Network', 'name' => 'Ships']], $this->categories());
    }

    public function testTypingCustomMakesNoCategoryCalledCustom(): void
    {
        $this->save(['cat_network' => ['other'], 'cat_name' => ['Games: Characters 0-M'], 'cat_network_other' => ['custom']]);

        self::assertSame([['network' => 'Custom', 'name' => 'Games: Characters 0-M']], $this->savedEntries());
        self::assertSame([self::GAMES], $this->categories());
    }

    public function testATypedNetworkWithNoCategoryNameMakesNothing(): void
    {
        $this->save(['cat_network' => ['other'], 'cat_name' => [''], 'cat_network_other' => ['My Network']]);

        self::assertSame([self::GAMES], $this->categories());
    }

    public function testARefusedSaveLeavesNoNewCategory(): void
    {
        $response = $this->save(
            ['cat_network' => ['other'], 'cat_name' => ['Ships'], 'cat_network_other' => ['My Network']],
            ['name' => '', 'url' => 'not a link'],
        );

        self::assertFalse($response->isRedirect(), 'The form comes back with an error');
        self::assertSame([self::GAMES], $this->categories());
    }

    public function testARefusedEditLeavesNoNewCategoryEither(): void
    {
        $this->pdo->exec("INSERT INTO lstr_joined (id, name, url) VALUES (5, 'Moon FL', 'https://fans.test/moon')");

        $response = $this->save(
            ['cat_network' => ['other'], 'cat_name' => ['Ships'], 'cat_network_other' => ['My Network']],
            ['name' => '', 'url' => 'not a link'],
            5,
        );

        self::assertFalse($response->isRedirect());
        self::assertSame([self::GAMES], $this->categories());
    }

    public function testAnEditThatSavesAddsTheCategoryToo(): void
    {
        $this->pdo->exec("INSERT INTO lstr_joined (id, name, url) VALUES (5, 'Moon FL', 'https://fans.test/moon')");

        $response = $this->save(
            ['cat_network' => ['other'], 'cat_name' => ['Ships'], 'cat_network_other' => ['My Network']],
            ['name' => 'Moon FL', 'url' => 'https://fans.test/moon'],
            5,
        );

        self::assertTrue($response->isRedirect());
        self::assertSame([self::GAMES, ['source' => 'My Network', 'name' => 'Ships']], $this->categories());
    }

    private const SHIPS = ['source' => 'My Network', 'name' => 'Ships'];

    /** A saved row keeps its deleted category's name, and typing on the same network can't bring that back. */
    public function testTypingACategoryCantBringBackADeletedOneOnTheSameNetwork(): void
    {
        $this->save(['cat_network' => ['Custom', 'other'], 'cat_name' => ['Deleted One', 'New One'], 'cat_network_other' => ['', 'custom']]);

        self::assertSame([
            ['network' => 'Custom', 'name' => 'Deleted One'],
            ['network' => 'Custom', 'name' => 'New One'],
        ], $this->savedEntries());
        self::assertSame([self::GAMES, ['source' => 'custom', 'name' => 'New One']], $this->categories());
    }

    public function testAPickedRowOnATypedNetworkStaysAPickedRow(): void
    {
        $this->save(['cat_network' => ['My Network', 'other'], 'cat_name' => ['Deleted One', 'Ships'], 'cat_network_other' => ['', 'My Network']]);

        self::assertCount(2, $this->savedEntries());
        self::assertSame([self::GAMES, self::SHIPS], $this->categories());
    }

    public function testTypingTheNameOfADeletedCategoryMakesItAgain(): void
    {
        $this->save(['cat_network' => ['Custom', 'other'], 'cat_name' => ['Wanted', 'Wanted'], 'cat_network_other' => ['', 'Custom']]);

        self::assertSame([['network' => 'Custom', 'name' => 'Wanted']], $this->savedEntries());
        self::assertSame([self::GAMES, ['source' => 'custom', 'name' => 'Wanted']], $this->categories());
    }

    // ------------------------------------------------------------------
    // A refused save keeps a typed row typed
    // ------------------------------------------------------------------

    /** @return array<int, array<string, string>> What the form's script builds its rows from. */
    private function rowsOnThePage(string $page): array
    {
        self::assertSame(1, preg_match("/data-existing='([^']*)'/", $page, $existing));

        return json_decode(html_entity_decode($existing[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
    }

    private const REFUSED = ['name' => '', 'url' => 'not a link'];

    public function testARefusedAddKeepsATypedRowTyped(): void
    {
        $page = $this->save(['cat_network' => ['other'], 'cat_name' => ['Ships'], 'cat_network_other' => ['My Network']], self::REFUSED)->getBody();

        self::assertSame([['network' => 'other', 'name' => 'Ships', 'other' => 'My Network']], $this->rowsOnThePage($page));
        self::assertSame([self::GAMES], $this->categories(), 'Nothing is saved yet');
    }

    public function testARefusedEditKeepsATypedRowTyped(): void
    {
        $this->pdo->exec("INSERT INTO lstr_joined (id, name, url) VALUES (5, 'Moon FL', 'https://fans.test/moon')");

        $page = $this->save(['cat_network' => ['other'], 'cat_name' => ['Ships'], 'cat_network_other' => ['My Network']], self::REFUSED, 5)->getBody();

        self::assertSame([['network' => 'other', 'name' => 'Ships', 'other' => 'My Network']], $this->rowsOnThePage($page));
    }

    public function testARefusedSaveDoesntTurnAPickedRowIntoATypedOne(): void
    {
        $page = $this->save(
            ['cat_network' => ['Custom', 'other'], 'cat_name' => ['Deleted One', 'New One'], 'cat_network_other' => ['', 'custom']],
            self::REFUSED,
        )->getBody();

        self::assertSame([
            ['network' => 'Custom', 'name' => 'Deleted One'],
            ['network' => 'other', 'name' => 'New One', 'other' => 'custom'],
        ], $this->rowsOnThePage($page));
    }

    /** What the refused page shows, sent again once the name is fixed, the way the form sends it. */
    public function testCorrectingARefusedSaveStillMakesTheTypedCategory(): void
    {
        $rows = $this->rowsOnThePage($this->save(
            ['cat_network' => ['Custom', 'other'], 'cat_name' => ['Deleted One', 'Ships'], 'cat_network_other' => ['', 'My Network']],
            self::REFUSED,
        )->getBody());

        $response = $this->save([
            'cat_network'       => array_column($rows, 'network'),
            'cat_name'          => array_column($rows, 'name'),
            'cat_network_other' => array_map(static fn(array $row): string => $row['other'] ?? '', $rows),
        ]);

        self::assertTrue($response->isRedirect());
        self::assertSame([
            ['network' => 'Custom', 'name' => 'Deleted One'],
            ['network' => 'My Network', 'name' => 'Ships'],
        ], $this->savedEntries());
        self::assertSame([self::GAMES, self::SHIPS], $this->categories());
    }

    public function testASavedRowOnAFreshEditIsNeverTyped(): void
    {
        $this->pdo->exec("INSERT INTO lstr_joined (id, name, url, categories_json, category) VALUES (5, 'Moon FL', 'https://fans.test/moon',
            '[{\"network\":\"Custom\",\"name\":\"Deleted One\"}]', 'Deleted One')");

        $page = (new JoinedController($this->app))
            ->edit(new Request([], [], ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'luster.test'], [], []), ['id' => '5'])
            ->getBody();

        self::assertSame([['network' => 'Custom', 'name' => 'Deleted One']], $this->rowsOnThePage($page));
    }

    /** A network column holds 50 characters. Strict MySQL would refuse more and cost the owner the whole save. */
    public function testANameTooLongForTheCategoryTableStillSavesTheJoinedListing(): void
    {
        $tooLong = str_repeat('n', 51);

        $response = $this->save(['cat_network' => ['other'], 'cat_name' => ['Ships'], 'cat_network_other' => [$tooLong]]);

        self::assertTrue($response->isRedirect());
        self::assertSame([['network' => $tooLong, 'name' => 'Ships']], $this->savedEntries());
        self::assertSame([self::GAMES], $this->categories());
    }

    public function testACategoryNameTooLongForTheTableStillSavesTheJoinedListing(): void
    {
        $tooLong = str_repeat('c', 151);

        $response = $this->save(['cat_network' => ['other'], 'cat_name' => [$tooLong], 'cat_network_other' => ['My Network']]);

        self::assertTrue($response->isRedirect());
        self::assertSame([self::GAMES], $this->categories());
    }

    public function testASavedEditKeepsACustomCategoryOnTheForm(): void
    {
        $this->pdo->exec("INSERT INTO lstr_joined (id, name, url, categories_json, category) VALUES (5, 'Moon FL', 'https://fans.test/moon',
            '[{\"network\":\"Custom\",\"name\":\"Games: Characters 0-M\"}]', 'Games: Characters 0-M')");

        $page = (new JoinedController($this->app))
            ->edit(new Request([], [], ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'luster.test'], [], []), ['id' => '5'])
            ->getBody();

        self::assertSame(1, preg_match("/data-existing='([^']*)'/", $page, $existing));
        self::assertSame([['network' => 'Custom', 'name' => 'Games: Characters 0-M']], json_decode(html_entity_decode($existing[1]), true));
    }
}
