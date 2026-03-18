<?php
/** @var array<int,array<string,mixed>> $rows */
/** @var int    $total */
/** @var int    $page */
/** @var int    $pages */
/** @var int    $per_page */
/** @var string $success */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>

<?php if ($total > 0): ?>
<div class="neb-toolbar">
  <span class="text-muted" style="font-size:.8125rem"><?= (int) $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?> logged</span>
  <div class="neb-actions">
    <form method="POST"
          action="<?= $b ?>/admin/errorlog/flush"
          data-confirm="Clear all error logs? This cannot be undone.">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn btn-danger btn-sm">Flush Logs</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="neb-card">
  <?php if (empty($rows)): ?>
    <div class="neb-empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      All clear — no errors have been logged.
    </div>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:160px">Date</th>
          <th style="width:90px">Level</th>
          <th style="width:200px">Source</th>
          <th>Message</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
          <td class="text-muted" style="white-space:nowrap"><?= \Luster\Core\View::e((string) $row['created_at']) ?></td>
          <td>
            <?php
            $lvl = strtolower((string) ($row['level'] ?? 'error'));
            $badgeClass = match ($lvl) {
                'error'   => 'badge-error',
                'warning' => 'badge-upcoming',
                'notice'  => 'badge-inactive',
                default   => 'badge-inactive',
            };
            ?>
            <span class="neb-badge <?= $badgeClass ?>"><?= \Luster\Core\View::e($lvl) ?></span>
          </td>
          <td class="text-muted">
            <?= $row['source'] !== null && $row['source'] !== ''
                ? \Luster\Core\View::e((string) $row['source'])
                : '<span class="text-dim">&mdash;</span>' ?>
          </td>
          <td style="max-width:500px;word-break:break-word"><?= \Luster\Core\View::e((string) $row['message']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
<div class="neb-pagination">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <?php if ($i === $page): ?>
      <span class="pg-link active"><?= $i ?></span>
    <?php else: ?>
      <a href="<?= $b ?>/admin/errorlog?page=<?= $i ?>" class="pg-link"><?= $i ?></a>
    <?php endif; ?>
  <?php endfor; ?>
</div>
<?php endif; ?>
