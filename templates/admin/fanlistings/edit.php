<?php
/** @var \Luster\Models\Fanlisting    $fanlisting */
/** @var bool                         $auto_approve */
/** @var string                       $error */
/** @var string                       $success */
/** @var array                        $old */
/** @var array                        $cfg */
/** @var string|null                  $current_image */
/** @var int                          $approved */
/** @var int                          $pending */
/** @var \Luster\Models\Affiliate[]   $affiliates */
/** @var array                        $templates */
/** @var \Luster\Models\Category[]    $categories */
/** @var int|null                     $current_category_id */
/** @var string                       $base_url */
/** @var string                       $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');

$sizeMap   = ['small' => '50px', 'medium' => '100px', 'large' => '150px'];
$sizeKey   = (string) ($cfg['image_preview_size'] ?? 'medium');
$imgHeight = $sizeMap[$sizeKey] ?? '100px';

// Resolve display values: prefer $old (re-populates on validation fail) then model
$oldTitle  = $old['title']  ?? $fanlisting->name;
$oldUrl    = $old['url']    ?? $fanlisting->url;
$oldEmail  = $old['email']  ?? $fanlisting->email;

// Resolve current status string
if ($fanlisting->upcoming) {
    $currentStatus = 'upcoming';
} elseif ($fanlisting->trouble) {
    $currentStatus = 'trouble';
} else {
    $currentStatus = 'active';
}
$oldStatus = $old['status'] ?? $currentStatus;
?>
<style>
  /* Stats */
  .stat-row { display:flex;gap:1.25rem;margin-bottom:1.25rem; }
  .mini-stat { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1rem 1.25rem;min-width:120px; }
  .mini-stat .v { font-size:1.75rem;font-weight:700;color:#111827; }
  .mini-stat .l { font-size:.75rem;color:#6b7280;margin-top:.1rem; }
  /* Cards */
  .info-card { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.25rem;margin-bottom:1.25rem; }
  .form-card { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;margin-bottom:1.25rem; }
  /* Form elements */
  .form-group { margin-bottom:1.1rem; }
  .form-group label { display:block;font-size:.8125rem;font-weight:600;color:#374151;margin-bottom:.35rem; }
  .form-group input, .form-group select { width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;color:#111827; }
  .form-group input:focus, .form-group select:focus { outline:none;border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.15); }
  .form-hint { font-size:.75rem;color:#6b7280;margin-top:.2rem; }
  .form-check { display:flex;align-items:center;gap:.5rem; }
  .form-check input[type=checkbox] { width:auto; }
  .btn-row { display:flex;gap:.75rem;align-items:center;margin-top:1.5rem; }
  /* Buttons */
  .btn { display:inline-block;padding:.45rem 1rem;border-radius:6px;font-size:.875rem;font-weight:500;text-decoration:none;cursor:pointer;border:none; }
  .btn-primary { background:#4f46e5;color:#fff; }
  .btn-primary:hover { background:#4338ca; }
  .btn-secondary { background:#f3f4f6;color:#374151;border:1px solid #d1d5db; }
  .btn-secondary:hover { background:#e5e7eb; }
  .btn-danger { background:#dc2626;color:#fff; }
  .btn-danger:hover { background:#b91c1c; }
  .btn-cancel { color:#6b7280;text-decoration:none;font-size:.875rem; }
  /* Alerts */
  .alert-success { background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#15803d;font-size:.875rem; }
  .alert-error   { background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#b91c1c;font-size:.875rem; }
  /* Fieldsets */
  fieldset { border:1px solid #e5e7eb;border-radius:6px;padding:.75rem 1rem;margin-bottom:1.1rem; }
  legend { font-size:.8rem;font-weight:600;color:#6b7280;padding:0 .25rem; }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
  <a href="<?= $b ?>/admin/fanlistings" style="color:#6b7280;font-size:.875rem;text-decoration:none">&larr; All Fanlistings</a>
  <div style="display:flex;gap:.5rem;">
    <a href="<?= $b ?>/admin/members/<?= (int) $fanlisting->id ?>" class="btn btn-secondary">Members</a>
    <form method="post"
          action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/delete"
          style="display:inline"
          onsubmit="return confirm('Delete this fanlisting and all its members? This cannot be undone.')">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn btn-danger">Delete</button>
    </form>
  </div>
</div>

<?php if ($success !== ''): ?>
  <div class="alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<!-- 1. Stats row -->
<div class="stat-row">
  <div class="mini-stat">
    <div class="v"><?= (int) $approved ?></div>
    <div class="l">Approved</div>
  </div>
  <div class="mini-stat">
    <div class="v" style="<?= (int) $pending > 0 ? 'color:#d97706' : '' ?>"><?= (int) $pending ?></div>
    <div class="l">Pending</div>
  </div>
</div>

<!-- 2. Pending warning banner -->
<?php if ((int) $pending > 0): ?>
<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1.25rem;">
  <strong style="color:#92400e">&#9888; <?= (int) $pending ?> member(s) awaiting approval.</strong>
  <a href="<?= $b ?>/admin/members/<?= (int) $fanlisting->id ?>?status=pending" style="color:#4f46e5;margin-left:.5rem;font-size:.875rem">Review now &rarr;</a>
</div>
<?php endif; ?>

<!-- 3. Edit form -->
<div class="form-card">
  <form method="POST" action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/edit" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <div class="form-group">
      <label for="fl-title">Display Name</label>
      <input id="fl-title" type="text" name="title"
             value="<?= \Luster\Core\View::e((string) $oldTitle) ?>"
             maxlength="255" required>
    </div>

    <div class="form-group">
      <label for="fl-url">Website URL</label>
      <input id="fl-url" type="url" name="url"
             value="<?= \Luster\Core\View::e((string) $oldUrl) ?>"
             maxlength="500" placeholder="https://example.com" required>
    </div>

    <div class="form-group">
      <label for="fl-email">Contact Email</label>
      <input id="fl-email" type="email" name="email"
             value="<?= \Luster\Core\View::e((string) $oldEmail) ?>"
             maxlength="255" required>
    </div>

    <div class="form-group">
      <label for="fl-status">Status</label>
      <select id="fl-status" name="status">
        <option value="active"   <?= $oldStatus === 'active'   ? 'selected' : '' ?>>Active</option>
        <option value="upcoming" <?= $oldStatus === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
        <option value="trouble"  <?= $oldStatus === 'trouble'  ? 'selected' : '' ?>>Trouble</option>
      </select>
      <p class="form-hint">
        Active = open for signups. Upcoming = not yet open. Trouble = hidden/inactive.
      </p>
    </div>

    <fieldset>
      <legend>Luster Settings</legend>
      <div class="form-check">
        <input id="auto-approve" type="checkbox" name="auto_approve" value="1"
               <?= $auto_approve ? 'checked' : '' ?>>
        <label for="auto-approve" style="margin-bottom:0;font-weight:400">
          Auto-approve new join requests
        </label>
      </div>
      <p class="form-hint" style="margin-top:.35rem">
        When enabled, members are approved immediately on join (no admin review).
        Requires <code>migrate.php</code> to have been run.
      </p>
      <div class="form-group" style="margin-top:.85rem;margin-bottom:.85rem">
        <label for="fl-listing-type">Listing Type</label>
        <?php $lt = $listing_type ?? 'fanlisting'; ?>
        <select id="fl-listing-type" name="listing_type">
          <option value="fanlisting"  <?= $lt === 'fanlisting'  ? 'selected' : '' ?>>Fanlisting</option>
          <option value="clique"      <?= $lt === 'clique'      ? 'selected' : '' ?>>Clique</option>
          <option value="collective"  <?= $lt === 'collective'  ? 'selected' : '' ?>>Collective</option>
          <option value="shrine"      <?= $lt === 'shrine'      ? 'selected' : '' ?>>Shrine</option>
          <option value="webring"     <?= $lt === 'webring'     ? 'selected' : '' ?>>Webring</option>
          <option value="other"       <?= $lt === 'other'       ? 'selected' : '' ?>>Other</option>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label for="fl-members-per-page">Members Per Page</label>
        <input id="fl-members-per-page" type="number" name="members_per_page"
               min="5" max="200"
               value="<?= (int) ($members_per_page ?? 25) ?>"
               style="max-width:100px">
        <p class="form-hint" style="margin-top:.2rem">How many members to show per page in the admin panel (5–200).</p>
      </div>
    </fieldset>

    <fieldset>
      <legend>Fanlisting Image</legend>
      <?php if (isset($current_image) && $current_image !== null && $current_image !== ''): ?>
        <div style="margin-bottom:.6rem">
          <img src="/luster/public/uploads/fanlistings/<?= \Luster\Core\View::e($current_image) ?>"
               alt="Current fanlisting image"
               style="max-height:<?= \Luster\Core\View::e($imgHeight) ?>;width:auto;display:block;border-radius:4px">
        </div>
        <div class="form-check" style="margin-bottom:.6rem">
          <input type="checkbox" id="fl-delete-image" name="delete_image" value="1">
          <label for="fl-delete-image" style="margin-bottom:0;font-weight:400">Delete current image</label>
        </div>
        <label for="fl-image" style="display:block;font-size:.8125rem;font-weight:600;color:#374151;margin-bottom:.35rem">Replace with new image</label>
      <?php else: ?>
        <label for="fl-image" style="display:block;font-size:.8125rem;font-weight:600;color:#374151;margin-bottom:.35rem">Upload image</label>
      <?php endif; ?>
      <input type="file" id="fl-image" name="image" accept="image/*" style="font-size:.875rem">
      <p class="form-hint" style="margin-top:.35rem">JPEG, PNG, GIF, or WebP. Max 2 MB. Leave empty to keep the current image.</p>
    </fieldset>

    <div class="form-group">
      <label>Database Table</label>
      <div style="display:flex;align-items:center;gap:.75rem">
        <input type="text" value="<?= \Luster\Core\View::e($fanlisting->dbprefix) ?>"
               readonly style="background:#f9fafb;color:#6b7280;cursor:not-allowed;flex:1">
        <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/edit-prefix"
           class="btn btn-secondary" style="white-space:nowrap">Edit Prefix</a>
      </div>
      <p class="form-hint">The member table name in your database.</p>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="<?= $b ?>/admin/fanlistings" class="btn-cancel">Cancel</a>
    </div>
  </form>
</div>

<!-- 4. Category assignment -->
<div class="info-card">
  <form method="POST" action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/update"
        style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <label for="fl_category_id"
           style="font-size:.75rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap">
      Category
    </label>

    <select id="fl_category_id" name="category_id"
            style="border:1px solid #d1d5db;border-radius:6px;padding:.35rem .65rem;font-size:.875rem;color:#111827;background:#fff">
      <option value="0">— Uncategorised —</option>
      <?php
      $catParents  = [];
      $catChildren = [];
      foreach ($categories as $cat) {
          if ($cat->parentId === null) {
              $catParents[$cat->id] = $cat;
          } else {
              $catChildren[$cat->parentId][] = $cat;
          }
      }
      foreach ($catParents as $parent):
          $hasChildren = !empty($catChildren[$parent->id]);
          if ($hasChildren): ?>
        <optgroup label="<?= \Luster\Core\View::e($parent->name) ?>">
          <option value="<?= (int) $parent->id ?>"<?= $current_category_id === $parent->id ? ' selected' : '' ?>>
            <?= \Luster\Core\View::e($parent->name) ?> (general)
          </option>
          <?php foreach ($catChildren[$parent->id] as $child): ?>
            <option value="<?= (int) $child->id ?>"<?= $current_category_id === $child->id ? ' selected' : '' ?>>
              &nbsp;&nbsp;<?= \Luster\Core\View::e($child->name) ?>
            </option>
          <?php endforeach; ?>
        </optgroup>
      <?php else: ?>
        <option value="<?= (int) $parent->id ?>"<?= $current_category_id === $parent->id ? ' selected' : '' ?>>
          <?= \Luster\Core\View::e($parent->name) ?>
        </option>
      <?php endif;
      endforeach; ?>
    </select>

    <button type="submit" class="btn btn-primary" style="font-size:.8125rem;padding:.35rem .8rem">Save</button>
  </form>
</div>

<!-- 5. Website Templates -->
<div class="info-card">
  <h2 style="font-size:1rem;font-weight:700;color:#111827;margin-bottom:1rem">Website Templates</h2>

  <div style="margin-bottom:1.25rem">
    <label style="font-size:.75rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.35rem">Stats URL</label>
    <input type="text" readonly
           value="<?= $b ?>/stats/<?= (int) $fanlisting->id ?>"
           style="width:100%;font-family:monospace;font-size:.8125rem;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:.45rem .75rem;color:#374151;box-sizing:border-box">
  </div>

  <form method="POST" action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/templates">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <div style="margin-bottom:1.25rem">
      <label for="members_template"
             style="font-size:.875rem;font-weight:600;color:#111827;display:block;margin-bottom:.35rem">Members List</label>
      <p style="font-size:.8125rem;color:#6b7280;font-family:monospace;margin:0 0 .5rem;line-height:1.8">
        $$fan_name$$ &bull; $$fan_email$$ &bull; $$fan_url$$ &bull; $$fan_country$$
      </p>
      <textarea id="members_template" name="members_template" rows="8"
                style="width:100%;font-family:monospace;font-size:.8125rem;border:1px solid #d1d5db;border-radius:6px;padding:.5rem .75rem;color:#111827;box-sizing:border-box;resize:vertical"
                placeholder="Leave blank to use the built-in default"><?= \Luster\Core\View::e($templates['members_template'] ?? '') ?></textarea>
    </div>

    <div style="margin-bottom:1.25rem">
      <label for="affiliates_template"
             style="font-size:.875rem;font-weight:600;color:#111827;display:block;margin-bottom:.35rem">Affiliates</label>
      <p style="font-size:.8125rem;color:#6b7280;font-family:monospace;margin:0 0 .5rem;line-height:1.8">
        $$aff_url$$ &bull; $$aff_image$$ &bull; $$aff_name$$
      </p>
      <textarea id="affiliates_template" name="affiliates_template" rows="8"
                style="width:100%;font-family:monospace;font-size:.8125rem;border:1px solid #d1d5db;border-radius:6px;padding:.5rem .75rem;color:#111827;box-sizing:border-box;resize:vertical"
                placeholder="Leave blank to use the built-in default"><?= \Luster\Core\View::e($templates['affiliates_template'] ?? '') ?></textarea>
    </div>

    <div style="margin-bottom:1.25rem">
      <label for="stats_template"
             style="font-size:.875rem;font-weight:600;color:#111827;display:block;margin-bottom:.35rem">Statistics</label>
      <p style="font-size:.8125rem;color:#6b7280;font-family:monospace;margin:0 0 .5rem;line-height:1.8">
        $$stat_opened$$ &bull; $$stat_updated$$ &bull; $$stat_approved$$ &bull; $$stat_pending$$ &bull; $$stat_newmembers$$
      </p>
      <textarea id="stats_template" name="stats_template" rows="8"
                style="width:100%;font-family:monospace;font-size:.8125rem;border:1px solid #d1d5db;border-radius:6px;padding:.5rem .75rem;color:#111827;box-sizing:border-box;resize:vertical"
                placeholder="Leave blank to use the built-in default"><?= \Luster\Core\View::e($templates['stats_template'] ?? '') ?></textarea>
    </div>

    <button type="submit" class="btn btn-primary">Save Templates</button>
  </form>
</div>

<!-- 6. Affiliates -->
<div class="info-card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <h2 style="font-size:1rem;font-weight:700;color:#111827">Affiliates</h2>
    <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/affiliates/add"
       class="btn btn-primary" style="font-size:.8125rem;padding:.35rem .8rem">+ Add Affiliate</a>
  </div>

  <?php if (empty($affiliates)): ?>
    <p style="color:#6b7280;font-size:.875rem">No affiliates for this fanlisting yet.</p>
  <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:.875rem">
      <thead>
        <tr style="border-bottom:1px solid #e5e7eb">
          <th style="text-align:left;padding:.5rem .5rem;color:#6b7280;font-weight:600;font-size:.75rem">NAME</th>
          <th style="text-align:left;padding:.5rem .5rem;color:#6b7280;font-weight:600;font-size:.75rem">STATUS</th>
          <th style="text-align:right;padding:.5rem .5rem;color:#6b7280;font-weight:600;font-size:.75rem">ACTIONS</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($affiliates as $aff): ?>
        <tr style="border-bottom:1px solid #f3f4f6">
          <td style="padding:.5rem .5rem">
            <a href="<?= \Luster\Core\View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer"
               style="color:#4f46e5;font-weight:500"><?= \Luster\Core\View::e($aff->name) ?></a>
          </td>
          <td style="padding:.5rem .5rem">
            <?php if ($aff->active): ?>
              <span style="background:#f0fdf4;color:#15803d;padding:.15rem .55rem;border-radius:9999px;font-size:.7rem;font-weight:600">Active</span>
            <?php else: ?>
              <span style="background:#f9fafb;color:#6b7280;padding:.15rem .55rem;border-radius:9999px;font-size:.7rem;font-weight:600">Inactive</span>
            <?php endif; ?>
          </td>
          <td style="padding:.5rem .5rem;text-align:right">
            <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit?from_fanlisting=<?= (int) $fanlisting->id ?>"
               style="color:#4f46e5;font-size:.8125rem;margin-right:.5rem">Edit</a>

            <form method="POST"
                  action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/toggle"
                  style="display:inline">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="from_fanlisting" value="<?= (int) $fanlisting->id ?>">
              <button type="submit" style="background:none;border:none;color:#d97706;font-size:.8125rem;cursor:pointer;padding:0;margin-right:.5rem">
                <?= $aff->active ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>

            <form method="POST"
                  action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/delete"
                  style="display:inline"
                  onsubmit="return confirm('Delete this affiliate?')">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="from_fanlisting" value="<?= (int) $fanlisting->id ?>">
              <button type="submit" style="background:none;border:none;color:#dc2626;font-size:.8125rem;cursor:pointer;padding:0">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
