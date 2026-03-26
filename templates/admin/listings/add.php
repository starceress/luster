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

$dataTfl    = json_encode($tfl_cats      ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataTafl   = json_encode($tafl_cats     ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustom = json_encode($custom_networks ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
?>

<p class="page-back">
  <a href="<?= $b ?>/admin/listings" class="back-link">&larr; Back to Owned Listings</a>
</p>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="card listing-field-card">
  <form method="POST" action="<?= $b ?>/admin/listings/add" enctype="multipart/form-data" class="js-warn-unsaved">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <div class="listing-field-section">

      <!-- ── Network & Category ── -->
      <div class="listing-field-row listing-field-row--top">
        <span class="listing-field-label">Network &amp; Category</span>
        <div>
          <div class="cat-widget" id="cat-widget"
               data-tfl='<?= $dataTfl ?>'
               data-tafl='<?= $dataTafl ?>'
               data-custom='<?= $dataCustom ?>'
               data-existing='[]'>
            <div id="cat-rows"></div>
            <a href="#" class="link" id="cat-add" style="font-size:.8125rem;display:inline-block;margin-top:.4rem">+ Add another category</a>
          </div>
          <p class="form-hint">The network(s) and category(ies) this listing is listed under.</p>
        </div>
      </div>

      <!-- ── Subject ── -->
      <div class="listing-field-row listing-field-row--top<?= !empty($fe['subject']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="fl-subject">Subject <span class="req">*</span></label>
        <div>
          <input id="fl-subject" type="text" name="subject" class="form-input" maxlength="255" required
                 value="<?= View::e($val('subject')) ?>">
          <p class="form-hint">The subject of your listing (e.g. character name, show title).</p>
          <?php if (!empty($fe['subject'])): ?><p class="field-error"><?= View::e($fe['subject'][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <!-- ── Title ── -->
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="fl-title">Title</label>
        <div>
          <input id="fl-title" type="text" name="title" class="form-input" maxlength="255"
                 value="<?= View::e($val('title')) ?>">
          <p class="form-hint">Optional display name — defaults to Subject if left blank.</p>
        </div>
      </div>

      <!-- ── URL ── -->
      <div class="listing-field-row listing-field-row--top<?= !empty($fe['url']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="fl-url">URL <span class="req">*</span></label>
        <div>
          <input id="fl-url" type="url" name="url" class="form-input" maxlength="500" placeholder="https://" required
                 value="<?= View::e($val('url')) ?>">
          <?php if (!empty($fe['url'])): ?><p class="field-error"><?= View::e($fe['url'][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <!-- ── Email ── -->
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="fl-email">Email</label>
        <div>
          <input id="fl-email" type="email" name="email" class="form-input" maxlength="255"
                 placeholder="admin@example.com"
                 value="<?= View::e($val('email')) ?>">
          <p class="form-hint">Address listing emails are sent from.</p>
        </div>
      </div>

      <!-- ── Listing Image ── -->
      <div class="listing-field-row">
        <span class="listing-field-label">Listing Image</span>
        <label class="file-drop-zone">
          <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
          <svg class="file-drop-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
            <rect x="2" y="2" width="12" height="12" rx="1.5"/>
            <circle cx="5.5" cy="5.5" r="1"/>
            <path d="M14 10l-3.5-3.5L6 11l-2-2-2 2"/>
          </svg>
          <span class="file-drop-label">Click to upload</span>
          <span class="file-drop-sub">JPEG, PNG, GIF, WebP &mdash; max&nbsp;2&nbsp;MB</span>
        </label>
      </div>

      <!-- ── Status ── -->
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

      <!-- ── Opened On ── -->
      <div class="listing-field-row" id="opened-row">
        <label class="listing-field-label" for="fl-opened">Opened On</label>
        <div>
          <input id="fl-opened" type="date" name="opened" class="form-input" style="max-width:180px"
                 value="<?= View::e($val('opened', $default_date)) ?>">
          <input type="hidden" id="fl-opened-tba" name="opened" value="TBA" disabled>
          <span id="fl-opened-tba-text" style="display:none;font-size:.85rem;color:var(--text-muted);font-style:italic">TBA &mdash; date will be set when the listing opens.</span>
        </div>
      </div>

    </div><!-- /.listing-field-section -->

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Add Listing</button>
      <button type="button" class="btn btn-ghost" id="clear-form-btn">Clear Form</button>
      <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
    </div>

  </form>
</div>
