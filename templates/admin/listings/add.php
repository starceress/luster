<?php
use Luster\Core\View;
/** @var array  $tfl_cats        [{id: int, name: string}] */
/** @var array  $tafl_cats       [{id: int, name: string}] */
/** @var array  $custom_networks string[] */
/** @var array  $old */
/** @var string $error */
/** @var array  $field_errors */
/** @var string $default_date */
/** @var string $base_url */
/** @var string $csrf_token */
$b  = rtrim(View::e($base_url), '/');
$fe = $field_errors ?? [];

$val = static function (string $k, string $def = '') use ($old): string {
    return isset($old[$k]) ? (string) $old[$k] : $def;
};

$selStatus = isset($old['status']) ? (int) $old['status'] : 2;

$dataTfl        = json_encode($tfl_cats       ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataTafl       = json_encode($tafl_cats      ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustom     = json_encode($custom_networks ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustomCats = json_encode($custom_cats     ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/listings" class="link">&larr; Back to Owned Listings</a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="gradient-card">
  <div class="pc-head">
    <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('star') ?></div>
    <div class="pc-head-title">Add New Listing</div>
  </div>
  <form method="POST" action="<?= $b ?>/admin/listings/add" enctype="multipart/form-data" class="js-warn-unsaved">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <div class="listing-field-section">
      <div class="listing-field-row">
        <span class="listing-field-label">Network & Category</span>
        <div>
          <div class="cat-widget" id="cat-widget"
               data-tfl='<?= $dataTfl ?>'
               data-tafl='<?= $dataTafl ?>'
               data-custom='<?= $dataCustom ?>'
               data-custom-cats='<?= $dataCustomCats ?>'
               data-existing='[]'>
            <div id="cat-rows"></div>
            <a href="#" class="link" id="cat-add"><?= View::icon('add') ?> Add another category</a>
          </div>
          <p class="form-hint">The network(s) and category(ies) this listing is listed under.</p>
        </div>
      </div>

      <div class="listing-field-row<?= !empty($fe['subject']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="fl-subject">Subject <span class="req">*</span></label>
        <div>
          <input id="fl-subject" type="text" name="subject" class="form" maxlength="255" required
                 value="<?= View::e($val('subject')) ?>">
          <p class="form-hint">The subject of your listing (e.g. character name, show title).</p>
          <?php if (!empty($fe['subject'])): ?><p class="field-error"><?= View::e($fe['subject'][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-title">Title</label>
        <div>
          <input id="fl-title" type="text" name="title" class="form" maxlength="255"
                 value="<?= View::e($val('title')) ?>">
          <p class="form-hint">Optional name of your listing — defaults to "Subject" if left blank.</p>
        </div>
      </div>

      <div class="listing-field-row<?= !empty($fe['url']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="fl-url">Website URL <span class="req">*</span></label>
        <div>
          <input id="fl-url" type="url" name="url" class="form" maxlength="500" placeholder="https://" required
                 value="<?= View::e($val('url')) ?>">
          <?php if (!empty($fe['url'])): ?><p class="field-error"><?= View::e($fe['url'][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-email">Email</label>
        <div>
          <input id="fl-email" type="email" name="email" class="form" maxlength="255"
                 placeholder="admin@example.com"
                 value="<?= View::e($val('email')) ?>">
          <p class="form-hint">This is the email address that listing emails will be sent from.</p>
        </div>
      </div>

      <div class="listing-field-row">
        <span class="listing-field-label">Image</span>
        <label class="file-drop-zone">
          <input id="fl-image" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
          <span class="file-drop-icon"><?= View::icon('image') ?></span>
              <strong>Click to upload</strong>
              <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
        </label>
      </div>

      <div class="listing-field-row">
        <span class="listing-field-label">Status</span>
        <div class="status-pill-row">
          <label class="status-pill status-pill--current">
            <input type="radio" name="status" value="2" <?= $selStatus === 2 ? 'checked' : '' ?>>
            Current
          </label>
          <label class="status-pill status-pill--upcoming">
            <input type="radio" name="status" value="1" <?= $selStatus === 1 ? 'checked' : '' ?>>
            Upcoming
          </label>
          <label class="status-pill status-pill--pending">
            <input type="radio" name="status" value="0" <?= $selStatus === 0 ? 'checked' : '' ?>>
            Pending
          </label>
        </div>
      </div>

      <div class="listing-field-row" id="opened-row">
        <label class="listing-field-label" for="fl-opened">Opened On</label>
        <div>
          <input id="fl-opened" type="date" name="opened" class="form form--compact"
                 value="<?= View::e($val('opened', $default_date)) ?>">
          <input type="hidden" id="fl-opened-tba" name="opened" value="TBA" disabled>
          <span id="fl-opened-tba-text" class="text-dim">TBA &mdash; date will be set when the listing opens.</span>
        </div>
      </div>

    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Add Listing</button>
      <button type="button" class="btn btn-ghost" id="clear-form-btn">Clear</button>
      <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
    </div>

  </form>
</div>
