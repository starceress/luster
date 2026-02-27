<?php
/** @var \Luster\Models\Fanlisting[] $fanlistings */
/** @var array<int,int> $member_counts */
/** @var array<int,int> $pending_counts */
/** @var string $base_url */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>
<style>
  .fl-grid { display:grid;gap:.75rem;grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); }
  .fl-card { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.1rem 1.25rem;text-decoration:none;color:#111827;display:block; }
  .fl-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.07); }
  .fl-card .title { font-weight:600;font-size:.95rem;margin-bottom:.25rem; }
  .fl-card .subject { font-size:.8rem;color:#6b7280;margin-bottom:.6rem; }
  .fl-card .counts { display:flex;gap:.75rem; }
  .fl-card .cnt { font-size:.8rem; }
  .cnt-approved { color:#15803d; }
  .cnt-pending  { color:#d97706; }
</style>

<?php if (empty($fanlistings)): ?>
  <p style="color:#6b7280">No fanlistings found.</p>
<?php else: ?>
<p style="font-size:.875rem;color:#6b7280;margin-bottom:1rem">Select a fanlisting to manage its members.</p>
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
