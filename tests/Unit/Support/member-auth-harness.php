<?php

declare(strict_types=1);

/*
 Runs the real controllers behind public sign-ins, member removal, renames, resets
 and KIM Update against a fresh in-memory SQLite database for each scenario in a JSON
 file, one step after another, and prints what happened as JSON. Its tests run this
 in a child PHP process so mail() can be pointed at capture-mail.php through
 -d sendmail_path, which can't be changed at runtime, and error_log() at a file
 through -d error_log.

 Usage: php -d sendmail_path="<php> capture-mail.php <mail file>" -d error_log=<log file>
            member-auth-harness.php <scenarios.json> <mail file> <log file>

 A scenario: {"layout": "owned"|"enth", "no_migration": bool, "queue_updates": bool,
   "members": [{"listing": 1, "email": "...", "name": "...", "password": "...", "md5": bool, "status": "..."}],
   "steps": [{"do": "...", ...}]}

 Listings: 1 Moon and 2 Sun. Steps are in the switch below. GET_LOCK and friends are
 TracingPdo's scripted versions, so a test can make a chosen lock refuse and see the
 order of every lock, read and write. Waiting for a lock really happening is checked
 on MariaDB instead, because this is only ever one connection.
*/

use Luster\Addons\Kim\Controllers\KimAdminController;
use Luster\Addons\Kim\Controllers\KimResetController;
use Luster\Addons\Kim\Controllers\KimSignupController;
use Luster\Addons\Kim\Controllers\KimUpdateController;
use Luster\Addons\Kim\KimMemberRepo;
use Luster\Addons\Kim\KimPasswordResetRepo;
use Luster\Addons\Kim\KimSignIn;
use Luster\Controllers\Admin\MemberController;
use Luster\Controllers\Admin\PendingUpdateController;
use Luster\Controllers\Public\CollectiveUpdateController;
use Luster\Controllers\Public\JoinController;
use Luster\Controllers\Public\ResetController;
use Luster\Controllers\Public\UpdateController;
use Luster\Core\Application;
use Luster\Core\Database;
use Luster\Core\MemberFormSessionService;
use Luster\Core\Request;
use Luster\Repositories\CollectiveUpdateTokenRepo;
use Luster\Repositories\FanPasswordResetRepo;
use Luster\Repositories\MemberFormSessionRepo;
use Luster\Support\MemberRemoval;
use Luster\Support\MemberSignIn;
use Luster\Tests\Unit\Support\TracingPdo;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

[, $scenarioFile, $mailFile, $logFile] = $argv + [null, '', '', ''];
if (!is_file((string) $scenarioFile) || $mailFile === '' || $logFile === '') {
    fwrite(STDERR, "usage: member-auth-harness.php <scenarios.json> <mail file> <log file>\n");
    exit(2);
}

// Never risk real mail: refuse to run unless mail() goes to the capture script.
if (!str_contains((string) ini_get('sendmail_path'), 'capture-mail.php')) {
    fwrite(STDERR, "member-auth-harness.php: sendmail_path must point at capture-mail.php\n");
    exit(3);
}

if (!defined('LUSTER_BASE')) {
    define('LUSTER_BASE', $root);
}

/** A cheap hash, because the default cost takes about a second. Sign-in upgrades it. */
function cheapHash(string $password): string
{
    return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]);
}

