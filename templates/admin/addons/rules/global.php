<?php

declare(strict_types=1);

use Luster\Core\View;
use Luster\Support\RulesBodyRenderer;

/**
 * @var string $global_body
 * @var string $global_gate_enabled
 * @var string $global_gate_type
 * @var string $global_gate_label
 * @var string $success
 * @var string $error
 * @var string $base_url
 * @var string $csrf_token
 */

$b = rtrim(View::e($base_url), '/');

$defaultBody = RulesBodyRenderer::defaultBody();
$bodyVal     = $global_body !== '' ? $global_body : $defaultBody;

$currentGateType  = $global_gate_type  !== '' ? $global_gate_type  : 'checkbox';
$gateIsEnabled    = $global_gate_enabled === '1';
$defaultGateLabel = $currentGateType === 'button'
    ? 'Proceed'
    : 'Proceed';
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/rules" class="link">&larr; Back to Join Rules</a>
</div>

<?php if ($success !== ''): ?>
<div class="alert alert-success" style="margin-bottom:1.25rem"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
<div class="alert alert-error" style="margin-bottom:1.25rem"><?= View::e($error) ?></div>
<?php endif; ?>

  <div class="composer-grid">

    <!-- ── Main form ──────────────────────────────────────────────────── -->
    <form method="POST" action="<?= $b ?>/admin/rules/global">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

      <div class="card card--gradient">
        <div class="pc-head">
          <div class="pc-head-icon pc-head-icon-bg">
          <?= View::icon('rules') ?>
          </div>
          <div class="pc-head-meta">
          <div class="pc-head-title">Global Join Rules HTML Template</div>
          <div class="pc-head-sub">Choose the HTML Luster prints for your Global Join Rules. These rules are applied as the default template throughout your entire collective unless overrided by custom per-listing join rules.</div>
          </div></div>
        <div class="card-body">

          <div class="field-row field-row--top">
            <label class="field-label" for="global-rules-body">
              Rules Template
            </label>
            <div>
              <textarea class="form form-mono" id="global-rules-body" name="body"
                        rows="16"
                        maxlength="10000"
                        spellcheck="false"><?= View::e($bodyVal) ?></textarea>
              <p class="form-hint">This is the default rules page fans see before they can join. It applies to every listing unless that listing has its own custom rules. You can use HTML and tokens like <code>{subject}</code> or <code>{gate_button}</code> to fill in listing details automatically. If you change this text, fans who already agreed will need to agree again next time they visit.</p>
              <div class="tpl-reset-row">
                <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="rules" data-textarea="global-rules-body"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
                <button type="button" class="btn btn-quaternary btn-xs" data-textarea="global-rules-body">Reset to Luster Rules</button>
              </div>
            </div>
          </div>

          <div class="field-row">
            <label class="field-label" for="global-rules-enabled">Gate Enabled</label>
            <div>
              <label class="toggle-wrap">
                <input type="checkbox" id="global-rules-enabled" name="gate_enabled" value="1"
                       <?= $gateIsEnabled ? 'checked' : '' ?>>
                <span class="toggle-label">Show rules gate on listings using this template</span>
              </label>
              <p class="form-hint">When turned off, fans can join without seeing or agreeing to rules. Your rules text is kept safe &mdash; just not shown.</p>
            </div>
          </div>

          <div class="field-row" id="global-rules-gate-style-row" style="<?= $gateIsEnabled ? '' : 'display:none' ?>">
            <label class="field-label" for="global-rules-gate-type">Gate Style</label>
            <div>
              <div class="select-wrap select-auto">
                <select id="global-rules-gate-type" name="gate_type">
                  <option value="checkbox" <?= $currentGateType === 'checkbox' ? 'selected' : '' ?>>Checkbox + Button</option>
                  <option value="button"   <?= $currentGateType === 'button'   ? 'selected' : '' ?>>Single Button</option>
                </select>
              </div>
              <p class="form-hint">&ldquo;Checkbox + Button&rdquo; shows a checkbox fans must tick before clicking the button. &ldquo;Single Button&rdquo; skips the checkbox &mdash; fans just click a button to agree and continue.</p>
            </div>
          </div>

          <div class="field-row" id="global-rules-gate-label-row">
            <label class="field-label" for="global-rules-gate-label">Gate Label</label>
            <div>
              <input class="form" type="text" id="global-rules-gate-label" name="gate_label"
                     maxlength="255"
                     value="<?= View::e($global_gate_label) ?>"
                     placeholder="<?= View::e($defaultGateLabel) ?>">
              <p class="form-hint">The text on the agree button (e.g. &ldquo;I agree&rdquo; or &ldquo;Proceed&rdquo;). Leave blank to use the default. Only matters when the gate is turned on above.</p>
            </div>
          </div>

        </div><!-- /.card-body -->

        <div class="composer-footer">
          <button type="submit" class="btn btn-primary">Save Global Rules</button>
          <button type="button" class="btn btn-quaternary js-rules-reset-defaults" data-form-reset="global-rules-body">Reset Form to Default</button>
        </div>
      </div><!-- /.card--gradient -->
    </form>

    <!-- ── Sidebar ────────────────────────────────────────────────────── -->
    <div class="composer-side">

      <details class="card chevron">
        <summary class="chevron-head">Available Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint" style="margin-bottom:.75rem">Use these in your rules template &mdash; they're replaced when shown to fans.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <tr><td><code>{subject}</code></td><td>Listing subject name</td></tr>
            <tr><td><code>{listing_title}</code></td><td>Listing title</td></tr>
            <tr><td><code>{listing_url}</code></td><td>Listing's external URL</td></tr>
            <tr><td><code>{join_url}</code></td><td>Link to the join form</td></tr>
            <tr><td><code>{update_url}</code></td><td>Link to the update form</td></tr>
            <tr><td><code>{gate_checkbox}</code></td><td>Agreement checkbox (empty in button mode)</td></tr>
            <tr><td><code>{gate_button}</code></td><td>Agreement button (label from Gate Label)</td></tr>
            <tr><td><code>{if_gate_checkbox}...{/if_gate_checkbox}</code></td><td>Wraps content shown only when the gate style is "Checkbox + Button" (removed entirely in "Single Button" mode)</td></tr>
          </tbody></table>
        </div>
      </details>

    </div><!-- /.composer-side -->
  </div><!-- /.composer-grid -->

<script type="application/json" id="luster-coltpl-defaults">
<?= json_encode(['global-rules-body' => $defaultBody], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>

<script type="application/json" id="luster-coltpl-meta"><?= json_encode([
    'previewUrl' => $b . '/admin/rules/global/preview',
    'csrf'       => $csrf_token ?? '',
], JSON_UNESCAPED_SLASHES) ?></script>

<div id="coltpl-prev-modal" class="modal-backdrop is-hidden" style="display:none" role="dialog" aria-modal="true" aria-label="Template preview">
  <div class="modal modal--preview" style="max-width:680px;height:68vh">
    <div class="modal-header">
      <span id="coltpl-prev-title" class="modal-title">Preview</span>
      <div style="display:flex;align-items:center;gap:.5rem">
        <span id="coltpl-prev-spinner" style="display:none;font-size:.8rem;color:var(--text-muted)">Loading&hellip;</span>
        <button type="button" id="coltpl-prev-close" class="btn btn-tertiary btn-sm" aria-label="Close preview">&times;&ensp;Close</button>
      </div>
    </div>
    <div class="modal-body--iframe">
      <iframe id="coltpl-prev-iframe" title="Template preview" sandbox=""></iframe>
    </div>
  </div>
</div>
