<?php

declare(strict_types=1);

/*
 Runs MemberController (Add Member, the Members page, and the Approve button)
 against a fresh in-memory SQLite database for each scenario in a JSON file,
 and prints what happened as JSON. MemberControllerAddTest runs this in a
 child PHP process so mail() can be pointed at capture-mail.php through
 -d sendmail_path, which can't be changed at runtime, and error_log() at a
 file through -d error_log.

 Usage: php -d sendmail_path="<php> capture-mail.php <mail file>" -d error_log=<log file>
            member-add-harness.php <scenarios.json> <mail file> <log file>

 A scenario: {"schema": "owned"|"enth", "action": "add"|"get"|"overview"|"approve",
   "post": {...}, "query": {...},
   "existing": [{"listing": 1, "email": "...", "status": "pending"|"approved"|"rejected", "extra": {"field": "value"}}],
   "templates": [{"fanlisting_id": 1, "type": "approve", "enabled": 1, "subject": "...", "body": "..."}],
   "smtp_fail": bool, "fail_on": "regex", "fail_error_info": ["23000", 1062, "..."]}

 The fixture's listings: 1 Moon (every field type), 2 Sun (shares a field name
 with Moon), 3 Closed, 4 Adopted Out, 5 with no member table, 6 Star (a field
 named "123", and a radio named like Moon's).
*/

use Luster\Controllers\Admin\MemberController;
use Luster\Core\Application;
use Luster\Core\Database;
use Luster\Core\Request;
use Luster\Tests\Unit\Support\TracingPdo;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

[, $scenarioFile, $mailFile, $logFile] = $argv + [null, '', '', ''];
if (!is_file((string) $scenarioFile) || $mailFile === '' || $logFile === '') {
    fwrite(STDERR, "usage: member-add-harness.php <scenarios.json> <mail file> <log file>\n");
    exit(2);
}

// Never risk real mail: refuse to run unless mail() goes to the capture script.
if (!str_contains((string) ini_get('sendmail_path'), 'capture-mail.php')) {
    fwrite(STDERR, "member-add-harness.php: sendmail_path must point at capture-mail.php\n");
    exit(3);
}

if (!defined('LUSTER_BASE')) {
    define('LUSTER_BASE', $root);
}

/**
 * Every field type, the select alias and a required one, as saveFields() stores
 * them. Each of tags' five options is 60 letters: any four of them joined fit in
 * 255 characters, all five don't.
 */
function moonFields(): array
{
    $tags = implode(', ', array_map(static fn(string $c): string => str_repeat($c, 60), ['a', 'b', 'c', 'd', 'e']));
    return [
        ['name' => 'fave',     'label' => 'Favourite song', 'type' => 'text',     'required' => 0, 'options' => ''],
        ['name' => 'mood',     'label' => 'Mood',           'type' => 'dropdown', 'required' => 0, 'options' => 'Happy, Sad'],
        ['name' => 'era',      'label' => 'Era',            'type' => 'select',   'required' => 0, 'options' => 'Old,New'],
        ['name' => 'pet',      'label' => 'Pet',            'type' => 'radio',    'required' => 0, 'options' => 'Cat, Dog'],
        ['name' => 'likes',    'label' => 'Likes',          'type' => 'checkbox', 'required' => 0, 'options' => 'Red, Blue, , Green'],
        ['name' => 'tags',     'label' => 'Tags',           'type' => 'checkbox', 'required' => 0, 'options' => $tags],
        ['name' => 'agree',    'label' => 'Agree',          'type' => 'checkbox', 'required' => 0, 'options' => ''],
        ['name' => 'bio',      'label' => 'Bio',            'type' => 'textarea', 'required' => 0, 'options' => ''],
        ['name' => 'musthave', 'label' => 'Must have',      'type' => 'text',     'required' => 1, 'options' => ''],
    ];
}

