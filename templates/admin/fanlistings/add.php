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
  <a href="<?= $b ?>/admin/fanlistings" class="neb-link">&larr; Back to Owned Listings</a>
</p>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="form-card">
  <form method="POST" action="<?= $b ?>/admin/fanlistings/add" enctype="multipart/form-data">
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
      <button type="button" class="btn btn-ghost" onclick="clearJoinedForm()">Clear Form</button>
      <a href="<?= $b ?>/admin/fanlistings" class="btn btn-ghost">Cancel</a>
    </div>

  </form>
</div>

<script>
(function () {
  'use strict';

  var widget   = document.getElementById('cat-widget');
  var rowsEl   = document.getElementById('cat-rows');
  var addLink  = document.getElementById('cat-add');

  var tflCats    = JSON.parse(widget.dataset.tfl);
  var taflCats   = JSON.parse(widget.dataset.tafl);
  var customNets = JSON.parse(widget.dataset.custom);
  var existing   = JSON.parse(widget.dataset.existing);

  // ------------------------------------------------------------------
  // HTML-escape helper for dynamically built option values
  // ------------------------------------------------------------------
  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ------------------------------------------------------------------
  // Build <option> HTML for the Network select
  // ------------------------------------------------------------------
  function buildNetOpts(selected) {
    var h = '<option value="">\u2014 select network \u2014</option>';
    var nets = ['TFL', 'TAFL'].concat(customNets).sort(function (a, b) {
      return a.toLowerCase().localeCompare(b.toLowerCase());
    });
    nets.forEach(function (name) {
      h += '<option value="' + esc(name) + '"' + (selected === name ? ' selected' : '') + '>' + esc(name) + '</option>';
    });
    h += '<option value="other"' + (selected === 'other' ? ' selected' : '') + '>Other (add new)</option>';
    return h;
  }

  // ------------------------------------------------------------------
  // Build <option> HTML for the Category select (TFL / TAFL)
  // ------------------------------------------------------------------
  function buildCatOpts(cats, selected) {
    var h = '<option value="">\u2014 select category \u2014</option>';
    cats.forEach(function (c) {
      h += '<option value="' + esc(c.name) + '"' + (selected === c.name ? ' selected' : '') + '>' + esc(c.name) + '</option>';
    });
    return h;
  }

  // ------------------------------------------------------------------
  // Show / hide / enable / disable the right inputs for a given row
  // ------------------------------------------------------------------
  function syncRow(row, netVal, catVal) {
    var catSel = row.querySelector('.cat-name-sel');
    var catTxt = row.querySelector('.cat-name-txt');
    var othTxt = row.querySelector('.cat-other-net');
    catVal = catVal || '';

    if (netVal === 'TFL' || netVal === 'TAFL') {
      catSel.innerHTML     = buildCatOpts(netVal === 'TFL' ? tflCats : taflCats, catVal);
      catSel.disabled      = false;
      catSel.style.display = '';
      catTxt.disabled      = true;
      catTxt.style.display = 'none';
      othTxt.style.display = 'none';

    } else if (netVal === 'other') {
      catSel.disabled      = true;
      catSel.style.display = 'none';
      catTxt.disabled      = false;
      catTxt.style.display = '';
      if (catVal) { catTxt.value = catVal; }
      othTxt.style.display = '';

    } else if (netVal !== '') {
      // Named custom network — free-text category
      catSel.disabled      = true;
      catSel.style.display = 'none';
      catTxt.disabled      = false;
      catTxt.style.display = '';
      if (catVal) { catTxt.value = catVal; }
      othTxt.style.display = 'none';

    } else {
      // Nothing selected yet
      catSel.disabled      = true;
      catSel.style.display = 'none';
      catTxt.disabled      = true;
      catTxt.style.display = 'none';
      othTxt.style.display = 'none';
    }
  }

  // ------------------------------------------------------------------
  // Build a single category row element
  // ------------------------------------------------------------------
  function makeRow(netVal, catVal, otherNetVal) {
    netVal      = netVal      || '';
    catVal      = catVal      || '';
    otherNetVal = otherNetVal || '';

    var row = document.createElement('div');
    row.className = 'cat-row';
    row.innerHTML =
      '<select name="cat_network[]" class="cat-net-sel">' + buildNetOpts(netVal) + '</select>' +
      '<select name="cat_name[]" class="cat-name-sel" disabled style="display:none"></select>' +
      '<input  type="text" name="cat_network_other[]" class="cat-other-net" value="' + esc(otherNetVal) + '" placeholder="New network name" style="display:none">' +
      '<input  type="text" name="cat_name[]"          class="cat-name-txt"  disabled style="display:none" placeholder="Category name">' +
      '<button type="button" class="cat-remove-btn" title="Remove">&times;</button>';

    row.querySelector('.cat-net-sel').addEventListener('change', function () {
      syncRow(row, this.value, '');
    });
    row.querySelector('.cat-remove-btn').addEventListener('click', function () {
      row.remove();
    });

    if (netVal) {
      syncRow(row, netVal, catVal);
    }

    return row;
  }

  // ------------------------------------------------------------------
  // Pre-populate rows from existing categories_json (edit mode)
  // Each entry: {network: string, name: string}
  // ------------------------------------------------------------------
  existing.forEach(function (entry) {
    rowsEl.appendChild(makeRow(entry.network || '', entry.name || '', ''));
  });

  // Always show at least one blank row
  if (rowsEl.children.length === 0) {
    rowsEl.appendChild(makeRow('', '', ''));
  }

  // ------------------------------------------------------------------
  // "Add another category" link
  // ------------------------------------------------------------------
  addLink.addEventListener('click', function (e) {
    e.preventDefault();
    rowsEl.appendChild(makeRow('', '', ''));
  });

  // Exposed so clearJoinedForm() can call it from outside the IIFE
  window.addCatRow = function () {
    rowsEl.appendChild(makeRow('', '', ''));
  };

}());

function clearJoinedForm() {
  document.querySelectorAll('.form-card input, .form-card textarea, .form-card select').forEach(function (el) {
    if (el.type === 'checkbox' || el.type === 'radio') { el.checked = false; }
    else { el.value = ''; }
  });
  var rows = document.getElementById('cat-rows');
  if (rows) {
    rows.innerHTML = '';
    addCatRow();
  }
  var statusSel = document.querySelector('select[name="status"]');
  if (statusSel) { statusSel.value = 'joined'; }
}
</script>
