<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compat wrapper test page. Lets the admin select a listing and embed type, then
 renders the resulting embed output inline so legacy compatibility wrappers can
 be verified before deploying to a live site.
*/
use Luster\Core\View;
$b = rtrim(View::e($base_url), '/');
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/embed-codes" class="link">&larr; Back to Embed Codes</a>
</div>

<div class="card card--gradient" style="margin-bottom:1.5rem">
  <div class="panel-header">
    <div class="panel-header-icon"><?= View::icon('embed') ?></div>
    <div class="panel-header-meta">
      <div class="panel-header-title">Compat Wrapper Test</div>
      <div class="panel-header-sub">
        Render any embed output here to verify your compat wrapper setup before deploying.
      </div>
    </div>
  </div>

  <form method="POST" action="<?= $b ?>/admin/compat-test" class="card-body">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div class="field-row">
      <label class="field-label" for="ct-listing">Listing</label>
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

    <div class="field-row">
      <label class="field-label" for="ct-type">Embed Type</label>
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
<div class="card card--gradient" style="margin-bottom:1.5rem">
  <div class="panel-header">
    <div class="panel-header-meta">
      <div class="panel-header-title">Rendered Output &mdash; <?= View::e(ucfirst($selected_type)) ?></div>
      <div class="panel-header-sub">Listing #<?= (int) $selected_listing_id ?></div>
    </div>
  </div>
  <div class="card-body">
    <?php if (trim($output) === ''): ?>
      <p class="form-hint">Embed returned empty output.</p>
    <?php else: ?>
      <div style="border:1px solid var(--border);border-radius:6px;padding:1.25rem;background:var(--bg)">
        <?= $output ?>
      </div>
      <p class="form-hint" style="margin-top:.75rem">
        The HTML above is what the embed renders. If it looks correct, your compat wrapper will work.
      </p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
