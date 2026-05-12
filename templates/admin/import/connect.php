<?php
use Luster\Core\View;
/** @var string $source */
/** @var string $source_name */
/** @var string $error */
/** @var array  $saved_creds */
/** @var bool   $dump_active   true when a SQL dump has already been uploaded this session */
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

<?php if ($dump_active): ?>
  <div class="alert alert-info import-stack-gap">
    <?= View::icon('check') ?> SQL dump already loaded for this session.
    <a href="<?= $b ?>/admin/import/<?= $src ?>/scope" class="link" style="margin-left:.5rem">
      Skip to Options &rarr;
    </a>
  </div>
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
        <div class="pc-head-sub">How should Luster access the source database?</div>
      </div>
    </div>

    <!-- ── Connection method ──────────────────────────────────────── -->
    <div class="pc-section">
      <div class="import-choice">
        <label class="import-choice-item">
          <input type="radio" name="_conn_mode" value="live" id="js-mode-live" checked>
          <div>
            <div class="import-choice-title">Live database connection</div>
            <div class="import-choice-sub">
              Enter credentials directly.
              <?php if ($source === 'enthusiast'): ?>
                Leave all fields blank if Enthusiast shares Luster&rsquo;s database.
              <?php endif; ?>
            </div>
          </div>
        </label>
        <label class="import-choice-item">
          <input type="radio" name="_conn_mode" value="dump" id="js-mode-dump">
          <div>
            <div class="import-choice-title">Upload SQL dump</div>
            <div class="import-choice-sub">Upload a <code>.sql</code> file exported with <code>mysqldump</code> (max&nbsp;50&nbsp;MB). Schema is detected automatically.</div>
          </div>
        </label>
      </div>
    </div>

    <!-- ── Live DB credentials ───────────────────────────────────── -->
    <div id="js-live-db-section">
      <div class="pc-section">
        <div class="pc-section-label">Database Credentials</div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="db_host">Host</label>
            <input id="db_host" type="text" name="db_host" class="form"
                   placeholder="localhost"
                   value="<?= View::e((string) ($saved_creds['host'] ?? '')) ?>">
          </div>
          <div class="form-group">
            <label class="form-label" for="db_name">Database Name</label>
            <input id="db_name" type="text" name="db_name" class="form"
                   value="<?= View::e((string) ($saved_creds['name'] ?? '')) ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="db_user">User</label>
            <input id="db_user" type="text" name="db_user" class="form"
                   value="<?= View::e((string) ($saved_creds['user'] ?? '')) ?>">
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

    <!-- ── SQL dump upload ──────────────────────────────────────── -->
    <div id="js-dump-section" class="is-hidden">
      <div class="pc-section">
        <div class="pc-section-label">SQL Dump File</div>
        <div class="form-group">
          <label class="form-label" for="sql_dump">SQL File <span class="text-muted">(max&nbsp;50&nbsp;MB)</span></label>
          <input id="sql_dump" type="file" name="sql_dump" accept=".sql,text/plain,text/x-sql,application/sql"
                 class="form import-input-wide">
          <p class="field-hint-sm">Export with <code>mysqldump --no-tablespaces &lt;dbname&gt; &gt; export.sql</code>. Only <code>CREATE TABLE</code> and <code>INSERT INTO</code> statements are executed.</p>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <a href="<?= $b ?>/admin/import" class="btn btn-ghost">&#8592; Back</a>
      <button type="submit" id="js-submit-btn" class="btn btn-primary">
        Continue to Options &#8594;
      </button>
    </div>
  </div>

</form>

<p class="field-hint-sm import-stack-gap" style="text-align:center;color:var(--text-dim)">
  Credentials are stored only in your session and cleared immediately after the import completes.
</p>

</div>
