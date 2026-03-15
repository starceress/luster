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

<div class="page-header">
  <h1>
    Error Log
    <?php if ($total > 0): ?>
      <span style="font-size:.8rem;font-weight:400;color:var(--text-muted);margin-left:.4rem">(<?= (int) $total ?> entries)</span>
    <?php endif; ?>
  </h1>
  <?php if ($total > 0): ?>
    <form method="POST"
          action="<?= $b ?>/admin/errorlog/flush"
          onsubmit="return confirm('Clear all error logs? This cannot be undone.')">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn btn-danger">Flush Logs</button>
    </form>
  <?php endif; ?>
</div>

<div class="neb-card">
  <?php if (empty($rows)): ?>
    <p class="neb-empty">No errors logged.</p>
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
