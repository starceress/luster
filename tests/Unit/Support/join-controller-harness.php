<?php

declare(strict_types=1);

/*
 Runs the real JoinController::submit() once against an in-memory SQLite
 database and prints what happened as JSON. JoinControllerSubmitTest runs this
 in a child PHP process (so mail() can be pointed at capture-mail.php through
 -d sendmail_path, which can't be changed at runtime).

 Usage: php -d sendmail_path="<php> capture-mail.php <file>" join-controller-harness.php <owned|enth> <chosen|generated>
*/

use Luster\Controllers\Public\JoinController;
use Luster\Core\Application;
use Luster\Core\Database;
use Luster\Core\Request;
use Luster\Tests\Unit\Support\TracingPdo;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

$schema       = $argv[1] ?? '';
$passwordMode = $argv[2] ?? '';
if (!in_array($schema, ['owned', 'enth'], true) || !in_array($passwordMode, ['chosen', 'generated'], true)) {
    fwrite(STDERR, "usage: join-controller-harness.php <owned|enth> <chosen|generated>\n");
    exit(2);
}

// Never risk real mail: refuse to run unless mail() goes to the capture script.
if (!str_contains((string) ini_get('sendmail_path'), 'capture-mail.php')) {
    fwrite(STDERR, "join-controller-harness.php: sendmail_path must point at capture-mail.php\n");
    exit(3);
}

if (!defined('LUSTER_BASE')) {
    define('LUSTER_BASE', $root);
}

$isOwned = $schema === 'owned';

$cfgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_join_harness_' . uniqid('', true) . '.php';
file_put_contents($cfgPath, '<?php return ' . var_export([
    'enth_table_prefix' => $isOwned ? '' : 'enth_',
    'app_url'           => 'https://luster.test',
    'site_url'          => 'https://fans.test',
    'site_name'         => 'Test Collective',
    'owner_name'        => 'Owner',
    'owner_email'       => 'owner@example.com',
], true) . ';');
$app = new Application($root, $cfgPath);
unlink($cfgPath);

$pdo = TracingPdo::memory();
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'));
$prop = new ReflectionProperty(Database::class, 'pdo');
$prop->setAccessible(true);
$prop->setValue($app->getDatabase(), $pdo);

if ($isOwned) {
    $pdo->exec("CREATE TABLE owned (
        listingid INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
        email TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', status INTEGER NOT NULL DEFAULT 2,
        dbtable TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '', catid TEXT NOT NULL DEFAULT '',
        category_id INTEGER NULL, opened TEXT NULL)");
    $pdo->exec("INSERT INTO owned (listingid, title, url, subject, dbtable) VALUES (1, 'Moon FL', 'https://fans.test/moon/', 'The Moon', 'fl_moon')");
    $pdo->exec("CREATE TABLE fl_moon (
        email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
        url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
        showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL)");
    $memberTable = 'fl_moon';
} else {
    $pdo->exec("CREATE TABLE enth_fanlistings (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
        email TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', dbprefix TEXT NOT NULL DEFAULT '',
        additional_info TEXT NOT NULL DEFAULT '', category INTEGER NOT NULL DEFAULT 0,
        upcoming INTEGER NOT NULL DEFAULT 0, trouble INTEGER NOT NULL DEFAULT 0, opened TEXT NULL)");
    $pdo->exec("INSERT INTO enth_fanlistings (id, name, url, subject, dbprefix) VALUES (1, 'Moon FL', 'https://fans.test/moon/', 'The Moon', 'fl_moon_')");
    $pdo->exec("CREATE TABLE fl_moon_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '',
        country TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', approved INTEGER NOT NULL DEFAULT 1,
        password TEXT NOT NULL DEFAULT '', showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1,
        added TEXT NULL, updated TEXT NULL)");
    $memberTable = 'fl_moon_members';
}

$pdo->exec("CREATE TABLE lstr_fanlisting_settings (fanlisting_id INTEGER PRIMARY KEY, last_updated TEXT NULL, listing_type TEXT NULL)");
$pdo->exec("INSERT INTO lstr_fanlisting_settings (fanlisting_id, last_updated) VALUES (1, '2020-01-01')");
$pdo->exec("CREATE TABLE lstr_site_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NULL, updated_at TEXT NULL)");
$pdo->exec("CREATE TABLE lstr_rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT, identifier TEXT NOT NULL, action TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 1, last_attempt TEXT NULL, blocked_until TEXT NULL)");
$pdo->exec("CREATE TABLE lstr_pending_updates (id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, member_email TEXT NOT NULL, changes TEXT NOT NULL DEFAULT '', submitted_at TEXT NULL)");
$pdo->exec("CREATE TABLE lstr_fan_password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, email TEXT NOT NULL, token_hash TEXT NOT NULL, expires_at TEXT NOT NULL, created_at TEXT NULL)");
$pdo->exec("CREATE TABLE lstr_collective_update_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT NOT NULL, email TEXT NOT NULL, expires_at TEXT NOT NULL, used_at TEXT NULL, created_at TEXT NULL)");
$pdo->exec("CREATE TABLE lstr_blacklist (id INTEGER PRIMARY KEY AUTOINCREMENT, pattern TEXT NOT NULL, note TEXT NOT NULL DEFAULT '', created_at TEXT NULL)");
$pdo->exec("CREATE TABLE lstr_email_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, type TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1, subject TEXT NOT NULL DEFAULT '', body TEXT NOT NULL DEFAULT '')");

$_SESSION = ['captcha_join_answer' => 7];

$chosenPassword = 'Moonlight-Pass-2026';
$post = [
    '_luster_embed_form' => 'm1',
    'name'               => 'New Fan',
    'email'              => 'newfan@example.com',
    'url'                => '',
    'country'            => 'Canada',
    'captcha_answer'     => '7',
    'password'           => $passwordMode === 'chosen' ? $chosenPassword : '',
    'password_confirm'   => $passwordMode === 'chosen' ? $chosenPassword : '',
];
$request = new Request([], $post, ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'luster.test'], [], []);

$error = null;
try {
    (new JoinController($app))->submit($request, ['id' => '1']);
} catch (\Throwable $e) {
    $error = get_class($e) . ': ' . $e->getMessage();
}

echo json_encode([
    'error'          => $error,
    'members'        => $pdo->query("SELECT * FROM {$memberTable}")->fetchAll(),
    'last_updated'   => $pdo->query('SELECT last_updated FROM lstr_fanlisting_settings WHERE fanlisting_id = 1')->fetchColumn(),
    'success_html'   => $_SESSION['lstr_flash_success_html:m1'] ?? null,
    'flash_error'    => $_SESSION['lstr_flash_error:m1'] ?? null,
    'chosen_password'=> $chosenPassword,
], JSON_THROW_ON_ERROR);
