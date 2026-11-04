<?php

declare(strict_types=1);

/*
 Runs the real forms that read and save a member's extra-field answers against a
 fresh in-memory SQLite database for each scenario in a JSON file, and prints what
 happened as JSON. MemberFieldColumnsTest runs this in a child PHP process so
 mail() can be pointed at capture-mail.php through -d sendmail_path, which can't
 be changed at runtime, and error_log() at a file through -d error_log.

 Usage: php -d sendmail_path="<php> capture-mail.php <mail file>" -d error_log=<log file>
            member-save-harness.php <scenarios.json> <mail file> <log file>

 A scenario: {"layout": "owned"|"enth", "action": "...", "definitions": [...],
   "extra_columns": ["fave", ...], "member": {"fave": "value", ...} (null for no member),
   "post": {...}, "queue_updates": bool, "fail_on": "regex"}

 Actions: join, add, edit, edit_get, update, roundtrip_edit, roundtrip_update,
 update_and_approve, display, queue_page, queue_overview, fields_page.
*/

use Luster\Controllers\Admin\FieldController;
use Luster\Controllers\Admin\MemberController;
use Luster\Controllers\Admin\PendingUpdateController;
use Luster\Controllers\Public\JoinController;
use Luster\Controllers\Public\UpdateController;
use Luster\Core\Application;
use Luster\Core\Countries;
use Luster\Core\Database;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Models\AdditionalField;
use Luster\Models\Member;
use Luster\Repositories\FieldRepo;
use Luster\Support\MembersTemplateRenderer;
use Luster\Tests\Unit\Support\TracingPdo;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

[, $scenarioFile, $mailFile, $logFile] = $argv + [null, '', '', ''];
if (!is_file((string) $scenarioFile) || $mailFile === '' || $logFile === '') {
    fwrite(STDERR, "usage: member-save-harness.php <scenarios.json> <mail file> <log file>\n");
    exit(2);
}

// Never risk real mail: refuse to run unless mail() goes to the capture script.
if (!str_contains((string) ini_get('sendmail_path'), 'capture-mail.php')) {
    fwrite(STDERR, "member-save-harness.php: sendmail_path must point at capture-mail.php\n");
    exit(3);
}

if (!defined('LUSTER_BASE')) {
    define('LUSTER_BASE', $root);
}

const EXISTING_PASSWORD = 'existing-password-1';

