<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Join Rules admin per-listing editor. Allows configuring the rules page body content
 (with token placeholders), the gate type (checkbox or single button), gate label,
 and enabled/disabled toggle for a specific listing. Falls back to the default body
 template when no custom content has been set.
*/

declare(strict_types=1);

use Luster\Core\View;
use Luster\Support\RulesBodyRenderer;

/**
 * @var \Luster\Models\Listing              $listing
 * @var int                                  $listing_id
 * @var \Luster\Addons\Rules\RuleSet|null    $rules      null = no rules yet
 * @var string                               $error
 * @var string                               $base_url
 * @var string                               $csrf_token
 */

$b = rtrim(View::e($base_url), '/');

$defaultBody = RulesBodyRenderer::defaultBody();
$bodyVal     = ($rules?->body ?? '') !== '' ? $rules->body : $defaultBody;

$currentGateType  = $rules?->gateType  ?? 'checkbox';
$defaultGateLabel = $currentGateType === 'button'
    ? 'Proceed'
    : 'Proceed';
?>

<?php if (!($_rules_embedded ?? false)): ?>
<div class="toolbar">
  <a href="<?= $b ?>/admin/rules" class="link">&larr; Back to Join Rules</a>
</div>

<?php if ($error !== ''): ?>
<div class="alert alert-error" style="margin-bottom:1.25rem"><?= View::e($error) ?></div>
<?php endif; ?>

<?php if (isset($success) && $success !== ''): ?>
<div class="alert alert-success" style="margin-bottom:1.25rem"><?= View::e($success) ?></div>
<?php endif; ?>
<?php endif; ?>

  <div class="composer-grid">

    <!-- ── Main form ──────────────────────────────────────────────────── -->
    <form method="POST" action="<?= $b ?>/admin/rules/<?= (int) $listing_id ?>/edit">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <?php if ($_rules_embedded ?? false): ?>
      <input type="hidden" name="_redirect_to_listing" value="<?= (int) $listing_id ?>">
      <?php endif; ?>

      <div class="card card--gradient">
        <div class="panel-header">
          <div class="panel-header-icon">
          <?= View::icon('rules') ?>
          </div>
          <div class="panel-header-meta">
          <div class="panel-header-title">Global Join Rules HTML Template</div>
          <div class="panel-header-sub">Choose the HTML Luster prints for your Per-Listing Join Rules. This template will override the Global Join Rules template for this specific listing only.</div>
          </div></div>
        <div class="card-body">

          <div class="field-row">
            <label class="field-label" for="rules-body">
              Rules Template <span class="req">*</span>
            </label>
            <div>
              <textarea class="form form-mono" id="rules-body" name="body"
                        rows="16"
                        maxlength="10000"
                        spellcheck="false"><?= View::e($bodyVal) ?></textarea>
              <p class="form-hint">HTML is allowed. Use tokens like <code>{subject}</code>, <code>{gate_checkbox}</code>, and <code>{gate_button}</code> to insert dynamic content. Editing this text will increment the version number and require fans to agree again on their next visit.</p>
              <div class="tpl-reset-row">
                <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="rules" data-textarea="rules-body"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
                <button type="button" class="btn btn-quaternary btn-xs" data-textarea="rules-body">Reset to Luster Default</button>
              </div>
            </div>
          </div>

          <div class="field-row">
            <label class="field-label" for="rules-enabled">Enabled</label>
            <div>
              <label class="toggle-wrap">
                <input type="checkbox" id="rules-enabled" name="enabled" value="1"
                       <?= ($rules !== null && $rules->enabled) ? 'checked' : '' ?>>
                <span class="toggle-label">Show rules gate on this listing</span>
              </label>
              <p class="form-hint">When disabled, the gate is hidden and fans can join without agreeing. The rules template is preserved.</p>
            </div>
          </div>

          <div class="field-row" id="rules-gate-style-row" style="<?= ($rules !== null && $rules->enabled) ? '' : 'display:none' ?>">
            <label class="field-label" for="rules-gate-type">Gate Style</label>
            <div>
              <div class="select-wrap select-auto">
                <select id="rules-gate-type" name="gate_type">
                  <option value="checkbox" <?= $currentGateType === 'checkbox' ? 'selected' : '' ?>>Checkbox + Button</option>
                  <option value="button"   <?= $currentGateType === 'button'   ? 'selected' : '' ?>>Single Button</option>
                </select>
              </div>
              <p class="form-hint">"Checkbox + Button" — <code>{gate_checkbox}</code> renders a required checkbox; <code>{gate_button}</code> renders the submit button. "Single Button" — <code>{gate_checkbox}</code> renders as empty; <code>{gate_button}</code> renders a button that submits the agreement directly.</p>
            </div>
          </div>

          <div class="field-row" id="rules-gate-label-row">
            <label class="field-label" for="rules-gate-label">Gate Label</label>
            <div>
              <input class="form" type="text" id="rules-gate-label" name="gate_label"
                     maxlength="255"
                     value="<?= View::e($rules?->gateLabel ?? '') ?>"
                     placeholder="<?= View::e($defaultGateLabel) ?>">
              <p class="form-hint">Text shown on the <code>{gate_button}</code> button, used for both gate styles. <code>{gate_checkbox}</code> renders an unlabeled checkbox &mdash; add your own label text in the template body next to it. Leave blank to use the default for the selected gate type. Only takes effect while the gate is enabled above.</p>
            </div>
          </div>

        </div><!-- /.card-body -->

        <div class="composer-footer">
          <button type="submit" class="btn btn-primary">Save Rules</button>
          <button type="reset" class="btn btn-quaternary">Reset</button>
          <?php if ($rules !== null): ?>
          <div class="composer-footer-spacer"></div>
          <button type="submit" form="rules-delete-form" class="btn btn-danger">Delete</button>
          <?php endif; ?>
        </div>
      </div><!-- /.card--gradient -->
    </form>

    <?php if ($rules !== null): ?>
    <form id="rules-delete-form"
          method="POST"
          action="<?= $b ?>/admin/rules/<?= (int) $listing_id ?>/delete"
          class="action-form-inline"
          data-del-modal
          data-del-entity="rules"
          data-del-name="<?= View::e($listing->title) ?>">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    </form>
    <?php endif; ?>

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

    </div>
  </div>

<?php if (!($_rules_embedded ?? false)): ?>
<script type="application/json" id="luster-coltpl-defaults">
<?= json_encode(['rules-body' => $defaultBody], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>

<script type="application/json" id="luster-coltpl-meta"><?= json_encode([
    'previewUrl' => $b . '/admin/listings/' . (int) $listing_id . '/templates/preview',
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
      <iframe id="coltpl-prev-iframe" title="Template preview" sandbox="allow-scripts"></iframe>
    </div>
  </div>
</div>
<?php endif; ?>
