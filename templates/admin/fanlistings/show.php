<?php
/** @var \Luster\Models\Fanlisting $fanlisting */
/** @var int $approved */
/** @var int $pending */
/** @var bool $auto_approve */
/** @var \Luster\Models\Affiliate[] $affiliates */
/** @var array       $cfg */
/** @var string|null $current_image */
/** @var string $base_url */
/** @var string $csrf_token */
/** @var string $success */
/** @var string|null $stats_template */
/** @var string|null $members_template */
/** @var string|null $affiliates_template */
/** @var \Luster\Models\Category[] $categories */
/** @var int|null $current_category_id */
$b = rtrim(\Luster\Core\View::e($base_url), '/');

$sizeMap   = ['small' => '50px', 'medium' => '100px', 'large' => '150px'];
$sizeKey   = (string) ($cfg['image_preview_size'] ?? 'medium');
$imgHeight = $sizeMap[$sizeKey] ?? '100px';
?>

<p style="margin-bottom:1rem">
  <a href="<?= $b ?>/admin/fanlistings" class="neb-link">&larr; All Fanlistings</a>
</p>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>

<!-- Stats row -->
<div class="stat-row">
  <div class="mini-stat">
    <div class="v"><?= (int) $approved ?></div>
    <div class="l">Approved</div>
  </div>
  <div class="mini-stat">
    <div class="v<?= (int) $pending > 0 ? ' warn' : '' ?>"><?= (int) $pending ?></div>
    <div class="l">Pending</div>
  </div>
</div>

<!-- Details card -->
<div class="neb-card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem">
    <h2 style="font-size:1.1rem;font-weight:700;color:var(--text);margin:0"><?= \Luster\Core\View::e($fanlisting->name) ?></h2>
    <div style="display:flex;gap:.5rem">
      <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/edit" class="btn btn-primary">Edit</a>
      <a href="<?= $b ?>/admin/members/<?= (int) $fanlisting->id ?>" class="btn btn-ghost">Members</a>
      <form method="post"
            action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/delete"
            style="display:inline"
            onsubmit="return confirm('Delete this fanlisting and all its members? This cannot be undone.')">
        <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
        <button type="submit" class="btn btn-danger">Delete</button>
      </form>
    </div>
  </div>

  <div class="detail-grid">
    <div class="detail-row">
      <label>Subject</label>
      <span><?= \Luster\Core\View::e($fanlisting->subject) ?></span>
    </div>
    <div class="detail-row">
      <label>Status</label>
      <span>
        <?php if ($fanlisting->upcoming): ?>Upcoming
        <?php elseif ($fanlisting->trouble): ?>Pending
        <?php else: ?>Current<?php endif; ?>
      </span>
    </div>
    <div class="detail-row">
      <label>URL</label>
      <span>
        <?php if ($fanlisting->url !== ''): ?>
          <a href="<?= \Luster\Core\View::e($fanlisting->url) ?>" target="_blank" rel="noopener noreferrer"
             class="neb-link"><?= \Luster\Core\View::e($fanlisting->url) ?></a>
        <?php else: ?><span class="text-dim">&mdash;</span><?php endif; ?>
      </span>
    </div>
    <div class="detail-row">
      <label>Email</label>
      <span><?= \Luster\Core\View::e($fanlisting->email !== '' ? $fanlisting->email : '—') ?></span>
    </div>
    <div class="detail-row">
      <label>Table</label>
      <span><code class="neb-code"><?= \Luster\Core\View::e($fanlisting->dbprefix) ?></code></span>
    </div>
    <div class="detail-row">
      <label>Auto-Approve</label>
      <span><?= $auto_approve ? 'Yes' : 'No' ?></span>
    </div>
  </div>

  <!-- Fanlisting image -->
  <?php if (isset($current_image) && $current_image !== null && $current_image !== ''): ?>
  <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border)">
    <img src="<?= $b ?>/uploads/fanlistings/<?= \Luster\Core\View::e($current_image) ?>"
         alt="<?= \Luster\Core\View::e($fanlisting->name) ?>"
         style="max-height:<?= \Luster\Core\View::e($imgHeight) ?>;width:auto;display:block;border-radius:4px">
  </div>
  <?php endif; ?>

  <!-- Category assignment -->
  <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border)">
    <form method="POST" action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/update"
          style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

      <label for="fl_category_id"
             style="font-size:.72rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;white-space:nowrap">
        Category
      </label>

      <div class="select-wrap">
        <select id="fl_category_id" name="category_id" class="form-select" style="min-width:200px">
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
      </div>

      <button type="submit" class="btn btn-primary" style="font-size:.8125rem;padding:.35rem .8rem">Save</button>
    </form>
  </div>
</div>

