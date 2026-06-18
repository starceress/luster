<?php
use Luster\Core\View;
/** @var string $source */
/** @var string $source_name */
/** @var string $error */
/** @var array  $saved_creds */
/** @var string $saved_fn_prefix     Saved Fanatically table prefix (from session credentials) */
/** @var bool   $dump_active         true when a SQL dump has already been uploaded this session */
/** @var bool   $config_reader_enabled  true when admin has enabled config auto-detect in Settings */
/** @var array  $enth_networks */
/** @var string $base_url */
/** @var string $csrf_token */
$b   = rtrim(View::e($base_url), '/');
$src = View::e($source);
$connectUrl = $b . '/admin/import/' . $src . '/connect';
?>

<div class="import-wizard">

<div class="wizard-steps">
  <div class="wizard-steps-line"></div>
  <div class="wizard-steps-progress wizard-steps-progress--connect"></div>
  <div class="wizard-step-node wizard-step-node--done">
    <div class="wizard-step-bubble"><?= View::icon('check') ?></div>
    <div class="wizard-step-label" data-step-sub="Pick an import source">Source</div>
  </div>
  <div class="wizard-step-node wizard-step-node--active">
    <div class="wizard-step-bubble">2</div>
    <div class="wizard-step-label" data-step-sub="Connect or upload data">Connect</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">3</div>
    <div class="wizard-step-label" data-step-sub="Choose what to import">Options</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">4</div>
    <div class="wizard-step-label" data-step-sub="Review the dry run">Preview</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">5</div>
    <div class="wizard-step-label" data-step-sub="Results and summary">Done</div>
  </div>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error import-stack-gap"><?= View::e($error) ?></div>
<?php endif; ?>

<form id="import-configure-form" method="POST"
      action="<?= $connectUrl ?>"
      data-live-url="<?= $connectUrl ?>"
      data-dump-url="<?= $connectUrl ?>"
      enctype="multipart/form-data">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('database') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Connect to <?= View::e($source_name) ?></div>
        <div class="pc-head-sub">Tell Luster where to read the old data from.</div>
      </div>
    </div>

    <!-- ── Connection method ──────────────────────────────────────── -->
    <div class="pc-section">
      <p>Luster can either log into the old database for this import, or read a SQL export file you upload. Login details and uploads are temporary and are cleared after the import finishes.</p>

      <div class="import-choice pc-section">
        <label class="import-choice-item">
          <input type="radio" name="_conn_mode" value="live" id="js-mode-live" checked>
          <div>
            <div class="import-choice-title">Live database connection</div>
            <div class="import-choice-sub">
              Type the old database login details below.
              <?php if ($source === 'enthusiast'): ?>
                Leave the fields blank if Enthusiast uses the same database as Luster.
              <?php endif; ?>
            </div>
          </div>
        </label>
        <label class="import-choice-item">
          <input type="radio" name="_conn_mode" value="dump" id="js-mode-dump"<?= $dump_active ? ' checked' : '' ?>>
          <div>
            <div class="import-choice-title">Upload SQL dump</div>
            <div class="import-choice-sub">Upload a database backup file ending in <code>.sql</code> (max&nbsp;50&nbsp;MB). Luster detects the table layout for you.</div>
          </div>
        </label>
      </div>
    </div>

    <?php if ($source === 'fanatically'): ?>
    <!-- ── Fanatically table prefix ─────────────────────────────── -->
    <div class="pc-section">
      <div class="pc-section-label">Database Table Prefix</div>
      <div class="pc-section">
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="fn_table_prefix">Table Prefix</label>
        <div>
          <input id="fn_table_prefix" type="text" name="fn_table_prefix" class="form import-input-medium"
                 placeholder="fanatic_"
                 value="<?= View::e($saved_fn_prefix ?? 'fanatic_') ?>">
          <p class="form-hint">The text Fanatically puts before its table names. Most installs use <code>fanatic_</code>. Check Fanatically's <code>config.php</code> if you are unsure.</p>
        </div>
      </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Live DB credentials ───────────────────────────────────── -->
    <div id="js-live-db-section"<?= $dump_active ? ' class="is-hidden"' : '' ?>>

<?php if ($config_reader_enabled): ?>
      <!-- ── Auto-detect credentials (opt-in) ──────────────────── -->
      <div class="pc-section">
        <div class="pc-section-label">Auto-detect Credentials</div>
        <div class="pc-section">
          <div class="listing-field-row listing-field-row--top">
            <label class="listing-field-label" for="js-config-path">Config file path</label>
            <div>
              <div style="display:flex;gap:.5rem;align-items:flex-start">
                <input id="js-config-path" type="text" class="form" style="flex:1"
                       placeholder="/absolute/path/to/<?= View::e($source_name) ?>/config.php">
                <button type="button" id="js-autodetect-btn" class="btn btn-secondary btn-sm"
                        style="white-space:nowrap">
                  <?= View::icon('gear') ?> Detect
                </button>
              </div>
              <p class="form-hint">
                Optional. Enter the absolute server path to your <?= View::e($source_name) ?> <code>config.php</code>.
                Luster reads the file as plain text and never runs it. If it finds database details, it fills the fields below. Review them before continuing.
              </p>
              <p id="js-autodetect-result" style="font-size:var(--fs-sm);margin-top:.35rem;display:none"></p>
            </div>
          </div>
        </div>
      </div>
