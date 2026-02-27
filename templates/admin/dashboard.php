<?php
/** @var string   $admin_username */
/** @var string   $app_name */
/** @var int      $total_fanlistings */
/** @var int      $total_members */
/** @var int      $total_pending */
/** @var array[]  $recent_activity */
/** @var string   $base_url */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>
<style>
  .stat-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); margin-bottom: 2rem; }
  .stat-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1.25rem; }
  .stat-card .val { font-size: 2rem; font-weight: 700; color: #111827; line-height: 1; }
  .stat-card .lbl { font-size: 0.8125rem; color: #6b7280; margin-top: 0.25rem; }
  .stat-card.warn .val { color: #d97706; }
  .section-title { font-size: 1rem; font-weight: 600; color: #111827; margin-bottom: 0.75rem; }
  .activity-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
  .activity-table th { background: #f9fafb; text-align: left; padding: .6rem 1rem; font-size: .8rem; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
  .activity-table td { padding: .6rem 1rem; font-size: .8125rem; color: #374151; border-bottom: 1px solid #f3f4f6; }
  .activity-table tr:last-child td { border-bottom: none; }
  .badge { display: inline-block; padding: .15rem .5rem; border-radius: 999px; font-size: .7rem; font-weight: 600; }
  .badge-blue { background: #eff6ff; color: #1d4ed8; }
  .quick-links { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
  .ql { display: block; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem; text-decoration: none; color: #111827; }
  .ql:hover { box-shadow: 0 4px 12px rgba(0,0,0,.07); }
  .ql .icon { font-size: 1.5rem; margin-bottom: .4rem; }
  .ql .name { font-weight: 600; font-size: .875rem; }
  .ql .desc { font-size: .75rem; color: #6b7280; margin-top: .15rem; }
</style>

<p style="color:#6b7280;margin-bottom:1.5rem;">
  Welcome back, <strong><?= \Luster\Core\View::e($admin_username) ?></strong>.
</p>

<!-- Stats -->
<div class="stat-grid">
  <div class="stat-card">
    <div class="val"><?= (int) $total_fanlistings ?></div>
    <div class="lbl">Fanlistings</div>
  </div>
  <div class="stat-card">
    <div class="val"><?= (int) $total_members ?></div>
    <div class="lbl">Approved Members</div>
  </div>
  <div class="stat-card <?= (int) $total_pending > 0 ? 'warn' : '' ?>">
    <div class="val"><?= (int) $total_pending ?></div>
    <div class="lbl">Pending Approval</div>
  </div>
</div>

<!-- Quick links -->
<p class="section-title">Quick Links</p>
<div class="quick-links" style="margin-bottom:2rem;">
  <a href="<?= $b ?>/admin/fanlistings" class="ql">
    <div class="icon">&#9734;</div>
    <div class="name">Fanlistings</div>
    <div class="desc">View &amp; edit</div>
  </a>
  <a href="<?= $b ?>/admin/members" class="ql">
    <div class="icon">&#9829;</div>
    <div class="name">Members</div>
    <div class="desc">Approve &amp; manage</div>
  </a>
  <a href="<?= $b ?>/admin/affiliates" class="ql">
    <div class="icon">&#9830;</div>
    <div class="name">Affiliates</div>
    <div class="desc">Manage links</div>
  </a>
  <a href="<?= $b ?>/admin/settings" class="ql">
    <div class="icon">&#9881;</div>
    <div class="name">Settings</div>
    <div class="desc">Configure panel</div>
  </a>
</div>

<!-- Recent activity -->
<p class="section-title">Recent Activity</p>
<?php if (empty($recent_activity)): ?>
  <p style="color:#6b7280;font-size:.875rem;">No activity recorded yet.</p>
<?php else: ?>
<table class="activity-table">
  <thead>
    <tr>
      <th>Action</th>
      <th>Description</th>
      <th>User</th>
      <th>Time</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($recent_activity as $entry): ?>
    <tr>
      <td><span class="badge badge-blue"><?= \Luster\Core\View::e((string) $entry['action']) ?></span></td>
      <td><?= \Luster\Core\View::e((string) ($entry['description'] ?? '')) ?></td>
      <td><?= \Luster\Core\View::e((string) ($entry['username'] ?? '—')) ?></td>
      <td style="white-space:nowrap;color:#6b7280"><?= \Luster\Core\View::e((string) ($entry['created_at'] ?? '')) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<p style="margin-top:.5rem;font-size:.8125rem;">
  <a href="<?= $b ?>/admin/activity" style="color:#4f46e5">View full activity log &rarr;</a>
</p>
<?php endif; ?>
