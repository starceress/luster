<?php
/** @var \Luster\Models\Fanlisting[] $fanlistings */
/** @var array<int,int> $member_counts */
/** @var array<int,int> $pending_counts */
/** @var string $base_url */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>

<?php if (empty($fanlistings)): ?>
  <p class="text-muted">No fanlistings found.</p>
<?php else: ?>
<p class="text-muted" style="font-size:.875rem;margin-bottom:1rem">Select a fanlisting to manage its members.</p>
<div class="fl-grid">
  <?php foreach ($fanlistings as $fl): ?>
  <a href="<?= $b ?>/admin/members/<?= (int) $fl->id ?>" class="fl-card">
    <div class="title"><?= \Luster\Core\View::e($fl->name) ?></div>
    <div class="subject"><?= \Luster\Core\View::e($fl->subject) ?></div>
    <div class="counts">
      <span class="cnt cnt-approved">&#10003; <?= (int) ($member_counts[$fl->id] ?? 0) ?> approved</span>
      <?php $pend = (int) ($pending_counts[$fl->id] ?? 0); ?>
      <?php if ($pend > 0): ?>
        <span class="cnt cnt-pending">&#9679; <?= $pend ?> pending</span>
      <?php endif; ?>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
