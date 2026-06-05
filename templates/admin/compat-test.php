<?php
use Luster\Core\View;
/** @var \Luster\Models\Listing[] $listings */
/** @var string[]                 $embed_types */
/** @var int                      $selected_listing_id */
/** @var string                   $selected_type */
/** @var string|null              $output */
/** @var string|null              $error */
/** @var string                   $base_url */
/** @var string                   $csrf_token */
$b = rtrim(View::e($base_url), '/');
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/embed-codes" class="link">&larr; Back to Embed Codes</a>
</div>

<div class="gradient-card" style="margin-bottom:1.5rem">
  <div class="pc-head">
    <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('embed') ?></div>
    <div class="pc-head-meta">
      <div class="pc-head-title">Compat Wrapper Test</div>
      <div class="pc-head-sub">
        Render any embed output here to verify your compat wrapper setup before deploying.
      </div>
    </div>
  </div>

  <form method="POST" action="<?= $b ?>/admin/compat-test" class="pc-section">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div class="listing-field-row">
      <label class="listing-field-label" for="ct-listing">Listing</label>
      <div>
        <div class="select-wrap" style="max-width:300px">
          <select id="ct-listing" name="listing_id">
            <option value="0">— select a listing —</option>
            <?php foreach ($listings as $fl): ?>
            <option value="<?= (int) $fl->id ?>"
                    <?= $fl->id === $selected_listing_id ? 'selected' : '' ?>>
              <?= View::e($fl->title) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="listing-field-row">
      <label class="listing-field-label" for="ct-type">Embed Type</label>
      <div>
        <div class="select-wrap" style="max-width:200px">
          <select id="ct-type" name="embed_type">
            <option value="">— select type —</option>
            <?php foreach ($embed_types as $t): ?>
            <option value="<?= View::e($t) ?>" <?= $t === $selected_type ? 'selected' : '' ?>>
              <?= View::e(ucfirst($t)) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Render Embed</button>
    </div>
  </form>
</div>

<?php if ($error !== null): ?>
<div class="callout callout-err">
  <?= View::icon('alert') ?>
  <div><?= $error ?></div>
</div>
<?php endif; ?>

<?php if ($output !== null && $error === null): ?>
<div class="gradient-card" style="margin-bottom:1.5rem">
  <div class="pc-head">
    <div class="pc-head-meta">
      <div class="pc-head-title">Rendered Output &mdash; <?= View::e(ucfirst($selected_type)) ?></div>
      <div class="pc-head-sub">Listing #<?= (int) $selected_listing_id ?></div>
    </div>
  </div>
  <div class="pc-section">
    <?php if (trim($output) === ''): ?>
      <p class="form-hint">Embed returned empty output.</p>
    <?php else: ?>
      <div style="border:1px solid var(--border);border-radius:6px;padding:1.25rem;background:var(--bg-body)">
        <?= $output ?>
      </div>
      <p class="form-hint" style="margin-top:.75rem">
        The HTML above is what the embed renders. If it looks correct, your compat wrapper will work.
      </p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