const SUN_FIELDS = [
    ['name' => 'fave',    'label' => 'Favourite ray', 'type' => 'text', 'required' => 0, 'options' => ''],
    ['name' => 'sunonly', 'label' => 'Sun only',      'type' => 'text', 'required' => 0, 'options' => ''],
];

/** A numeric field name (Enthusiast allows it; PHP makes it an int key) and a radio named like Moon's. */
const STAR_FIELDS = [
    ['name' => '123', 'label' => 'Lucky number', 'type' => 'text',  'required' => 0, 'options' => ''],
    ['name' => 'pet', 'label' => 'Star pet',     'type' => 'radio', 'required' => 0, 'options' => 'Cat, Dog'],
];

/** @return array{0: Application, 1: TracingPdo, 2: array<int,string>} */
function memberAddFixture(string $root, array $s): array
{
    $isOwned = $s['schema'] === 'owned';

    $cfg = [
        'enth_table_prefix' => $isOwned ? '' : 'enth_',
        'app_url'           => 'https://luster.test',
        'site_url'          => 'https://fans.test',
        'site_name'         => 'Test Collective',
        'owner_name'        => 'Owner',
        'owner_email'       => 'owner@example.com',
    ];
    if (!empty($s['smtp_fail'])) {
        // Nothing listens on port 1: the connection is refused at once.
        $cfg += ['smtp_enabled' => '1', 'smtp_host' => '127.0.0.1', 'smtp_port' => 1];
    }
    $cfgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_member_add_harness_' . uniqid('', true) . '.php';
    file_put_contents($cfgPath, '<?php return ' . var_export($cfg, true) . ';');
    $app = new Application($root, $cfgPath);
    unlink($cfgPath);

    $pdo = TracingPdo::memory();
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'));
    $prop = new ReflectionProperty(Database::class, 'pdo');
    $prop->setAccessible(true);
    $prop->setValue($app->getDatabase(), $pdo);

    // listing id => [title, member table or '', fields]. Moon has its own
    // address, so an owner new-member notification would have somewhere to go.
    $listings = [
        1 => ['Moon FL',    'fl_moon',    moonFields()],
        2 => ['Sun FL',     'fl_sun',     SUN_FIELDS],
        3 => ['Closed FL',  'fl_closed',  []],
        4 => ['Adopted FL', 'fl_adopted', []],
        5 => ['Tableless',  '',           []],
        6 => ['Star FL',    'fl_star',    STAR_FIELDS],
    ];

    if ($isOwned) {
        $pdo->exec("CREATE TABLE owned (
            listingid INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', status INTEGER NOT NULL DEFAULT 2,
            dbtable TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '', catid TEXT NOT NULL DEFAULT '',
            category_id INTEGER NULL, opened TEXT NULL)");
    } else {
        $pdo->exec("CREATE TABLE enth_fanlistings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', dbprefix TEXT NOT NULL DEFAULT '',
            additional_info TEXT NOT NULL DEFAULT '', category INTEGER NOT NULL DEFAULT 0,
            upcoming INTEGER NOT NULL DEFAULT 0, trouble INTEGER NOT NULL DEFAULT 0, opened TEXT NULL)");
    }

    $memberTables = [];
    foreach ($listings as $id => [$title, $table, $fields]) {
        $slug  = strtolower(strtok($title, ' '));
        $url   = 'https://fans.test/' . $slug . '/';
        $email = $id === 1 ? 'moon@fans.test' : '';
        if ($isOwned) {
            $pdo->prepare('INSERT INTO owned (listingid, title, url, email, subject, dbtable, additional) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $title, $url, $email, 'The ' . ucfirst($slug), $table, $fields !== [] ? serialize($fields) : '']);
        } else {
            $pdo->prepare('INSERT INTO enth_fanlistings (id, name, url, email, subject, dbprefix, additional_info) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $title, $url, $email, 'The ' . ucfirst($slug), $table !== '' ? $table . '_' : '', $fields !== [] ? serialize($fields) : '']);
        }
        if ($table === '') {
            continue;
        }
        $extraCols = '';
        foreach ($fields as $f) {
            // Quoted: SQLite won't take a bare "123" as a column name.
            $extraCols .= ', "' . ($isOwned ? $f['name'] : 'additional_' . $f['name']) . "\" TEXT NOT NULL DEFAULT ''";
        }
        if ($isOwned) {
            $pdo->exec("CREATE TABLE {$table} (
                email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
                url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
                showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL{$extraCols})");
            $memberTables[$id] = $table;
        } else {
            $pdo->exec("CREATE TABLE {$table}_members (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '',
                country TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', approved INTEGER NOT NULL DEFAULT 1,
                password TEXT NOT NULL DEFAULT '', showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1,
                added TEXT NULL, updated TEXT NULL{$extraCols})");
            $memberTables[$id] = $table . '_members';
        }
    }

    $pdo->exec("CREATE TABLE lstr_listing_lifecycle (
        fanlisting_id INTEGER PRIMARY KEY, lifecycle_status TEXT NOT NULL, archived_at TEXT NULL,
        legacy_upcoming INTEGER NOT NULL DEFAULT 0, legacy_trouble INTEGER NOT NULL DEFAULT 0,
        adopted_to_name TEXT NULL, adopted_to_url TEXT NULL, archive_note TEXT NULL)");
    $pdo->exec("INSERT INTO lstr_listing_lifecycle (fanlisting_id, lifecycle_status, archived_at) VALUES (3, 'closed', '2025-01-01'), (4, 'adopted_out', NULL)");

    $pdo->exec("CREATE TABLE lstr_fanlisting_settings (
        fanlisting_id INTEGER PRIMARY KEY, last_updated TEXT NULL, listing_type TEXT NULL,
        form_url_join TEXT NOT NULL DEFAULT '', form_url_update TEXT NOT NULL DEFAULT '', form_url_reset TEXT NOT NULL DEFAULT '',
        form_url_codes_submit TEXT NOT NULL DEFAULT '', codes_submit_enabled INTEGER NOT NULL DEFAULT 1,
        db_host TEXT NOT NULL DEFAULT '', db_name TEXT NOT NULL DEFAULT '', db_user TEXT NOT NULL DEFAULT '',
        db_pass TEXT NOT NULL DEFAULT '', country_field_enabled INTEGER NULL)");
    $pdo->exec("INSERT INTO lstr_fanlisting_settings (fanlisting_id, last_updated, listing_type, form_url_reset)
                VALUES (1, '2020-01-01', 'fanlisting', 'https://fans.test/moon/reset.php'), (2, '2020-01-01', 'fanlisting', '')");
    $pdo->exec("CREATE TABLE lstr_site_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NULL, updated_at TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_email_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, type TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 1, subject TEXT NOT NULL DEFAULT '', body TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE lstr_activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, admin_user_id INTEGER NULL, action TEXT NOT NULL,
        description TEXT NOT NULL, ip_address TEXT NULL, created_at TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_member_link_checks (
        id INTEGER PRIMARY KEY AUTOINCREMENT, listing_id INTEGER NOT NULL, member_email TEXT NOT NULL,
        member_name TEXT NOT NULL DEFAULT '', member_url TEXT NOT NULL DEFAULT '', link_status TEXT NOT NULL DEFAULT 'unchecked',
        link_redirect_url TEXT NULL, link_checked_at TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_pending_updates (
        id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, member_email TEXT NOT NULL,
        changes TEXT NOT NULL DEFAULT '', submitted_at TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_fan_password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, email TEXT NOT NULL,
        token_hash TEXT NOT NULL, expires_at TEXT NOT NULL, created_at TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_collective_update_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT NOT NULL, email TEXT NOT NULL,
        expires_at TEXT NOT NULL, used_at TEXT NULL, created_at TEXT NULL)");

    foreach ($s['templates'] ?? [] as $t) {
        $pdo->prepare('INSERT INTO lstr_email_templates (fanlisting_id, type, enabled, subject, body) VALUES (?, ?, ?, ?, ?)')
            ->execute([$t['fanlisting_id'], $t['type'], $t['enabled'] ?? 1, $t['subject'] ?? '', $t['body'] ?? '']);
    }
    foreach ($s['existing'] ?? [] as $m) {
        $status = $m['status'];
        $cols   = $isOwned
            ? ['pending' => ['approved' => 0, 'pending' => 1, 'rejected' => 2][$status]]
            : ['approved' => $status === 'approved' ? 1 : 0];
        $row = ['email' => $m['email'], 'name' => $m['name'] ?? 'Existing Fan', 'url' => $m['url'] ?? '', 'country' => $m['country'] ?? ''] + $cols;
        foreach ($m['extra'] ?? [] as $field => $value) {
            $row[($isOwned ? '' : 'additional_') . $field] = $value;
        }
        $pdo->prepare('INSERT INTO ' . $memberTables[$m['listing']] . ' ("' . implode('", "', array_keys($row)) . '") VALUES ('
            . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    }

    // Only what the controller does is traced, never the fixture.
    $pdo->statements = [];
    if (isset($s['fail_on'])) {
        $pdo->failOn = $s['fail_on'];
    }
    if (isset($s['fail_error_info'])) {
        $pdo->failErrorInfo = $s['fail_error_info'];
    }

    return [$app, $pdo, $memberTables];
}

function fileFrom(string $path, int $offset): string
{
    clearstatcache(true, $path);
    if (!is_file($path) || filesize($path) <= $offset) {
        return '';
    }
    return (string) file_get_contents($path, false, null, $offset);
}

$results = [];
foreach (json_decode((string) file_get_contents($scenarioFile), true, 512, JSON_THROW_ON_ERROR) as $s) {
    clearstatcache();
    $mailStart = is_file($mailFile) ? filesize($mailFile) : 0;
    $logStart  = is_file($logFile) ? filesize($logFile) : 0;

    [$app, $pdo, $memberTables] = memberAddFixture($root, $s);
    $_SESSION = ['lstr_admin_id' => 1, 'lstr_admin_username' => 'admin'];

    $isPost  = in_array($s['action'], ['add', 'approve'], true);
    $request = new Request($s['query'] ?? [], $s['post'] ?? [], [
        'REQUEST_METHOD' => $isPost ? 'POST' : 'GET', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'luster.test',
    ], [], []);

    $error    = null;
    $response = null;
    try {
        $controller = new MemberController($app);
        $response   = match ($s['action']) {
            'add', 'get' => $controller->add($request, []),
            'overview'   => $controller->overview($request, []),
            'approve'    => $controller->approve($request, ['id' => (string) ($s['listing'] ?? 1)]),
        };
    } catch (\Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }

    // Simulated failures were for the controller; reading the results mustn't hit them.
    $pdo->failOn = null;

    $members = [];
    foreach ($memberTables as $id => $table) {
        $members[$id] = $pdo->query("SELECT * FROM {$table}")->fetchAll();
    }

    $results[] = [
        'error'         => $error,
        'status'        => $response?->getStatus(),
        'redirect'      => $response?->isRedirect() ? $response->getRedirectUrl() : null,
        'body'          => $response !== null && !$response->isRedirect() ? $response->getBody() : '',
        'flash_success' => $_SESSION['lstr_flash_success'] ?? null,
        'flash_error'   => $_SESSION['lstr_flash_error'] ?? null,
        'members'       => $members,
        'statements'    => $pdo->statements,
        'activity'      => $pdo->query('SELECT action, description FROM lstr_activity_log')->fetchAll(),
        'link_checks'   => $pdo->query('SELECT listing_id, member_email, member_name, member_url FROM lstr_member_link_checks')->fetchAll(),
        'mail'          => fileFrom($mailFile, $mailStart),
        'log'           => fileFrom($logFile, $logStart),
    ];
}

echo json_encode($results, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
