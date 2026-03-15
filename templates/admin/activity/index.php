<?php
/** @var array[]  $entries */
/** @var int      $total */
/** @var int      $page */
/** @var int      $pages */
/** @var string   $filter_action */
/** @var string[] $actions */
/** @var string   $success */
/** @var string   $base_url */
/** @var string   $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>

<!-- Filter + action toolbar -->
<div class="neb-toolbar">
  <form method="GET" action="<?= $b ?>/admin/activity" class="neb-filter-row">
    <div class="toolbar-select-wrap">
      <select name="action" class="neb-select">
        <option value="">All actions</option>
        <?php foreach ($actions as $act): ?>
          <option value="<?= \Luster\Core\View::e($act) ?>"
                  <?= $filter_action === $act ? 'selected' : '' ?>>
            <?= \Luster\Core\View::e($act) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($filter_action !== ''): ?>
      <a href="<?= $b ?>/admin/activity" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
  </form>
  <?php if (($total ?? 0) > 0): ?>
  <div class="neb-actions">
    <form method="POST"
          action="<?= $b ?>/admin/activity/flush"
          data-confirm="Permanently delete all activity log entries from the database? This cannot be undone.">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn btn-danger btn-sm">Flush Log</button>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if (empty($entries)): ?>
  <p class="text-muted" style="font-size:.875rem">No log entries<?= $filter_action !== '' ? ' matching that filter' : '' ?>.</p>
<?php else: ?>

<p class="result-count">
  Showing <?= count($entries) ?> of <?= (int) $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?> &mdash; page <?= (int) $page ?> of <?= (int) $pages ?>
</p>

<div class="neb-card">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Action</th>
        <th>Description</th>
        <th>User</th>
        <th>IP</th>
        <th>Time</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($entries as $entry): ?>
      <tr>
        <td class="text-dim"><?= (int) $entry['id'] ?></td>
        <td><span class="neb-badge badge-info"><?= \Luster\Core\View::e((string) $entry['action']) ?></span></td>
        <td><?= \Luster\Core\View::e((string) ($entry['description'] ?? '')) ?></td>
        <td><?= \Luster\Core\View::e((string) ($entry['username'] ?? '—')) ?></td>
        <td style="font-family:'DM Mono',monospace;font-size:.75rem" class="text-muted">
          <?= \Luster\Core\View::e((string) ($entry['ip_address'] ?? '')) ?>
        </td>
        <td style="white-space:nowrap" class="text-muted">
          <?= \Luster\Core\View::e((string) ($entry['created_at'] ?? '')) ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div class="neb-pagination">
  <?php for ($p = 1; $p <= $pages; $p++): ?>
    <?php $qs = http_build_query(array_filter(['page' => $p, 'action' => $filter_action])); ?>
    <?php if ($p === (int) $page): ?>
      <span class="pg-link active"><?= $p ?></span>
    <?php else: ?>
      <a href="<?= $b ?>/admin/activity?<?= $qs ?>" class="pg-link"><?= $p ?></a>
    <?php endif; ?>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php endif; ?>
