<?php
use Luster\Core\View;
/** @var \Luster\Models\Listing[] $listings */
/** @var array<int,int> $member_counts */
/** @var array<int,int> $pending_counts */
/** @var string $base_url */
$b = rtrim(View::e($base_url), '/');
?>

<?php if (empty($listings)): ?>
  <p class="text-muted">No listings found.</p>
<?php else: ?>
<p class="text-muted" style="font-size:.875rem;margin-bottom:1rem">Select a listing to manage its members.</p>
<div class="fl-grid">
  <?php foreach ($listings as $fl): ?>
  <a href="<?= $b ?>/admin/members/<?= (int) $fl->id ?>" class="fl-card">
    <div class="title"><?= View::e($fl->name) ?></div>
    <div class="subject"><?= View::e($fl->subject) ?></div>
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
