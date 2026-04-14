<?php
use Luster\Core\View;
/** @var \Luster\Models\Listing $listing */
/** @var int $approved */
/** @var int $pending */
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
$b = rtrim(View::e($base_url), '/');

$sizeMap   = ['small' => '50px', 'medium' => '100px', 'large' => '150px'];
$sizeKey   = (string) ($cfg['image_preview_size'] ?? 'medium');
$imgHeight = $sizeMap[$sizeKey] ?? '100px';
?>

<p style="margin-bottom:1rem">
  <a href="<?= $b ?>/admin/listings" class="link">&larr; All Listings</a>
</p>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
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
<div class="card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem">
    <h2 class="card-title"><?= View::e($listing->name) ?></h2>
    <div style="display:flex;gap:.5rem">
      <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit" class="btn btn-tertiary">Edit</a>
      <a href="<?= $b ?>/admin/members/<?= (int) $listing->id ?>" class="btn btn-ghost">Members</a>
      <form method="post"
            action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/delete"
            style="display:inline"
            data-del-modal data-del-entity="listing"
            data-del-name="<?= View::e($listing->name) ?>">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <button type="submit" class="btn btn-danger">Delete</button>
      </form>
    </div>
  </div>

  <div class="detail-grid">
    <div class="detail-row">
      <label>Subject</label>
      <span><?= View::e($listing->subject) ?></span>
    </div>
    <div class="detail-row">
      <label>Status</label>
      <span>
        <?php if ($listing->upcoming): ?>Upcoming
        <?php elseif ($listing->trouble): ?>Pending
        <?php else: ?>Current<?php endif; ?>
      </span>
    </div>
    <div class="detail-row">
      <label>URL</label>
      <span>
        <?php if ($listing->url !== ''): ?>
          <a href="<?= View::e($listing->url) ?>" target="_blank" rel="noopener noreferrer"
             class="link"><?= View::e($listing->url) ?></a>
        <?php else: ?><span class="text-dim">&mdash;</span><?php endif; ?>
      </span>
    </div>
    <div class="detail-row">
      <label>Email</label>
      <span><?= View::e($listing->email !== '' ? $listing->email : '—') ?></span>
    </div>
    <div class="detail-row">
      <label>Table</label>
      <span><code class="neb-code"><?= View::e($listing->dbprefix) ?></code></span>
    </div>
  </div>

  <!-- Listing image -->
  <?php if (isset($current_image) && $current_image !== null && $current_image !== ''): ?>
  <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border)">
    <img src="<?= $b ?>/uploads/listings/<?= View::e($current_image) ?>"
         alt="<?= View::e($listing->name) ?>"
         style="max-height:<?= View::e($imgHeight) ?>;width:auto;display:block;border-radius:4px">
  </div>
  <?php endif; ?>

  <!-- Category assignment -->
  <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border)">
    <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/update"
          style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

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
            <optgroup label="<?= View::e($parent->name) ?>">
              <option value="<?= (int) $parent->id ?>"<?= $current_category_id === $parent->id ? ' selected' : '' ?>>
                <?= View::e($parent->name) ?> (general)
              </option>
              <?php foreach ($catChildren[$parent->id] as $child): ?>
                <option value="<?= (int) $child->id ?>"<?= $current_category_id === $child->id ? ' selected' : '' ?>>
                  &nbsp;&nbsp;<?= View::e($child->name) ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php else: ?>
            <option value="<?= (int) $parent->id ?>"<?= $current_category_id === $parent->id ? ' selected' : '' ?>>
              <?= View::e($parent->name) ?>
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
  <a href="<?= $b ?>/admin/members/<?= (int) $listing->id ?>?status=pending" class="link" style="font-size:.875rem">Review now &rarr;</a>
</div>
<?php endif; ?>

