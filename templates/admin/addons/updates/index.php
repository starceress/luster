<?php

declare(strict_types=1);

/**
 * Updates add-on — admin overview.
 *
 * @var \Luster\Models\Listing[]                                   $listings
 * @var array<int, array{count: int, latest: \Luster\Addons\Updates\Update}> $latest_per_fl
 * @var \Luster\Addons\Updates\Update|null                         $latest_coll
 * @var int                                                        $collective_cnt
 * @var string                                                     $success
 * @var string                                                     $error
 * @var string                                                     $base_url
 * @var string                                                     $csrf_token
 */

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');

/**
 * Truncates a string to at most $max characters, appending '…' if trimmed.
 */
$truncate = static function (string $text, int $max = 120): string {
    $text = strip_tags($text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $max)) . '…';
};
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<p style="margin-bottom:1.25rem">
  <a href="<?= $b ?>/admin/addons" class="neb-link">&larr; Back to Add-ons</a>
</p>

<div class="neb-card" style="margin-bottom:1.5rem">
  <table class="data-table">
    <thead>
      <tr>
        <th>Context</th>
        <th>Updates</th>
        <th>Latest</th>
        <th class="act-col">Actions</th>
      </tr>
    </thead>
    <tbody>

      <!-- Collective row -->
      <tr>
        <td class="text-strong">
          <span class="neb-badge badge-info" style="margin-right:.4rem">Collective</span>
          Site-wide
        </td>
        <td>
          <?php if ($collective_cnt > 0): ?>
            <span class="neb-badge badge-current"><?= (int) $collective_cnt ?></span>
          <?php else: ?>
            <span class="text-dim">0</span>
          <?php endif; ?>
        </td>
        <td class="text-muted" style="font-size:.85rem">
          <?php if ($latest_coll !== null): ?>
            <strong style="color:var(--text)"><?= View::e($latest_coll->title) ?></strong><br>
            <span style="font-size:.8rem"><?= View::e(substr($latest_coll->postedAt, 0, 10)) ?></span>
            <?php if ($truncate($latest_coll->body) !== ''): ?>
              <br><span style="color:var(--text-dim)"><?= View::e($truncate($latest_coll->body)) ?></span>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-dim">—</span>
          <?php endif; ?>
        </td>
        <td class="act-col">
          <a href="<?= $b ?>/admin/updates/collective"
             class="btn btn-secondary btn-sm">View All</a>
          <a href="<?= $b ?>/admin/updates/collective/add"
             class="btn btn-primary btn-sm">Add Update</a>
        </td>
      </tr>

      <!-- Per-fanlisting rows -->
      <?php foreach ($listings as $listing): ?>
        <?php
          $fid     = (int) $listing->id;
          $entry   = $latest_per_fl[$fid] ?? null;
          $cnt     = $entry['count']  ?? 0;
          $latest  = $entry['latest'] ?? null;
        ?>
        <tr>
          <td class="text-strong"><?= View::e($listing->name) ?></td>
          <td>
            <?php if ($cnt > 0): ?>
              <span class="neb-badge badge-current"><?= (int) $cnt ?></span>
            <?php else: ?>
              <span class="text-dim">0</span>
            <?php endif; ?>
          </td>
          <td class="text-muted" style="font-size:.85rem">
            <?php if ($latest !== null): ?>
              <strong style="color:var(--text)"><?= View::e($latest->title) ?></strong><br>
              <span style="font-size:.8rem"><?= View::e(substr($latest->postedAt, 0, 10)) ?></span>
              <?php if ($truncate($latest->body) !== ''): ?>
                <br><span style="color:var(--text-dim)"><?= View::e($truncate($latest->body)) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-dim">—</span>
            <?php endif; ?>
          </td>
          <td class="act-col">
            <a href="<?= $b ?>/admin/updates/<?= $fid ?>"
               class="btn btn-secondary btn-sm">View All</a>
            <a href="<?= $b ?>/admin/updates/<?= $fid ?>/add"
               class="btn btn-primary btn-sm">Add Update</a>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (empty($listings)): ?>
        <tr>
          <td colspan="4">
            <div class="neb-empty" style="padding:1.5rem 0">
              <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M19 20H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h10l6 6v8a2 2 0 0 1-2 2z"/><path d="M17 20v-8H7v8M7 4v4h8"/></svg>
              No fanlistings found. Add a fanlisting first to post per-listing updates.
            </div>
          </td>
        </tr>
      <?php endif; ?>

    </tbody>
  </table>
</div>