/** @return array{0: Application, 1: TracingPdo} */
function authFixture(string $root, array $s): array
{
    $isOwned = $s['layout'] === 'owned';

    $cfgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_member_auth_harness_' . uniqid('', true) . '.php';
    file_put_contents($cfgPath, '<?php return ' . var_export([
        'enth_table_prefix' => $isOwned ? '' : 'enth_',
        'db_name'           => 'lstr_test',
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
    } else {
        $pdo->exec("CREATE TABLE enth_fanlistings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', dbprefix TEXT NOT NULL DEFAULT '',
            additional_info TEXT NOT NULL DEFAULT '', category INTEGER NOT NULL DEFAULT 0,
            upcoming INTEGER NOT NULL DEFAULT 0, trouble INTEGER NOT NULL DEFAULT 0, opened TEXT NULL)");
    }

    foreach ([1 => ['Moon FL', 'fl_moon'], 2 => ['Sun FL', 'fl_sun']] as $id => [$title, $table]) {
        if ($isOwned) {
            $pdo->prepare('INSERT INTO owned (listingid, title, url, subject, dbtable) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $title, 'https://fans.test/' . $table . '/', 'The ' . $title, $table]);
            $pdo->exec("CREATE TABLE {$table} (
                email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
                url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
                showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL)");
        } else {
            $pdo->prepare('INSERT INTO enth_fanlistings (id, name, url, subject, dbprefix) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $title, 'https://fans.test/' . $table . '/', 'The ' . $title, $table . '_']);
            $pdo->exec("CREATE TABLE {$table}_members (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '',
                country TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', approved INTEGER NOT NULL DEFAULT 1,
                password TEXT NOT NULL DEFAULT '', showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1,
                added TEXT NULL, updated TEXT NULL)");
        }
    }

    $stampColumn = !empty($s['no_migration']) ? '' : ', member_stamp TEXT NULL';
    $pdo->exec("CREATE TABLE lstr_member_form_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT NOT NULL, fanlisting_id INTEGER NOT NULL, member_email TEXT NOT NULL,
        allowed_action TEXT NOT NULL, expires_at TEXT NOT NULL, used_at TEXT NULL, created_at TEXT NULL{$stampColumn})");
    $pdo->exec("CREATE TABLE lstr_listing_lifecycle (
        fanlisting_id INTEGER PRIMARY KEY, lifecycle_status TEXT NOT NULL, archived_at TEXT NULL,
        legacy_upcoming INTEGER NOT NULL DEFAULT 0, legacy_trouble INTEGER NOT NULL DEFAULT 0,
        adopted_to_name TEXT NULL, adopted_to_url TEXT NULL, archive_note TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_fanlisting_settings (
        fanlisting_id INTEGER PRIMARY KEY, last_updated TEXT NULL, listing_type TEXT NULL,
        form_url_join TEXT NOT NULL DEFAULT '', form_url_update TEXT NOT NULL DEFAULT '', form_url_reset TEXT NOT NULL DEFAULT '',
        form_url_codes_submit TEXT NOT NULL DEFAULT '', codes_submit_enabled INTEGER NOT NULL DEFAULT 1,
        db_host TEXT NOT NULL DEFAULT '', db_name TEXT NOT NULL DEFAULT '', db_user TEXT NOT NULL DEFAULT '',
        db_pass TEXT NOT NULL DEFAULT '', country_field_enabled INTEGER NULL, queue_updates INTEGER NOT NULL DEFAULT 0,
        success_template TEXT NULL)");
    foreach ([1, 2] as $id) {
        $pdo->prepare("INSERT INTO lstr_fanlisting_settings (fanlisting_id, last_updated, listing_type, queue_updates) VALUES (?, '2020-01-01', 'fanlisting', ?)")
            ->execute([$id, !empty($s['queue_updates']) ? 1 : 0]);
    }
    $pdo->exec("CREATE TABLE lstr_site_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NULL, updated_at TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT, identifier TEXT NOT NULL, action TEXT NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 1, last_attempt TEXT NULL, blocked_until TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_blacklist (id INTEGER PRIMARY KEY AUTOINCREMENT, pattern TEXT NOT NULL, note TEXT NOT NULL DEFAULT '', created_at TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_email_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL, type TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 1, subject TEXT NOT NULL DEFAULT '', body TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE lstr_activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, admin_user_id INTEGER NULL, action TEXT NOT NULL,
        description TEXT NOT NULL, ip_address TEXT NULL, created_at TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_member_link_checks (
        id INTEGER PRIMARY KEY AUTOINCREMENT, listing_id INTEGER NOT NULL, member_email TEXT NOT NULL,
        member_name TEXT NOT NULL DEFAULT '', member_url TEXT NOT NULL DEFAULT '', link_status TEXT NOT NULL DEFAULT 'unchecked',
        link_redirect_url TEXT NULL, link_checked_at TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_fan_password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, email TEXT NOT NULL,
        token_hash TEXT NOT NULL, expires_at TEXT NOT NULL, created_at TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_pending_updates (
        id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, member_email TEXT NOT NULL,
        changes TEXT NOT NULL DEFAULT '', submitted_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00')");
    $pdo->exec("CREATE TABLE lstr_collective_update_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT NOT NULL, email TEXT NOT NULL,
        expires_at TEXT NOT NULL, used_at TEXT NULL, created_at TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_kim_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '',
        url TEXT NOT NULL DEFAULT '', password TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 1,
        granted INTEGER NOT NULL DEFAULT 0, added_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE lstr_kim_password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, kim_member_id INTEGER NOT NULL, token_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL, created_at TEXT NULL)");

    $pdo->statements = [];

    return [$app, $pdo];
}

function memberTable(string $layout, int $listing): string
{
    return ($listing === 2 ? 'fl_sun' : 'fl_moon') . ($layout === 'owned' ? '' : '_members');
}

function dbprefixOf(string $layout, int $listing): string
{
    return ($listing === 2 ? 'fl_sun' : 'fl_moon') . ($layout === 'owned' ? '' : '_');
}

function insertMember(TracingPdo $pdo, string $layout, array $m): void
{
    $listing = (int) ($m['listing'] ?? 1);
    $status  = $m['status'] ?? 'approved';
    $row     = [
        'email'    => $m['email'],
        'name'     => $m['name'] ?? 'A Fan',
        'country'  => $m['country'] ?? 'Canada',
        'url'      => $m['url'] ?? '',
        'password' => !empty($m['md5']) ? md5($m['password']) : cheapHash($m['password'] ?? 'a-password-1'),
        'added'    => $m['added'] ?? date('Y-m-d'),
    ] + ($layout === 'owned'
        ? ['pending' => ['approved' => 0, 'pending' => 1, 'rejected' => 2][$status]]
        : ['approved' => $status === 'approved' ? 1 : 0]);
    $sql = 'INSERT INTO ' . memberTable($layout, $listing) . ' (' . implode(', ', array_keys($row)) . ') VALUES ('
        . implode(', ', array_map([$pdo, 'quote'], array_values($row))) . ')';
    $pdo->quiet($sql);
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

    [$app, $pdo] = authFixture($root, $s);
    $layout = (string) $s['layout'];
    $_SESSION = ['lstr_admin_id' => 1, 'lstr_admin_username' => 'admin', 'captcha_join_answer' => 7, 'captcha_kim_signup_answer' => 7];
    $session = $app->getSession();

    foreach ($s['members'] ?? [] as $m) {
        insertMember($pdo, $layout, $m);
    }

    $tokens = [];
    $ids    = [];
    $mailAtRelease = [];
    $pdo->beforeStatement = static function (string $sql) use (&$mailAtRelease, $mailFile): void {
        if (str_contains($sql, 'RELEASE_LOCK')) {
            clearstatcache(true, $mailFile);
            $mailAtRelease[] = is_file($mailFile) ? filesize($mailFile) : 0;
        }
    };
    $baseHook = $pdo->beforeStatement;

    $post = static fn(array $body): Request => new Request([], $body, ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'luster.test'], [], []);
    $error = null;
    $stepFlashes = [];
    $maxId = static fn(string $table): int => (int) $pdo->query("SELECT MAX(id) FROM {$table}")->fetchColumn();
    $kimIds = static function (array $names) use (&$ids): array {
        return array_map(static fn($n): string => (string) ($ids[$n] ?? $n), $names);
    };

    foreach ($s['steps'] ?? [] as $step) {
        $listing = (int) ($step['listing'] ?? 1);
        $email   = (string) ($step['email'] ?? 'a@x.test');
        $body    = $step['post'] ?? [];
        try {
            switch ($step['do']) {
                // ---- Scripting ----
                case 'set_lock':
                    $pdo->lockAnswers[$app->getMemberAddressLock()->nameFor($listing, $email)] = $step['answer'] ?? 0;
                    break;
                case 'clear_lock_script':
                    $pdo->lockAnswers = [];
                    $pdo->oneLockAtATime = false;
                    break;
                case 'one_lock_at_a_time':
                    $pdo->oneLockAtATime = true;
                    break;
                case 'trace_from_here':
                    $pdo->statements = [];
                    clearstatcache(true, $mailFile);
                    $mailStart = is_file($mailFile) ? filesize($mailFile) : 0;
                    $mailAtRelease = [];
                    break;
                case 'before':
                    // Once, just before the next statement matching: stands for another request.
                    $pattern = (string) $step['match'];
                    $sql     = (string) $step['sql'];
                    $fired   = false;
                    $pdo->beforeStatement = static function (string $statement) use ($pattern, $sql, &$fired, $pdo, $baseHook): void {
                        $baseHook($statement);
                        if (!$fired && preg_match($pattern, $statement) === 1) {
                            $fired = true;
                            $pdo->quiet($sql);
                        }
                    };
                    break;
                case 'sql':
                    $pdo->quiet((string) $step['sql']);
                    break;
                case 'set_password':
                    $pdo->quiet('UPDATE ' . memberTable($layout, $listing) . ' SET password = ' . $pdo->quote(cheapHash((string) $step['password'])) . ' WHERE email = ' . $pdo->quote($email));
                    break;
                case 'fail_on':
                    $pdo->failOn = $step['regex'] ?? null;
                    break;
                case 'insert_member':
                    insertMember($pdo, $layout, $step['member']);
                    break;
                case 'queue':
                    $pdo->prepare('INSERT INTO lstr_pending_updates (fanlisting_id, member_email, changes) VALUES (?, ?, ?)')
                        ->execute([$listing, $email, json_encode($step['changes'])]);
                    $ids[$step['as'] ?? 'queued'] = $maxId('lstr_pending_updates');
                    break;

                // ---- A session as sign-in leaves it, without the slow hash ----
                case 'signed_in':
                    $hash = (string) $pdo->query('SELECT password FROM ' . memberTable($layout, $listing) . ' WHERE email = ' . $pdo->quote($email))->fetchColumn();
                    MemberSignIn::remember($session, $listing, $email, $hash);
                    $service = new MemberFormSessionService(new MemberFormSessionRepo($app->getDatabase()));
                    $tokens['update'] = $service->issue($listing, $email, 'update', MemberSignIn::stamp($hash));
                    $tokens['delete'] = $service->issue($listing, $email, 'delete', MemberSignIn::stamp($hash));
                    break;
                case 'lose_session':
                    MemberSignIn::forget($session, $listing);
                    break;
                case 'drop_stamp':
                    $session->remove('lstr_update_authed_stamp_' . $listing);
                    break;

                // ---- Update ----
                case 'sign_in':
                    (new UpdateController($app))->signIn($post(['email' => $email, 'password' => $step['password'] ?? 'a-password-1', '_luster_embed_form' => 'm1']), ['id' => (string) $listing]);
                    $tokens['update'] = $session->get(MemberSignIn::FORM_TOKEN_UPDATE . $listing);
                    $tokens['delete'] = $session->get(MemberSignIn::FORM_TOKEN_DELETE . $listing);
                    break;
                case 'submit':
                    if (!empty($step['use_token'])) {
                        $action = ($body['delete_membership'] ?? '') === '1' ? 'delete' : 'update';
                        $body['_form_token_' . $action] = (string) $tokens[$action];
                    }
                    (new UpdateController($app))->submit($post($body + ['_luster_embed_form' => 'm1']), ['id' => (string) $listing]);
                    break;

                // ---- Removal ----
                case 'remove':
                    MemberRemoval::for($app)->remove($listing, dbprefixOf($layout, $listing), $email);
                    break;
                case 'admin_delete':
                    (new MemberController($app))->deleteMember($post(['member_email' => $email]), ['id' => (string) $listing]);
                    break;
                case 'admin_reject':
                    (new MemberController($app))->reject($post(['member_email' => $email]), ['id' => (string) $listing]);
                    break;
                case 'bulk':
                    (new MemberController($app))->bulk($post(['bulk_action' => $step['action'], 'emails' => [$email]]), ['id' => (string) $listing]);
                    break;
                case 'merge':
                    (new MemberController($app))->merge($post(['member_email' => $step['primary'], 'duplicate_email' => $email]), ['id' => (string) $listing]);
                    break;
                case 'pending_reject':
                    (new MemberController($app))->pendingBulk($post(['action' => 'reject', 'items' => [$listing . ':' . $email]]), []);
                    break;

                // ---- Admin edits and adds, public joins ----
                case 'edit':
                    (new MemberController($app))->edit($post($body + ['member_email' => $email, 'email' => $email, 'name' => 'A Fan', 'country' => 'Canada', 'url' => '', 'showurl' => '1']), ['id' => (string) $listing]);
                    break;
                case 'add':
                    (new MemberController($app))->add($post($body + ['listing_id' => (string) $listing, 'email' => $email, 'name' => 'Added Fan', 'url' => '', 'country' => 'Canada', 'showurl' => '1', 'status' => 'approved', 'email_fan' => '0']), []);
                    break;
                case 'join':
                    (new JoinController($app))->submit($post($body + ['_luster_embed_form' => 'm1', 'name' => 'Joined Fan', 'email' => $email, 'url' => '', 'country' => 'Canada', 'captcha_answer' => '7', 'password' => '', 'password_confirm' => '']), ['id' => (string) $listing]);
                    break;

                // ---- The queue ----
                case 'approve':
                    (new PendingUpdateController($app))->approve($post([]), ['id' => (string) ($ids[$step['id'] ?? 'queued'] ?? 0)]);
                    break;
                case 'approve_all':
                    (new PendingUpdateController($app))->approveAll($post(['_listing_id' => (string) $listing]), []);
                    break;
                case 'bulk_approve':
                    (new PendingUpdateController($app))->bulk($post(['bulk_action' => 'approve', 'ids' => array_map(static fn($n): string => (string) $ids[$n], $step['ids'])]), []);
                    break;
                case 'approve_overview':
                    (new MemberController($app))->approveAllOverview($post([]), []);
                    break;

                // ---- Resets and Collective Update ----
                case 'reset_token':
                    $raw = bin2hex(random_bytes(32));
                    (new FanPasswordResetRepo($app->getDatabase()))->create($listing, $email, hash('sha256', $raw), (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'));
                    $tokens['reset'] = $raw;
                    break;
                case 'reset_confirm':
                    (new ResetController($app))->submitConfirm($post(['token' => $tokens['reset'], 'new_password' => $step['password'], 'new_password_confirm' => $step['password'], '_luster_embed_form' => 'm1']), ['id' => (string) $listing]);
                    break;
                case 'collective_token':
                    $tokens['collective'] = (new CollectiveUpdateTokenRepo($app->getDatabase()))->create($email);
                    break;
                case 'collective':
                    $session->set('lstr_embed_return_to', 'https://fans.test/update.php');
                    (new CollectiveUpdateController($app))->submit($post($body + ['_cu_token' => $tokens['collective']]));
                    break;

                // ---- KIM ----
                case 'kim_entry':
                    $pdo->prepare('INSERT INTO lstr_kim_members (fanlisting_id, name, email, password, pending) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$listing, $step['name'] ?? 'A Fan', $email, cheapHash((string) $step['password']), !empty($step['pending']) ? 1 : 0]);
                    $ids[(string) $step['as']] = $maxId('lstr_kim_members');
                    break;
                case 'kim_entry_sql':
                    // {id} and {other} are entries by name.
                    $pdo->quiet(str_replace(array_map(static fn($n): string => '{' . $n . '}', array_keys($ids)), array_map('strval', array_values($ids)), (string) $step['sql']));
                    break;
                case 'kim_before':
                    $pattern = (string) $step['match'];
                    $sql     = str_replace(array_map(static fn($n): string => '{' . $n . '}', array_keys($ids)), array_map('strval', array_values($ids)), (string) $step['sql']);
                    $fired   = false;
                    $pdo->beforeStatement = static function (string $statement) use ($pattern, $sql, &$fired, $pdo, $baseHook): void {
                        $baseHook($statement);
                        if (!$fired && preg_match($pattern, $statement) === 1) {
                            $fired = true;
                            $pdo->quiet($sql);
                        }
                    };
                    break;
                case 'kim_sign_in':
                    (new KimUpdateController($app))->submitSignIn($post(['email' => $email, 'password' => $step['password']]), []);
                    break;
                case 'kim_save':
                    $body['entry_ids']  = $kimIds($step['entries'] ?? []);
                    $body['remove_ids'] = $kimIds($step['removes'] ?? []);
                    (new KimUpdateController($app))->submitSave($post($body), []);
                    break;
                case 'kim_session_only_old_style':
                    // A sign-in from before the entries were remembered.
                    $session->set(KimSignIn::EMAIL_KEY, $email);
                    break;
                case 'kim_reset_token':
                    $raw = bin2hex(random_bytes(32));
                    (new KimPasswordResetRepo($app->getDatabase()))->create((int) $ids[$step['entry']], hash('sha256', $raw), (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'));
                    $tokens['kim_reset'] = $raw;
                    break;
                case 'kim_reset_confirm':
                    (new KimResetController($app))->submitConfirm($post(['token' => $tokens['kim_reset'], 'new_password' => $step['password'], 'new_password_confirm' => $step['password']]), []);
                    break;
                case 'kim_reset_request':
                    (new KimResetController($app))->submit($post(['email' => $email]), []);
                    break;
                case 'kim_admin_edit':
                    (new KimAdminController($app))->edit($post($body + ['name' => 'A Fan', 'url' => '', 'fanlisting_id' => (string) $listing]), ['id' => (string) $ids[$step['entry']]]);
                    break;
                case 'kim_signup':
                    (new KimSignupController($app))->submit($post($body + ['name' => 'Signed Up', 'email' => $email, 'url' => '', 'fanlisting_id' => (string) $listing, 'captcha_answer' => '7']), []);
                    $ids[(string) ($step['as'] ?? 'signed_up')] = $maxId('lstr_kim_members');
                    break;
                case 'kim_approve_all':
                    (new KimMemberRepo($app->getDatabase()))->approveByIds(array_values($ids));
                    break;
                default:
                    throw new RuntimeException('Unknown step ' . $step['do']);
            }
        } catch (\Throwable $e) {
            $error = $step['do'] . ': ' . get_class($e) . ': ' . $e->getMessage();
        }

        // A simulated failure is for the step after it, and only that one.
        if ($step['do'] !== 'fail_on') {
            $pdo->failOn = null;
        }

        $flashes = [];
        foreach ($_SESSION as $key => $value) {
            if (str_starts_with((string) $key, 'lstr_flash_')) {
                if (!str_contains((string) $key, 'old')) {
                    $flashes[(string) $key] = $value;
                }
                unset($_SESSION[$key]);
            }
        }
        $stepFlashes[] = $flashes;
    }

    $pdo->beforeStatement = null;
    // Only what the code did is traced, not reading the results here.
    $traced = $pdo->statements;
    $read = static fn(string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $members = [];
    foreach ([1, 2] as $l) {
        $members[$l] = $read('SELECT * FROM ' . memberTable($layout, $l) . ' ORDER BY email');
    }
    $kim = [];
    foreach ($read('SELECT * FROM lstr_kim_members ORDER BY id') as $row) {
        $name       = array_search((int) $row['id'], $ids, true);
        $kim[$name !== false ? (string) $name : 'id' . $row['id']] = $row;
    }

    $results[] = [
        'error'          => $error,
        'members'        => $members,
        'kim'            => $kim,
        'kim_tokens'     => (int) $pdo->query('SELECT COUNT(*) FROM lstr_kim_password_resets')->fetchColumn(),
        'queue'          => $read('SELECT id, fanlisting_id, member_email, changes FROM lstr_pending_updates ORDER BY id'),
        'form_tokens'    => $read('SELECT member_email, allowed_action, used_at FROM lstr_member_form_sessions ORDER BY id'),
        'resets'         => $read('SELECT fanlisting_id, email FROM lstr_fan_password_resets ORDER BY id'),
        'collective_tokens' => $read('SELECT email, used_at FROM lstr_collective_update_tokens ORDER BY id'),
        'flashes'        => $stepFlashes,
        'session_authed' => array_values(array_filter(array_keys($_SESSION), static fn($k): bool => str_contains((string) $k, 'authed') || str_contains((string) $k, 'kim_update_entries') || str_contains((string) $k, 'formtoken'))),
        'statements'     => $traced,
        'locks_held'     => $pdo->locksHeld,
        'lock_names'     => array_map(static fn(array $a): string => $app->getMemberAddressLock()->nameFor((int) $a[0], (string) $a[1]), $s['names_for'] ?? []),
        'mail_at_release' => array_map(static fn(int $size): int => $size - $mailStart, $mailAtRelease),
        'mail'           => fileFrom($mailFile, $mailStart),
        'log'            => fileFrom($logFile, $logStart),
    ];
}

echo json_encode($results, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