/** @return array{0: Application, 1: TracingPdo, 2: string} */
function memberSaveFixture(string $root, array $s, string $passwordHash): array
{
    $isOwned = $s['layout'] === 'owned';

    $cfgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_member_save_harness_' . uniqid('', true) . '.php';
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

    $definitions = $s['definitions'] ?? [];
    foreach ($definitions as $i => $d) {
        $definitions[$i] += ['label' => ucfirst((string) $d['name']), 'type' => 'text', 'required' => 0, 'options' => ''];
    }
    $stored = $definitions !== [] ? serialize($definitions) : '';

    $extraCols = '';
    foreach ($s['extra_columns'] ?? [] as $column) {
        // Quoted: SQLite won't take a bare "123" as a column name.
        $extraCols .= ', "' . $column . "\" TEXT NOT NULL DEFAULT ''";
    }

    if ($isOwned) {
        $pdo->exec("CREATE TABLE owned (
            listingid INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', status INTEGER NOT NULL DEFAULT 2,
            dbtable TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '', catid TEXT NOT NULL DEFAULT '',
            category_id INTEGER NULL, opened TEXT NULL)");
        $pdo->prepare("INSERT INTO owned (listingid, title, url, subject, dbtable, additional) VALUES (1, 'Moon FL', 'https://fans.test/moon/', 'The Moon', 'fl_moon', ?)")
            ->execute([$stored]);
        $table = 'fl_moon';
        $pdo->exec("CREATE TABLE fl_moon (
            email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
            showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL{$extraCols})");
    } else {
        $pdo->exec("CREATE TABLE enth_fanlistings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', dbprefix TEXT NOT NULL DEFAULT '',
            additional_info TEXT NOT NULL DEFAULT '', category INTEGER NOT NULL DEFAULT 0,
            upcoming INTEGER NOT NULL DEFAULT 0, trouble INTEGER NOT NULL DEFAULT 0, opened TEXT NULL)");
        $pdo->prepare("INSERT INTO enth_fanlistings (id, name, url, subject, dbprefix, additional_info) VALUES (1, 'Moon FL', 'https://fans.test/moon/', 'The Moon', 'fl_moon_', ?)")
            ->execute([$stored]);
        $table = 'fl_moon_members';
        $pdo->exec("CREATE TABLE fl_moon_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '',
            country TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', approved INTEGER NOT NULL DEFAULT 1,
            password TEXT NOT NULL DEFAULT '', showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1,
            added TEXT NULL, updated TEXT NULL{$extraCols})");
    }

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
    $pdo->prepare("INSERT INTO lstr_fanlisting_settings (fanlisting_id, last_updated, listing_type, queue_updates) VALUES (1, '2020-01-01', 'fanlisting', ?)")
        ->execute([!empty($s['queue_updates']) ? 1 : 0]);
    $pdo->exec("CREATE TABLE lstr_site_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NULL, updated_at TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT, identifier TEXT NOT NULL, action TEXT NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 1, last_attempt TEXT NULL, blocked_until TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_blacklist (id INTEGER PRIMARY KEY AUTOINCREMENT, pattern TEXT NOT NULL, note TEXT NOT NULL DEFAULT '', created_at TEXT NULL)");
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
    $pdo->exec("CREATE TABLE lstr_fan_password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, email TEXT NOT NULL,
        token_hash TEXT NOT NULL, expires_at TEXT NOT NULL, created_at TEXT NULL)");
    $pdo->exec("CREATE TABLE lstr_pending_updates (
        id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, member_email TEXT NOT NULL,
        changes TEXT NOT NULL DEFAULT '', submitted_at TEXT NOT NULL DEFAULT '2026-01-01 00:00:00')");

    if (array_key_exists('member', $s) ? $s['member'] !== null : true) {
        $status = $s['status'] ?? 'approved';
        $row    = [
            'email'    => 'ann@example.com',
            'name'     => 'Ann',
            'country'  => 'Canada',
            'url'      => 'https://ann.test/',
            'password' => $passwordHash,
            'added'    => '2020-01-01',
        ] + ($isOwned
            ? ['pending' => ['approved' => 0, 'pending' => 1, 'rejected' => 2][$status]]
            : ['approved' => $status === 'approved' ? 1 : 0]);
        foreach ($s['member'] ?? [] as $column => $value) {
            $row[(string) $column] = $value;
        }
        $pdo->prepare('INSERT INTO ' . $table . ' ("' . implode('", "', array_map('strval', array_keys($row))) . '") VALUES ('
            . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    }
    if (!empty($s['second_listing'])) {
        // The same address in another listing, under another name. Unprefixed layout only.
        $pdo->exec("INSERT INTO owned (listingid, title, url, subject, dbtable) VALUES (2, 'Sun FL', 'https://fans.test/sun/', 'The Sun', 'fl_sun')");
        $pdo->exec("CREATE TABLE fl_sun (
            email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
            showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL)");
        $pdo->exec("INSERT INTO fl_sun (email, name, url) VALUES ('ann@example.com', 'Sun Ann', 'https://sun-ann.test/')");
        $pdo->prepare('INSERT INTO lstr_pending_updates (fanlisting_id, member_email, changes) VALUES (2, ?, ?)')
            ->execute(['ann@example.com', json_encode(['country' => 'Peru'])]);
    }
    foreach ($s['queued'] ?? [] as $q) {
        $pdo->prepare('INSERT INTO lstr_pending_updates (fanlisting_id, member_email, changes) VALUES (1, ?, ?)')
            ->execute([$q['email'] ?? 'ann@example.com', json_encode($q['changes'])]);
    }

    // Only what the controller does is traced, never the fixture.
    $pdo->statements = [];

    return [$app, $pdo, $table];
}

function fileFrom(string $path, int $offset): string
{
    clearstatcache(true, $path);
    if (!is_file($path) || filesize($path) <= $offset) {
        return '';
    }
    return (string) file_get_contents($path, false, null, $offset);
}

/**
 * What a browser would post from the first form matching $xpath: every named
 * control, as rendered. Unticked boxes send nothing, and name[] makes a list.
 *
 * @return array<string, mixed>
 */
function postedFrom(string $html, string $xpath): array
{
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $query = new DOMXPath($doc);
    $form  = $query->query($xpath)->item(0);
    if (!$form instanceof DOMElement) {
        throw new RuntimeException('No form matches ' . $xpath);
    }

    $post = [];
    $add  = static function (string $name, string $value) use (&$post): void {
        if (str_ends_with($name, '[]')) {
            $post[substr($name, 0, -2)][] = $value;
        } else {
            $post[$name] = $value;
        }
    };
    foreach ($query->query('.//input | .//textarea | .//select', $form) as $control) {
        $name = $control->getAttribute('name');
        if ($name === '') {
            continue;
        }
        if ($control->nodeName === 'textarea') {
            $add($name, $control->textContent);
        } elseif ($control->nodeName === 'select') {
            $picked = '';
            foreach ($query->query('.//option', $control) as $i => $option) {
                if ($i === 0 || $option->hasAttribute('selected')) {
                    $picked = $option->getAttribute('value');
                }
            }
            $add($name, $picked);
        } elseif (in_array($control->getAttribute('type'), ['checkbox', 'radio'], true)) {
            if ($control->hasAttribute('checked')) {
                $add($name, $control->getAttribute('value'));
            }
        } else {
            $add($name, $control->getAttribute('value'));
        }
    }

    return $post;
}

/** The real signed-in Update form, built the way public/embed/update.php builds it. */
function renderUpdateForm(Application $app, Member $member, array $fields): string
{
    $old     = [];
    $answers = $member->answers($fields);
    foreach ($fields as $field) {
        $old['field_' . $field->name] = $answers[$field->name] ?? '';
    }
    $urlRaw = '<input class="fl-input" type="url" id="upd-url" name="url" value="' . htmlspecialchars($member->url, ENT_QUOTES) . '">';
    $fb     = $app->getFormFieldBuilder();
    $f      = $fb->build([
        ['type' => 'text',     'name' => 'new_name',             'id' => 'upd-name',         'label' => 'New Name',     'required' => false, 'value' => ''],
        ['type' => 'email',    'name' => 'new_email',            'id' => 'upd-new-email',    'label' => 'New Email',    'required' => false, 'value' => ''],
        ['type' => 'url',      'name' => 'url',                  'id' => 'upd-url',          'label' => 'Website',      'required' => false, 'raw_input' => $urlRaw],
        ['type' => 'text',     'name' => 'country',              'id' => '',                 'label' => 'New Country',  'required' => false, 'raw_input' => Countries::buildSelect($member->country)],
        ...$fb->descriptorsFromAdditionalFields($fields, $old),
        ['type' => 'password', 'name' => 'new_password',         'id' => 'upd-new-pass',     'label' => 'New Password', 'required' => false],
        ['type' => 'password', 'name' => 'new_password_confirm', 'id' => 'upd-confirm-pass', 'label' => 'Confirm',      'required' => false],
    ], '');

    return $app->getView()->renderFragment('update.php', [
        'signed_in' => true, 'sign_out_url' => '#', 'form_action' => '#', 'csrf_token' => 'token', 'errors' => '',
        'field_email' => $member->email, 'fields' => $fields, 'f' => $f, 'form_html' => null, 'form_marker' => 'm1',
        'base_url' => 'https://luster.test',
    ]);
}

function addPost(array $post): array
{
    return $post + ['listing_id' => '1', 'email' => 'new@example.com', 'name' => 'New Fan', 'url' => '', 'country' => 'Canada',
        'showurl' => '1', 'status' => 'approved', 'email_fan' => '0'];
}

function joinPost(array $post): array
{
    return $post + ['_luster_embed_form' => 'm1', 'name' => 'New Fan', 'email' => 'new@example.com', 'url' => '', 'country' => 'Canada',
        'captcha_answer' => '7', 'password' => '', 'password_confirm' => ''];
}

$results      = [];
// A cheap hash: this one is only ever compared, and the default cost takes about a second.
$passwordHash = password_hash(EXISTING_PASSWORD, PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]);
foreach (json_decode((string) file_get_contents($scenarioFile), true, 512, JSON_THROW_ON_ERROR) as $s) {
    clearstatcache();
    $mailStart = is_file($mailFile) ? filesize($mailFile) : 0;
    $logStart  = is_file($logFile) ? filesize($logFile) : 0;

    [$app, $pdo, $table] = memberSaveFixture($root, $s, $passwordHash);
    $_SESSION = [
        'lstr_admin_id' => 1, 'lstr_admin_username' => 'admin',
        'lstr_update_authed_1' => 'ann@example.com', 'captcha_join_answer' => 7,
    ];

    $get  = static fn(array $query): Request => new Request($query, [], ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'luster.test'], [], []);
    $post = static fn(array $body): Request => new Request([], $body, ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'luster.test'], [], []);

    $rawRow = static fn(string $email = 'ann@example.com'): array|false => (static function () use ($pdo, $table, $email): array|false {
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    })();

    $rowBefore = $rawRow();
    if (isset($s['fail_on'])) {
        $pdo->failOn = $s['fail_on'];
    }

    $error    = null;
    $response = null;
    $posted   = null;
    $display  = [];
    try {
        $members = new MemberController($app);
        switch ($s['action']) {
            case 'join':
                $response = (new JoinController($app))->submit($post(joinPost($s['post'] ?? [])), ['id' => '1']);
                break;
            case 'add':
                $response = $members->add($post(addPost($s['post'] ?? [])), []);
                break;
            case 'edit':
                $response = $members->edit($post(($s['post'] ?? []) + ['member_email' => 'ann@example.com', 'email' => 'ann@example.com', 'name' => 'Ann', 'country' => 'Canada', 'url' => 'https://ann.test/', 'showurl' => '1']), ['id' => '1']);
                break;
            case 'edit_get':
                $response = $members->edit($get(['email' => 'ann@example.com']), ['id' => '1']);
                break;
            case 'update':
                $response = (new UpdateController($app))->submit($post(($s['post'] ?? []) + ['_luster_embed_form' => 'm1']), ['id' => '1']);
                break;
            case 'roundtrip_edit':
                $page     = $members->edit($get(['email' => 'ann@example.com']), ['id' => '1']);
                $posted   = postedFrom($page->getBody(), '//form[.//input[@name="member_email"] and .//input[@name="email"]]');
                $response = $members->edit($post($posted), ['id' => '1']);
                break;
            case 'roundtrip_update':
                $fields   = (new FieldRepo($app->getDatabase(), $app->getConfig()))->getFieldsForListing(1);
                $html     = renderUpdateForm($app, Member::fromRow($rowBefore), $fields);
                $posted   = postedFrom($html, '//form[contains(@class, "fl-form-update")]');
                $response = (new UpdateController($app))->submit($post($posted), ['id' => '1']);
                break;
            case 'update_and_approve':
                $response = (new UpdateController($app))->submit($post(($s['post'] ?? []) + ['_luster_embed_form' => 'm1']), ['id' => '1']);
                foreach ($pdo->query('SELECT id FROM lstr_pending_updates')->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $display['queued_before_approval'][] = json_decode((string) $pdo->query('SELECT changes FROM lstr_pending_updates WHERE id = ' . (int) $id)->fetchColumn(), true);
                    (new PendingUpdateController($app))->approve($post([]), ['id' => (string) $id]);
                }
                break;
            case 'display':
                $fields = (new FieldRepo($app->getDatabase(), $app->getConfig()))->getFieldsForListing(1);
                $member = Member::fromRow($rowBefore);
                $tokens = '';
                foreach ($fields as $field) {
                    $tokens .= '[' . $field->name . '={fan_' . $field->name . '}]';
                }
                $display['token_row'] = MembersTemplateRenderer::render('{each_member}' . $tokens . '{/each_member}', [$member], $fields, '', 1);
                $display['block']     = MembersTemplateRenderer::render('{each_member}{fan_additional_fields}{/each_member}', [$member], $fields, '', 1);
                $display['cells']     = MembersTemplateRenderer::render('{each_member}{fan_additional_field_cells}{/each_member}', [$member], $fields, '', 1);
                $conditions = '{if_fan_additional_fields}ANY{/if_fan_additional_fields}{if_no_fan_additional_fields}NONE{/if_no_fan_additional_fields}';
                foreach ($fields as $field) {
                    $conditions .= '{if_fan_' . $field->name . '}HAS{/if_fan_' . $field->name . '}{if_no_fan_' . $field->name . '}LACKS{/if_no_fan_' . $field->name . '}';
                }
                $display['conditions'] = MembersTemplateRenderer::render('{each_member}' . $conditions . '{/each_member}', [$member], $fields, '', 1);
                $display['default']   = $app->getView()->renderFragment('members.php', [
                    'members_html' => null, 'pagination' => '', 'show_search' => false, 'sort' => 'name', 'country' => '', 'members_base' => '#',
                    'stats_line' => '', 'country_summary' => [], 'members' => [$member], 'fields' => $fields, 'join_url' => '#', 'date_format' => '',
                ]);
                $display['admin'] = $members->overview($get(['listing_id' => '1', 'status' => 'all']), [])->getBody();
                $display['csv']   = $members->export($get([]), ['id' => '1'])->getBody();
                break;
            case 'queue_page':
                $response = $members->overview($get(['listing_id' => '1', 'status' => 'pending']), []);
                break;
            case 'queue_overview':
                $response = $members->overview($get(['status' => 'pending']), []);
                break;
            case 'fields_page':
                $response = (new FieldController($app))->index($get([]), ['fid' => '1']);
                break;
            default:
                throw new RuntimeException('Unknown action ' . $s['action']);
        }
    } catch (\Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }

    // Simulated failures were for the controller; reading the results mustn't hit them.
    $pdo->failOn = null;

    $queue = [];
    foreach ($pdo->query('SELECT member_email, changes FROM lstr_pending_updates')->fetchAll(PDO::FETCH_ASSOC) as $q) {
        $queue[] = ['email' => $q['member_email'], 'changes' => json_decode((string) $q['changes'], true)];
    }

    $flashes = [];
    foreach ($_SESSION as $key => $value) {
        if (str_starts_with((string) $key, 'lstr_flash_')) {
            $flashes[(string) $key] = $value;
        }
    }

    $results[] = [
        'error'      => $error,
        'status'     => $response?->getStatus(),
        'redirect'   => $response?->isRedirect() ? $response->getRedirectUrl() : null,
        'body'       => $response instanceof Response && !$response->isRedirect() ? $response->getBody() : '',
        'flashes'    => $flashes,
        'before'     => $rowBefore,
        'row'        => $rawRow(),
        'new_row'    => $rawRow('new@example.com'),
        'row_count'  => (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(),
        'queue'      => $queue,
        'posted'     => $posted,
        'display'    => $display,
        'statements' => $pdo->statements,
        'mail'       => fileFrom($mailFile, $mailStart),
        'log'        => fileFrom($logFile, $logStart),
    ];
}

echo json_encode($results, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
