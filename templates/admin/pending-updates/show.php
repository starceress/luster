<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var object                    $pending    PendingUpdate object
 * @var \Luster\Models\Fanlisting $fanlisting
 * @var \Luster\Models\Member|null $member    Current member record (null if deleted)
 * @var string                    $success
 * @var string                    $error
 * @var string                    $base_url
 * @var string                    $csrf_token
 */

$b = rtrim(View::e($base_url ?? ''), '/');

// Build human-readable label for a column name
$fieldLabel = static function (string $key): string {
    $key = ltrim($key, '_');
    if (str_starts_with($key, 'additional_')) {
        $key = substr($key, 11);
    }
    return ucwords(str_replace('_', ' ', $key));
};

// Fetch current value from member object
$currentValue = static function (string $key, ?\Luster\Models\Member $member): string {
    if ($member === null) {
        return '(member deleted)';
    }
    return match ($key) {
        'name'    => $member->name,
        'url'     => $member->url,
        'country' => $member->country,
        'showurl' => $member->showurl ? 'Yes' : 'No',
        default   => (string) ($member->additionalFields[ltrim($key, 'additional_')] ?? ''),
    };
};
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<p style="margin:0 0 .75rem">
  <a href="<?= $b ?>/admin/pending-updates" class="neb-link">&larr; Back to Pending Updates</a>
</p>

<div class="neb-card" style="margin-bottom:1.25rem">
  <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:1rem">
    <div>
      <div style="font-weight:700;color:var(--text)"><?= View::e($fanlisting->name) ?></div>
      <div style="font-size:.8rem;color:var(--text-dim)">
        Member: <strong><?= View::e($pending->member_email) ?></strong>
        &mdash; submitted <?= View::e($pending->submitted_at) ?>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;gap:.5rem">
      <form method="POST"
            action="<?= $b ?>/admin/pending-updates/<?= (int) $pending->id ?>/approve"
            onsubmit="return confirm('Approve and apply this update?')">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <button type="submit" class="btn btn-sm"
                style="background:var(--success-bg);color:var(--success);border-color:var(--success)">
          Approve &amp; Apply
        </button>
      </form>
      <form method="POST"
            action="<?= $b ?>/admin/pending-updates/<?= (int) $pending->id ?>/reject"
            onsubmit="return confirm('Reject and discard this update?')">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <button type="submit" class="btn btn-danger btn-sm">Reject</button>
      </form>
    </div>
  </div>

  <?php if ($member === null): ?>
    <div style="padding:1.25rem;color:var(--warn,#f59e0b);font-size:.875rem">
      ⚠️ The member record for <strong><?= View::e($pending->member_email) ?></strong>
      no longer exists. Approving this update will fail — please reject it.
    </div>
  <?php endif; ?>

  <table class="data-table">
    <thead>
      <tr>
        <th>Field</th>
        <th>Current Value</th>
        <th>Proposed Value</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pending->changes as $key => $proposed): ?>
      <?php
        $current  = $currentValue($key, $member ?? null);
        $changed  = ($current !== (string) $proposed) && $member !== null;
      ?>
      <tr <?= $changed ? 'style="background:rgba(167,139,250,.06)"' : '' ?>>
        <td style="font-size:.85rem;font-weight:600;color:var(--text)">
          <?= View::e($fieldLabel($key)) ?>
        </td>
        <td style="font-size:.85rem;color:var(--text-muted)">
          <?= View::e($current) ?>
        </td>
        <td style="font-size:.85rem<?= $changed ? ';color:var(--accent);font-weight:600' : '' ?>">
          <?= View::e((string) $proposed) ?>
          <?php if ($changed): ?>
            <span style="font-size:.7rem;color:var(--text-dim);font-weight:400;margin-left:.35rem">changed</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
