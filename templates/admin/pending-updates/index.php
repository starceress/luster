<?php

declare(strict_types=1);

/**
 * @var object[] $pending   PendingUpdate objects (id, fanlisting_id, member_email, changes, submitted_at)
 * @var array    $fl_by_id  [fanlistingId => fanlistingName]
 * @var string   $success
 * @var string   $error
 * @var string   $base_url
 * @var string   $csrf_token
 */

$b = rtrim(\Luster\Core\View::e($base_url ?? ''), '/');

function puTimeAgo(string $dt): string
{
    $diff = max(0, time() - (int) strtotime($dt));
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<?php if (empty($pending)): ?>

<div class="neb-card" style="padding:2.5rem;text-align:center">
  <div style="font-size:2rem;margin-bottom:.75rem">✅</div>
  <div style="font-weight:600;color:var(--text);margin-bottom:.35rem">No pending updates</div>
  <div style="font-size:.85rem;color:var(--text-dim)">
    All member update requests have been reviewed. Enable
    <em>Queue member update requests</em> on a fanlisting&rsquo;s edit page to use this workflow.
  </div>
</div>

<?php else: ?>

<p style="font-size:.85rem;color:var(--text-dim);margin-bottom:1rem">
  <?= count($pending) ?> update<?= count($pending) !== 1 ? 's' : '' ?> awaiting review
</p>

<div class="neb-card">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Fanlisting</th>
        <th>Member Email</th>
        <th>Fields Changed</th>
        <th>Submitted</th>
        <th class="act-col">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pending as $pu): ?>
      <tr>
        <td class="text-dim"><?= (int) $pu->id ?></td>
        <td class="text-strong">
          <?= \Luster\Core\View::e(($fl_by_id[$pu->fanlisting_id] ?? 'Unknown (#' . $pu->fanlisting_id . ')')) ?>
        </td>
        <td><?= \Luster\Core\View::e($pu->member_email) ?></td>
        <td class="text-dim" style="font-size:.8rem">
          <?= count($pu->changes) ?> field<?= count($pu->changes) !== 1 ? 's' : '' ?>
        </td>
        <td class="text-dim" style="font-size:.8rem"
            title="<?= \Luster\Core\View::e($pu->submitted_at) ?>">
          <?= puTimeAgo($pu->submitted_at) ?>
        </td>
        <td class="act-col">
          <a href="<?= $b ?>/admin/pending-updates/<?= (int) $pu->id ?>"
             class="btn btn-primary btn-sm">Review</a>

          <form method="POST"
                action="<?= $b ?>/admin/pending-updates/<?= (int) $pu->id ?>/approve"
                style="display:inline"
                onsubmit="return confirm('Approve and apply this update?')">
            <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
            <button type="submit" class="btn btn-sm"
                    style="background:var(--success-bg);color:var(--success);border-color:var(--success)">
              Approve
            </button>
          </form>

          <form method="POST"
                action="<?= $b ?>/admin/pending-updates/<?= (int) $pu->id ?>/reject"
                style="display:inline"
                onsubmit="return confirm('Reject and discard this update?')">
            <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
            <button type="submit" class="btn btn-danger btn-sm">Reject</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>