<?php endif; ?>

      <div class="pc-section">
        <div class="pc-section-label">Database Credentials</div>
        <div class="pc-section">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="db_host">Host</label>
            <input id="db_host" type="text" name="db_host" class="form"
                   value="<?= View::e((string) ($saved_creds['host'] ?? 'localhost')) ?>">
            <p class="form-hint">Usually <code>localhost</code> when the old script is on the same web host.</p>
          </div>
          <div class="form-group">
            <label class="form-label" for="db_name">Database Name</label>
            <input id="db_name" type="text" name="db_name" class="form"
                   value="<?= View::e((string) ($saved_creds['name'] ?? '')) ?>">
            <p class="form-hint">The MySQL database used by the old script.</p>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="db_user">User</label>
            <input id="db_user" type="text" name="db_user" class="form"
                   value="<?= View::e((string) ($saved_creds['user'] ?? '')) ?>">
            <p class="form-hint">The MySQL username for the old script's database.</p>
          </div>
          <div class="form-group">
            <label class="form-label" for="db_pass">Password</label>
            <div class="input-icon-wrap">
              <input id="db_pass" type="password" name="db_pass" class="form"
                     autocomplete="new-password">
              <button type="button" class="input-icon-btn" data-pw-toggle aria-label="Show password" tabindex="-1">
                <?= View::icon('eye-on') ?><?= View::icon('eye-off') ?>
              </button>
            </div>
            <p class="form-hint">The MySQL password for that user. Luster keeps it only for this import session.</p>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:.75rem;margin-top:.5rem;flex-wrap:wrap">
          <button type="button" id="js-test-conn" class="btn btn-secondary btn-sm"
                  data-action="<?= $b ?>/admin/import/<?= $src ?>/test-connection">
            Test Connection
          </button>
          <button type="reset" class="btn btn-ghost btn-sm">Clear</button>
          <span id="js-test-result" style="font-size:var(--fs-sm);display:none"></span>
        </div>
      </div>
    </div>
    
              </div>

    <!-- ── SQL dump upload ──────────────────────────────────────── -->
    <div id="js-dump-section"<?= $dump_active ? '' : ' class="is-hidden"' ?>>
      <div class="pc-section">
        <div class="pc-section-label">SQL Dump File</div>
        <?php if ($dump_active): ?>
          <div class="callout callout-info" style="margin-bottom:1rem">
            <?= View::icon('check') ?>
            <span>SQL dump already loaded for this session. <a href="<?= $b ?>/admin/import/<?= $src ?>/options" class="link">Skip to Options &rarr;</a></span>
          </div>
        <?php endif; ?>
        
      <div class="pc-section">
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="sql_dump">Upload File <span class="req">*</span></label>
          <div>
            <label class="file-drop-zone" for="sql_dump">
              <input id="sql_dump" type="file" name="sql_dump"
                     accept=".sql,text/plain,text/x-sql,application/sql">
              <span class="file-drop-icon"><?= View::icon('import') ?></span>
              <strong>Click to upload</strong>
              <span class="form-hint">.SQL &mdash; max 50 MB</span>
            </label>
            <p class="form-hint" style="margin-top:.5rem">Use a SQL export of the old database. Luster only reads table definitions and inserted rows from the file; it ignores other SQL commands.</p>
          </div>
        </div>
      </div>
    </div>
        </div>

    <div class="composer-footer">
      <a href="<?= $b ?>/admin/import" class="btn btn-quaternary">&#8592; Back</a>
      <button type="submit" id="js-submit-btn" class="btn btn-primary">
        Continue to Options &#8594;
      </button>
    </div>
  </div>
</form>

<?php if ($config_reader_enabled): ?>
<script>
(function () {
  var btn    = document.getElementById('js-autodetect-btn');
  var result = document.getElementById('js-autodetect-result');
  if (!btn || !result) { return; }

  function setResult(msg, color) {
    result.textContent = msg;
    result.style.color = color;
    result.style.display = '';
  }

  btn.addEventListener('click', function () {
    var path = (document.getElementById('js-config-path').value || '').trim();
    if (!path) {
      setResult('Enter a file path first.', 'var(--c-error, #c00)');
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Detecting…';

    var fd = new FormData();
    fd.append('_csrf', document.querySelector('[name="_csrf"]').value);
    fd.append('config_path', path);

    fetch('<?= $b ?>/admin/import/<?= $src ?>/detect-config', {method: 'POST', body: fd})
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;
        btn.textContent = 'Detect';
        if (!data.ok) {
          setResult(data.message || 'Detection failed.', 'var(--c-error, #c00)');
          return;
        }
        var fieldMap = {host: 'db_host', name: 'db_name', user: 'db_user', pass: 'db_pass'};
        var filled = 0;
        for (var key in fieldMap) {
          if (data[key]) {
            var el = document.getElementById(fieldMap[key]);
            if (el) { el.value = data[key]; filled++; }
          }
        }
        <?php if ($source === 'fanatically'): ?>
        if (data.prefix) {
          var pfx = document.getElementById('fn_table_prefix');
          if (pfx) { pfx.value = data.prefix; filled++; }
        }
        <?php endif; ?>
        if (filled > 0) {
          setResult(
            filled + ' field' + (filled === 1 ? '' : 's') + ' filled in — review before continuing.',
            'var(--c-success, green)'
          );
        } else {
          setResult(
            'No recognised credentials found in that file.',
            'var(--c-warning, #a60)'
          );
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Detect';
        setResult('Request failed — check the path and try again.', 'var(--c-error, #c00)');
      });
  });
}());
</script>
<?php endif; ?>

</div>