<?php if ((int) $pending > 0): ?>
<div class="alert-warning" style="display:flex;align-items:center;gap:.75rem">
  <strong>&#9888; <?= (int) $pending ?> member(s) awaiting approval.</strong>
  <a href="<?= $b ?>/admin/members/<?= (int) $fanlisting->id ?>?status=pending" class="neb-link" style="font-size:.875rem">Review now &rarr;</a>
</div>
<?php endif; ?>

<!-- Website Templates section -->
<div class="neb-card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
  <h2 style="font-size:1rem;font-weight:700;color:var(--text);margin:0 0 1rem">Website Templates</h2>

  <div style="margin-bottom:1.25rem">
    <label class="form-label">Stats URL</label>
    <input type="text" readonly class="form-input"
           value="<?= $b ?>/stats/<?= (int) $fanlisting->id ?>"
           style="font-family:'DM Mono',monospace;font-size:.8125rem;color:var(--text-muted);cursor:default">
  </div>

  <form method="POST" action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/templates">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <div style="margin-bottom:1.25rem">
      <label class="form-label" for="members_template">Members List</label>
      <p class="form-hint" style="font-family:'DM Mono',monospace;line-height:1.8;margin-bottom:.5rem">
        $$fan_name$$ &bull; $$fan_email$$ &bull; $$fan_url$$ &bull; $$fan_country$$ &bull; $$fan_{fieldname}$$
      </p>
      <textarea id="members_template" name="members_template" class="form-textarea" rows="8"
                style="font-family:'DM Mono',monospace;font-size:.8125rem"
                placeholder="Leave blank to use the built-in default"><?= \Luster\Core\View::e($members_template ?? '') ?></textarea>
    </div>

    <div style="margin-bottom:1.25rem">
      <label class="form-label" for="affiliates_template">Affiliates</label>
      <p class="form-hint" style="font-family:'DM Mono',monospace;line-height:1.8;margin-bottom:.5rem">
        $$aff_name$$ &bull; $$aff_url$$ &bull; $$aff_image$$ &bull; $$aff_image_url$$
      </p>
      <textarea id="affiliates_template" name="affiliates_template" class="form-textarea" rows="8"
                style="font-family:'DM Mono',monospace;font-size:.8125rem"
                placeholder="Leave blank to use the built-in default"><?= \Luster\Core\View::e($affiliates_template ?? '') ?></textarea>
    </div>

    <div style="margin-bottom:1.25rem">
      <label class="form-label" for="stats_template">Statistics</label>
      <p class="form-hint" style="font-family:'DM Mono',monospace;line-height:1.8;margin-bottom:.5rem">
        $$stat_opened$$ &bull; $$stat_updated$$ &bull; $$stat_approved$$ &bull; $$stat_pending$$ &bull; $$stat_newmembers$$
      </p>
      <textarea id="stats_template" name="stats_template" class="form-textarea" rows="8"
                style="font-family:'DM Mono',monospace;font-size:.8125rem"
                placeholder="Leave blank to use the built-in default"><?= \Luster\Core\View::e($stats_template ?? '') ?></textarea>
    </div>

    <button type="submit" class="btn btn-primary">Save Templates</button>
  </form>
</div>

<!-- Affiliates section -->
<div class="neb-card" style="overflow:hidden">
  <div class="neb-card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span>Affiliates</span>
    <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/affiliates/add"
       class="btn btn-primary btn-sm">+ Add Affiliate</a>
  </div>

  <?php if (empty($affiliates)): ?>
    <p class="text-muted" style="padding:1.25rem;font-size:.875rem">No affiliates for this fanlisting yet.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Status</th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($affiliates as $aff): ?>
        <tr>
          <td>
            <a href="<?= \Luster\Core\View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer"
               class="neb-link text-strong"><?= \Luster\Core\View::e($aff->name) ?></a>
          </td>
          <td>
            <?php if ($aff->active): ?>
              <span class="neb-badge badge-current">Active</span>
            <?php else: ?>
              <span class="neb-badge badge-inactive">Inactive</span>
            <?php endif; ?>
          </td>
          <td class="act-col">
            <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit?from_fanlisting=<?= (int) $fanlisting->id ?>"
               class="btn btn-secondary btn-sm">Edit</a>

            <form method="POST"
                  action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/toggle"
                  style="display:inline;margin-left:.25rem">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="from_fanlisting" value="<?= (int) $fanlisting->id ?>">
              <button type="submit" class="btn btn-ghost btn-sm">
                <?= $aff->active ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>

            <form method="POST"
                  action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/delete"
                  style="display:inline;margin-left:.25rem"
                  onsubmit="return confirm('Delete this affiliate?')">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="from_fanlisting" value="<?= (int) $fanlisting->id ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
