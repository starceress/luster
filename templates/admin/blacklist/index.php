<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var array[]  $entries    Blacklist rows: [{id, pattern, note, created_at}]
 * @var string   $success
 * @var string   $error
 * @var string   $base_url
 * @var string   $csrf_token
 */

$b = rtrim(View::e($base_url ?? ''), '/');
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= $success ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ── Add form ───────────────────────────────────────────────── -->
<div class="neb-card" style="margin-bottom:1.25rem">
  <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border);font-weight:700;color:var(--text)">
    Add Pattern
  </div>
  <form method="POST" action="<?= $b ?>/admin/blacklist/add" style="padding:1.25rem">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:.75rem;align-items:end">

      <div>
        <label class="form-label" for="bl-pattern">Pattern <span>*</span></label>
        <input id="bl-pattern" type="text" name="pattern" class="form-input"
               maxlength="255" required
               placeholder="spam@example.com  or  @spam.com">
        <p class="form-hint">
          Full email for an exact block, or start with <code class="neb-code">@</code> to block an entire domain
          (e.g. <code class="neb-code">@mailnull.com</code>).
        </p>
      </div>

      <div>
        <label class="form-label" for="bl-note">Note <em style="font-weight:400">(optional)</em></label>
        <input id="bl-note" type="text" name="note" class="form-input"
               maxlength="500"
               placeholder="Why this address was blocked">
      </div>

      <div>
        <button type="submit" class="btn btn-primary" style="width:100%">Add</button>
      </div>

    </div>
  </form>
</div>

<!-- ── Entries table ──────────────────────────────────────────── -->
<div class="neb-card">
  <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <span style="font-weight:700;color:var(--text)">Blocked Patterns</span>
    <span style="font-size:.8rem;color:var(--text-muted)"><?= count($entries) ?> entr<?= count($entries) === 1 ? 'y' : 'ies' ?></span>
  </div>

  <?php if (empty($entries)): ?>
    <div style="padding:2rem;text-align:center;color:var(--text-muted);font-size:.875rem">
      No blocked patterns yet. Add email addresses or domains above to prevent them from joining.
    </div>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Pattern</th>
          <th>Type</th>
          <th>Note</th>
          <th>Added</th>
          <th class="act-col"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($entries as $entry): ?>
        <tr>
          <td>
            <code class="neb-code" style="font-size:.85rem"><?= View::e($entry['pattern']) ?></code>
          </td>
          <td>
            <?php if (str_starts_with($entry['pattern'], '@')): ?>
              <span class="badge badge-info" style="font-size:.7rem">Domain</span>
            <?php else: ?>
              <span class="badge" style="font-size:.7rem;background:var(--bg-subtle);color:var(--text-muted);border-color:var(--border)">Exact</span>
            <?php endif; ?>
          </td>
          <td style="font-size:.85rem;color:var(--text-muted)">
            <?= $entry['note'] !== '' ? View::e($entry['note']) : '<span style="color:var(--text-dim)">—</span>' ?>
          </td>
          <td style="font-size:.8rem;color:var(--text-dim);white-space:nowrap">
            <?= View::e(substr($entry['created_at'], 0, 10)) ?>
          </td>
          <td class="act-col">
            <form method="POST"
                  action="<?= $b ?>/admin/blacklist/<?= (int) $entry['id'] ?>/delete"
                  onsubmit="return confirm('Remove <?= View::e($entry['pattern']) ?> from the blacklist?')">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
              <button type="submit" class="btn btn-danger btn-sm">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
