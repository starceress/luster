<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var \Luster\Models\Listing              $listing
 * @var int                                  $listing_id
 * @var \Luster\Addons\Rules\RuleSet|null    $rules      null = no rules yet
 * @var string                               $error
 * @var string                               $base_url
 * @var string                               $csrf_token
 */

$b = rtrim(View::e($base_url), '/');

$currentListStyle = $rules?->listStyle ?? 'numbered';
$currentGateType  = $rules?->gateType  ?? 'checkbox';
$defaultGateLabel = $currentGateType === 'button'
    ? 'I agree -- proceed to join form'
    : 'I have read and agree to the rules above.';
?>

<?php if ($error !== ''): ?>
<div class="alert alert-error" style="margin-bottom:1.25rem"><?= View::e($error) ?></div>
<?php endif; ?>

<?php if (isset($success) && $success !== ''): ?>
<div class="alert alert-success" style="margin-bottom:1.25rem"><?= View::e($success) ?></div>
<?php endif; ?>

  <div class="composer-grid">

    <!-- ── Main form ──────────────────────────────────────────────────── -->
    <form method="POST" action="<?= $b ?>/admin/rules/<?= (int) $listing_id ?>/edit">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

      <div class="gradient-card">
        <div class="listing-field-section">

          <div class="listing-field-row listing-field-row--top">
            <label class="listing-field-label" for="rules-body">
              Rules Text <span class="req">*</span>
            </label>
            <div>
              <textarea class="form form-textarea" id="rules-body" name="body"
                        rows="14"
                        maxlength="10000"
                        placeholder="Enter your join rules here&#x0a;&#x0a;You can use line breaks to separate rule items."><?= View::e($rules?->body ?? '') ?></textarea>
              <p class="form-hint">Plain text only. Line breaks are preserved when displayed. Editing this text will invalidate all existing fan agreements and require re-acceptance.</p>
            </div>
          </div>

          <div class="listing-field-row">
            <label class="listing-field-label" for="rules-enabled">Enabled</label>
            <div>
              <label class="toggle-wrap">
                <input type="checkbox" id="rules-enabled" name="enabled" value="1"
                       <?= ($rules !== null && $rules->enabled) ? 'checked' : '' ?>>
                <span class="toggle-label">Show rules gate on this listing</span>
              </label>
              <p class="form-hint">When disabled, the gate is hidden and fans can join without agreeing. The rules text is preserved.</p>
            </div>
          </div>

          <div class="listing-field-row">
            <label class="listing-field-label" for="rules-list-style">Bullet Style</label>
            <div>
              <div class="select-wrap select-auto">
                <select id="rules-list-style" name="list_style">
                  <option value="numbered" <?= $currentListStyle === 'numbered' ? 'selected' : '' ?>>Numbered (1, 2, 3...)</option>
                  <option value="circle"   <?= $currentListStyle === 'circle'   ? 'selected' : '' ?>>Bulleted</option>
                </select>
              </div>
              <p class="form-hint">Lines starting with "1. ", "2. ", etc. become list items&mdash;numbered keeps the numbers, bulleted strips them and shows bullets instead.</p>
            </div>
          </div>

          <div class="listing-field-row" id="rules-gate-style-row">
            <label class="listing-field-label" for="rules-gate-type">Gate Style</label>
            <div>
              <div class="select-wrap select-auto">
                <select id="rules-gate-type" name="gate_type">
                  <option value="checkbox" <?= $currentGateType === 'checkbox' ? 'selected' : '' ?>>Checkbox + button</option>
                  <option value="button"   <?= $currentGateType === 'button'   ? 'selected' : '' ?>>Single button</option>
                </select>
              </div>
              <p class="form-hint">"Checkbox + button" requires fans to tick a box before the button enables. "Single button" skips the checkbox entirely.</p>
            </div>
          </div>

          <div class="listing-field-row" id="rules-gate-label-row">
            <label class="listing-field-label" for="rules-gate-label">Gate Label</label>
            <div>
              <input class="form" type="text" id="rules-gate-label" name="gate_label"
                     maxlength="255"
                     value="<?= View::e($rules?->gateLabel ?? '') ?>"
                     placeholder="<?= View::e($defaultGateLabel) ?>">
              <p class="form-hint">Text shown on the agreement checkbox or button. Leave blank to use the default for the selected gate type.</p>
            </div>
          </div>

        </div><!-- /.listing-field-section -->

        <div class="composer-footer">
          <button type="submit" class="btn btn-primary">Save Rules</button>
          <a href="<?= $b ?>/admin/rules" class="btn btn-ghost">Cancel</a>
          <?php if ($rules !== null): ?>
          <div class="composer-footer-spacer"></div>
          <button type="submit" form="rules-delete-form" class="btn btn-danger">Delete</button>
          <?php endif; ?>
        </div>
      </div><!-- /.gradient-card -->
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

      <?php if ($rules !== null): ?>
      <div class="card">
        <div class="card-head">Status</div>
        <div class="pc-section">
          <div class="listing-field-row listing-field-row--compact">
            <span class="listing-field-label text-muted">Listing</span>
            <span><?= View::e($listing->title) ?></span>
          </div>
          <div class="listing-field-row listing-field-row--compact">
            <span class="listing-field-label text-muted">State</span>
            <span>
              <?= $rules->enabled
                ? '<span class="badge badge-current">Enabled</span>'
                : '<span class="badge badge-pending">Disabled</span>' ?>
            </span>
          </div>
          <div class="listing-field-row listing-field-row--compact">
            <span class="listing-field-label text-muted">Version</span>
            <span>v<?= (int) $rules->version ?></span>
          </div>
          <div class="listing-field-row listing-field-row--compact">
            <span class="listing-field-label text-muted">Updated</span>
            <span class="text-muted"><?= View::e(date('Y-m-d', strtotime($rules->updatedAt))) ?></span>
          </div>
          <div class="listing-field-row listing-field-row--compact">
            <span class="listing-field-label text-muted">List Style</span>
            <span><?= $rules->listStyle === 'circle' ? 'Bulleted' : 'Numbered' ?></span>
          </div>
          <div class="listing-field-row listing-field-row--compact">
            <span class="listing-field-label text-muted">Gate Type</span>
            <span><?= $rules->gateType === 'button' ? 'Single button' : 'Checkbox + button' ?></span>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <details class="card chevron">
        <summary class="chevron-head">Available Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm">Use these in your rules text &mdash; they'll be replaced when shown to fans.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <tr><td><code>{subject}</code></td><td>Listing subject name</td></tr>
            <tr><td><code>{listing_title}</code></td><td>Listing title</td></tr>
            <tr><td><code>{listing_url}</code></td><td>Listing's external URL</td></tr>
            <tr><td><code>{join_url}</code></td><td>Link to the join form</td></tr>
            <tr><td><code>{update_url}</code></td><td>Link to the update form</td></tr>
          </tbody></table>
        </div>
      </details>

      <div class="card">
        <div class="card-head">How to Use</div>
        <div class="pc-section">
            <p> Add the rules gate to your join page using the embed file. Place it <em>before</em> your join embed:</p>
          <pre class="snip-pre">$luster_fanlisting_id = <?= (int) $listing_id ?>;
$luster_return = true;

$gate = require '/path/to/luster/public/embed/rules-gate.php';
if ($gate !== '') {
    echo $gate;
    return; // stop rendering — fan must agree first
}

// Show join form here
require '/path/to/luster/public/embed/join.php';</pre>
          <p>The gate returns an empty string once the fan has agreed, so your join form renders normally.</p>
        </div>
      </div>

      <div class="card">
        <div class="card-head">Agreement Reset</div>
        <div class="pc-section">
            Each time you save a new rules text, the version number increments and all fan agreements in active sessions become invalid. Fans will see the rules gate again on their next visit.
        </div>
      </div>

    </div>
  </div>
