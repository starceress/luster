<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Owned listings settings. Configures how the listings index page behaves
 (sort order, items per page) and the default form page URLs that tell
 Luster which host pages hold each fan-facing form.
*/
use Luster\Core\View;
use Luster\Repositories\SiteSettingsRepo;
$b = rtrim(View::e($base_url), '/');
$_formUrls    = $form_urls ?? [];
$_urlDefaults = SiteSettingsRepo::GLOBAL_FORM_URL_KEYS;
$_urlFields   = [
    'global_form_url_join'            => ['Join Form',               'The page where visitors can join your listing.'],
    'global_form_url_update'          => ['Update Membership Form',  'The page where members can update their details.'],
    'global_form_url_reset'           => ['Password Reset Form',     'The page where members can reset their password.'],
    'global_form_url_affiliate_apply' => ['Affiliate Application',   'The page where sites can apply to affiliate.'],
    'global_form_url_codes_submit'    => ['Code Donation Form',      'The page where fans can submit codes.'],
    'global_form_url_contact'         => ['Contact Form',            'The page with the contact form.'],
];
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/listings" class="link">
    &larr; Back to Owned Listings
  </a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/listings/settings" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon"><?= View::icon('gear') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Owned Listing Settings</div>
        <div class="panel-header-sub">Display preferences and default form page URLs for your listings.</div>
      </div>
    </div>

    <div class="card-body">
      <div class="field-row field-row--top">
        <label class="field-label" for="fl-per-page">Listings per page</label>
        <div>
          <input type="number" id="fl-per-page" name="owned_per_page" class="form form--compact" min="5" max="50" value="<?= (int) ($cfg['owned_per_page'] ?? 10) ?>">
          <p class="form-hint">How many listings to show on each admin page. Choose 5&ndash;50.</p>
        </div>
      </div>
      <?php $_previewSize = (string) ($cfg['image_preview_size'] ?? 'small'); ?>
      <div class="field-row">
        <label class="field-label" for="fl-image-preview-size">Image preview size</label>
        <div>
          <div class="select-wrap select-auto">
            <select id="fl-image-preview-size" name="image_preview_size">
              <option value="small"  <?= $_previewSize === 'small'  ? 'selected' : '' ?>>Small</option>
              <option value="medium" <?= $_previewSize === 'medium' ? 'selected' : '' ?>>Medium</option>
              <option value="large"  <?= $_previewSize === 'large'  ? 'selected' : '' ?>>Large</option>
            </select>
          </div>
          <p class="form-hint">How tall listing image thumbnails appear on the Owned Listings and Edit Listing pages.</p>
        </div>
      </div>
    </div>

    <div class="card-body">
      <div class="section-label">Host Page URLs</div>

      <p>
        When someone joins a listing, resets their password, or applies to affiliate, Luster needs to know which page on your
        site hosts that form. <strong>Most sites can leave these blank</strong> &mdash; Luster will just send fans to the listing&rsquo;s
        own page instead, which works fine for most sites. Fill these in if you&rsquo;d rather send fans straight to a specific
        page (like a dedicated <code>join.php</code>) instead. Set filenames here and they&rsquo;ll apply to every listing by
        default; a specific listing can still override it from that listing&rsquo;s <strong>Embed</strong> tab.
        <span class="ec-setup-help" data-desc="Luster checks three places, in this order: the page set on a specific listing&rsquo;s Embed tab, then the default you set here, then finally just the listing&rsquo;s own URL &mdash; whichever it finds first wins.">?</span>
      </p>
      <?php $first = true; foreach ($_urlFields as $key => [$label, $hint]): ?>
        <div class="field-row<?= $first ? ' field-row--top' : '' ?>">
          <label class="field-label" for="<?= View::e($key) ?>"><?= View::e($label) ?></label>
          <div>
            <input id="<?= View::e($key) ?>" type="text" name="<?= View::e($key) ?>" class="form"
                   maxlength="200"
                   value="<?= View::e((string) ($_formUrls[$key] ?? '')) ?>"
                   placeholder="<?= View::e($_urlDefaults[$key]) ?>">
            <p class="form-hint"><?= View::e($hint) ?> Leave blank to use the built-in default (<code><?= View::e($_urlDefaults[$key]) ?></code>).</p>
          </div>
        </div>
      <?php $first = false; endforeach; ?>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>