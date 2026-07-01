<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Add new listing form. Provides fields for title, subject, URL, email, status, date
 opened, category selection (TFL/TAFL/custom networks), database table prefix, and
 optional image upload. Validates and repopulates on error.
*/
use Luster\Core\View;
$b  = rtrim(View::e($base_url), '/');
$fe = $field_errors ?? [];

$val = static function (string $k, string $def = '') use ($old): string {
    return isset($old[$k]) ? (string) $old[$k] : $def;
};

$selStatus        = isset($old['status']) ? (string) $old['status'] : 'current';
$oldAdoptedToName = (string) ($old['adopted_to_name'] ?? '');
$oldAdoptedToUrl  = (string) ($old['adopted_to_url']  ?? '');
$oldArchiveNote   = (string) ($old['archive_note']    ?? '');

$dataTfl        = json_encode($tfl_cats       ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataTafl       = json_encode($tafl_cats      ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustom     = json_encode($custom_networks ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustomCats = json_encode($custom_cats     ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/listings" class="link">&larr; Back to Owned Listings</a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="card card--gradient">
  <div class="panel-header">
    <div class="panel-header-icon panel-header-icon-filled"><?= View::icon('star') ?></div>
    <div class="panel-header-title">Add New Listing</div>
  </div>
  <form method="POST" action="<?= $b ?>/admin/listings/add" enctype="multipart/form-data" class="js-warn-unsaved">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <div class="card-body">
      <div class="field-row">
        <label class="field-label" for="fl-listing-type">Listing Type</label>
        <div>
          <div class="select-wrap select-auto">
            <select id="fl-listing-type" name="listing_type">
              <?php $lt = $val('listing_type', 'listing'); ?>
              <option value="listing"    <?= $lt === 'listing'    ? 'selected' : '' ?>>Fanlisting</option>
              <option value="clique"     <?= $lt === 'clique'     ? 'selected' : '' ?>>Clique</option>
              <option value="collective" <?= $lt === 'collective' ? 'selected' : '' ?>>Collective</option>
              <option value="shrine"     <?= $lt === 'shrine'     ? 'selected' : '' ?>>Shrine</option>
              <option value="webring"    <?= $lt === 'webring'    ? 'selected' : '' ?>>Webring</option>
              <option value="other"      <?= $lt === 'other'      ? 'selected' : '' ?>>Other</option>
            </select>
          </div>
          <p class="form-hint">Helps categorize your listing by type (e.g. fanlisting, clique, shrine).</p>
        </div>
      </div>

      <div class="field-row">
        <span class="field-label">Network & Category</span>
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
          <p class="form-hint">Choose the network and category this listing belongs to. Add another category if it is listed in more than one place.</p>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['subject']) ? ' has-error' : '' ?>">
        <label class="field-label" for="fl-subject">Subject <span class="req">*</span></label>
        <div>
          <input id="fl-subject" type="text" name="subject" class="form" maxlength="255" required
                 value="<?= View::e($val('subject')) ?>">
          <p class="form-hint">The subject of your listing (e.g. character name, show title).</p>
          <?php if (!empty($fe['subject'])): ?><p class="field-error"><?= View::e($fe['subject'][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="fl-title">Title</label>
        <div>
          <input id="fl-title" type="text" name="title" class="form" maxlength="255"
                 value="<?= View::e($val('title')) ?>">
          <p class="form-hint">Optional display name for your listing. If you leave it blank, Luster uses the subject.</p>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['url']) ? ' has-error' : '' ?>">
        <label class="field-label" for="fl-url">Website URL <span class="req">*</span></label>
        <div>
          <input id="fl-url" type="url" name="url" class="form" maxlength="500" placeholder="https://" required
                 value="<?= View::e($val('url')) ?>">
          <?php if (!empty($fe['url'])): ?><p class="field-error"><?= View::e($fe['url'][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="fl-email">Email</label>
        <div>
          <input id="fl-email" type="email" name="email" class="form" maxlength="255"
                 placeholder="admin@example.com"
                 value="<?= View::e($val('email')) ?>">
          <p class="form-hint">Luster uses this address as the sender for emails from this listing.</p>
        </div>
      </div>

      <div class="field-row field-row--top">
        <label class="field-label" for="fl-description">Description</label>
        <div>
          <textarea id="fl-description" name="description" class="form" rows="4" maxlength="65535"
                    placeholder="A short description of this listing&hellip;"><?= View::e($val('description')) ?></textarea>
          <p class="form-hint">Optional. Available in embed templates as <code>{listing_description}</code> and on your site pages as <code>&lt;?= $luster_description ?&gt;</code> (via the stats embed).</p>
        </div>
      </div>

      <div class="field-row">
        <span class="field-label">Image</span>
        <label class="file-drop-zone">
          <input id="thumbnail" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
          <span class="file-drop-icon"><?= View::icon('image') ?></span>
              <strong>Drag &amp; drop or click to browse</strong>
              <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
        </label>
      </div>

      <div class="field-row">
        <span class="field-label">Status</span>
        <div>
          <div class="status-pill-row">
            <label class="status-pill status-pill--current">
              <input type="radio" name="status" value="current" <?= $selStatus === 'current' ? 'checked' : '' ?>>
              Current
            </label>
            <label class="status-pill status-pill--upcoming">
              <input type="radio" name="status" value="upcoming" <?= $selStatus === 'upcoming' ? 'checked' : '' ?>>
              Upcoming
            </label>
            <label class="status-pill status-pill--pending">
              <input type="radio" name="status" value="pending" <?= $selStatus === 'pending' ? 'checked' : '' ?>>
              Pending
            </label>
            <label class="status-pill status-pill--archived">
              <input type="radio" name="status" value="closed" <?= $selStatus === 'closed' ? 'checked' : '' ?>>
              Closed
            </label>
            <label class="status-pill status-pill--archived">
              <input type="radio" name="status" value="adopted_out" <?= $selStatus === 'adopted_out' ? 'checked' : '' ?>>
              Adopted Out
            </label>
          </div>
          <p class="form-hint">Closed and Adopted Out archive the listing: it stops accepting joins/updates/contact/codes on its public forms, and its URL is no longer required.</p>
        </div>
      </div>

      <div class="field-row field-row--top" id="archive-note-row">
        <label class="field-label" for="fl-archive-note">Archive Note</label>
        <div>
          <textarea id="fl-archive-note" name="archive_note" class="form" rows="2"
                    placeholder="Optional internal note about why this listing was archived&hellip;"><?= View::e($oldArchiveNote) ?></textarea>
        </div>
      </div>

      <div class="field-row" id="adopted-to-name-row">
        <label class="field-label" for="fl-adopted-to-name">Adopted By (Name)</label>
        <div>
          <input id="fl-adopted-to-name" type="text" name="adopted_to_name" class="form"
                 value="<?= View::e($oldAdoptedToName) ?>" maxlength="255"
                 placeholder="Name of the new owner or successor listing">
        </div>
      </div>

      <div class="field-row" id="adopted-to-url-row">
        <label class="field-label" for="fl-adopted-to-url">Adopted By (URL)</label>
        <div>
          <input id="fl-adopted-to-url" type="url" name="adopted_to_url" class="form"
                 value="<?= View::e($oldAdoptedToUrl) ?>" maxlength="500"
                 placeholder="https://">
        </div>
      </div>

      <div class="field-row" id="opened-row">
        <label class="field-label" for="fl-opened">Opened On</label>
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
