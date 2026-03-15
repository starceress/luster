<?php
/** @var array  $tfl_cats        [{id: int, name: string}] */
/** @var array  $tafl_cats       [{id: int, name: string}] */
/** @var array  $custom_networks string[] */
/** @var array  $old */
/** @var string $error */
/** @var string $default_date */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');

$val = static function (string $k, string $def = '') use ($old): string {
    return isset($old[$k]) ? (string) $old[$k] : $def;
};

$selStatus = isset($old['status']) ? (int) $old['status'] : 2;

$dataTfl    = json_encode($tfl_cats      ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataTafl   = json_encode($tafl_cats     ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustom = json_encode($custom_networks ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
?>

<p style="margin-bottom:1rem">
  <a href="<?= $b ?>/admin/listings" class="neb-link">&larr; Back to Owned Listings</a>
</p>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="form-card">
  <form method="POST" action="<?= $b ?>/admin/listings/add" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <!-- ① Category -->
    <div class="form-group">
      <label class="form-label">Category</label>
      <div class="cat-widget" id="cat-widget"
           data-tfl='<?= $dataTfl ?>'
           data-tafl='<?= $dataTafl ?>'
           data-custom='<?= $dataCustom ?>'
           data-existing='[]'>
        <div id="cat-rows"></div>
        <a href="#" class="neb-link" id="cat-add" style="font-size:0.8125rem;display:inline-block;margin-top:0.4rem">+ Add another category</a>
      </div>
      <p class="form-hint">Choose the network(s) and category(ies) this listing will be assigned under.</p>
    </div>

    <div class="section-divider"></div>

    <!-- ② Subject -->
    <div class="form-group">
      <label class="form-label" for="fl-subject">Subject <span>*</span></label>
      <input id="fl-subject" type="text" name="subject" class="form-input" maxlength="255" required
             value="<?= \Luster\Core\View::e($val('subject')) ?>">
      <p class="form-hint">The subject of your listing (e.g. character name, show title).</p>
    </div>

    <!-- ③ Title -->
    <div class="form-group">
      <label class="form-label" for="fl-title">Title</label>
      <input id="fl-title" type="text" name="title" class="form-input" maxlength="255"
             value="<?= \Luster\Core\View::e($val('title')) ?>">
    </div>

    <!-- ④ URL -->
    <div class="form-group">
      <label class="form-label" for="fl-url">URL <span>*</span></label>
      <input id="fl-url" type="url" name="url" class="form-input" maxlength="500" placeholder="https://" required
             value="<?= \Luster\Core\View::e($val('url')) ?>">
    </div>

    <!-- ⑤ Email -->
    <div class="form-group">
      <label class="form-label" for="fl-email">Email</label>
      <input id="fl-email" type="email" name="email" class="form-input" maxlength="255"
             value="<?= \Luster\Core\View::e($val('email')) ?>">
      <p class="form-hint">The email address that listing emails will come from.</p>
    </div>

    <div class="section-divider"></div>

    <!-- ⑥ Status -->
    <div class="form-group">
      <label class="form-label">Status</label>
      <div style="display:flex;flex-direction:column;gap:0.35rem;margin-top:0.25rem">
        <label class="form-check">
          <input type="radio" name="status" value="2" <?= $selStatus === 2 ? 'checked' : '' ?>>
          <span class="form-check-label">Current</span>
        </label>
        <label class="form-check">
          <input type="radio" name="status" value="1" <?= $selStatus === 1 ? 'checked' : '' ?>>
          <span class="form-check-label">Upcoming</span>
        </label>
        <label class="form-check">
          <input type="radio" name="status" value="0" <?= $selStatus === 0 ? 'checked' : '' ?>>
          <span class="form-check-label">Pending</span>
        </label>
      </div>
    </div>

    <div class="section-divider"></div>

    <!-- ⑦ Image -->
    <div class="form-group">
      <label class="form-label" for="fl-image">Image</label>
      <input id="fl-image" type="file" name="image"
             accept="image/jpeg,image/png,image/gif,image/webp">
      <p class="form-hint">JPEG, PNG, GIF, or WebP &mdash; max&nbsp;2&nbsp;MB.</p>
    </div>

    <!-- ⑧ Opened On -->
    <div class="form-group">
      <label class="form-label" for="fl-opened">Opened On</label>
      <input id="fl-opened" type="date" name="opened" class="form-input" style="max-width:200px"
             value="<?= \Luster\Core\View::e($val('opened', $default_date)) ?>">
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Add Listing</button>
      <button type="button" class="btn btn-ghost" id="clear-form-btn">Clear Form</button>
      <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
    </div>

  </form>
</div>