<!-- Website Templates section -->
<div class="card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
  <h2 class="card-title" style="margin-bottom:1rem">Website Templates</h2>

  <div style="margin-bottom:1.25rem">
    <label class="form-label">Stats URL</label>
    <input type="text" readonly class="form-input"
           value="<?= $b ?>/stats/<?= (int) $listing->id ?>"
           style="font-family:'DM Mono',monospace;font-size:.8125rem;color:var(--text-muted);cursor:default">
  </div>

  <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/templates">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <div style="margin-bottom:1.25rem">
      <label class="form-label" for="members_template">Members List</label>
      <p class="form-hint" style="font-family:'DM Mono',monospace;line-height:1.8;margin-bottom:.5rem">
        {fan_name} &bull; {fan_email} &bull; {fan_url} &bull; {fan_country} &bull; {fan_{fieldname}}
      </p>
      <textarea id="members_template" name="members_template" class="form-textarea" rows="8"
                style="font-family:'DM Mono',monospace;font-size:.8125rem"
                placeholder="Leave blank to use the built-in default"><?= View::e($members_template ?? '') ?></textarea>
    </div>

    <div style="margin-bottom:1.25rem">
      <label class="form-label" for="affiliates_template">Affiliates</label>
      <p class="form-hint" style="font-family:'DM Mono',monospace;line-height:1.8;margin-bottom:.5rem">
        {aff_name} &bull; {aff_url} &bull; {aff_image} &bull; {aff_image_url}
      </p>
      <textarea id="affiliates_template" name="affiliates_template" class="form-textarea" rows="8"
                style="font-family:'DM Mono',monospace;font-size:.8125rem"
                placeholder="Leave blank to use the built-in default"><?= View::e($affiliates_template ?? '') ?></textarea>
    </div>

    <div style="margin-bottom:1.25rem">
      <label class="form-label" for="stats_template">Statistics</label>
      <p class="form-hint" style="font-family:'DM Mono',monospace;line-height:1.8;margin-bottom:.5rem">
        {stat_opened} &bull; {stat_updated} &bull; {stat_approved} &bull; {stat_pending} &bull; {stat_newmembers}
      </p>
      <textarea id="stats_template" name="stats_template" class="form-textarea" rows="8"
                style="font-family:'DM Mono',monospace;font-size:.8125rem"
                placeholder="Leave blank to use the built-in default"><?= View::e($stats_template ?? '') ?></textarea>
    </div>

    <button type="submit" class="btn btn-primary">Save Templates</button>
  </form>
</div>

<!-- Affiliates section -->
<div class="card" style="overflow:hidden">
  <div class="card-head" style="display:flex;justify-content:space-between;align-items:center">
    <span>Affiliates</span>
    <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/affiliates/add"
       class="btn btn-primary btn-sm">+ Add Affiliate</a>
  </div>

  <?php if (empty($affiliates)): ?>
    <div class="empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
      No affiliates for this listing yet.
      <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/affiliates/add" class="btn btn-primary btn-sm">+ Add Affiliate</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
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
            <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer"
               class="link text-strong"><?= View::e($aff->name) ?></a>
          </td>
          <td>
            <?php if ($aff->active): ?>
              <span class="badge badge-current">Active</span>
            <?php else: ?>
              <span class="badge badge-inactive">Inactive</span>
            <?php endif; ?>
          </td>
          <td class="act-col">
            <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit?from_listing=<?= (int) $listing->id ?>"
               class="btn btn-tertiary btn-sm">Edit</a>

            <form method="POST"
                  action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/toggle"
                  style="display:inline;margin-left:.25rem">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="from_listing" value="<?= (int) $listing->id ?>">
              <button type="submit" class="btn btn-ghost btn-sm">
                <?= $aff->active ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>

            <form method="POST"
                  action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/delete"
                  style="display:inline;margin-left:.25rem"
                  data-del-modal data-del-entity="affiliate"
                  data-del-name="<?= View::e($aff->name) ?>">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="from_listing" value="<?= (int) $listing->id ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
