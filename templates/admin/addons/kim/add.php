<?php declare(strict_types=1);
use Luster\Core\View;
$b = rtrim(View::e($base_url ?? ''), '/');
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/kim" class="link">&larr; Back to Keep In Mind</a>
</div>

<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/kim/add">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('kim') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Add KIM Entry</div>
        <div class="pc-head-sub">Manually add a Keep In Mind entry on behalf of someone.</div>
      </div>
    </div>

    <div class="pc-section">
      <div class="pc-section-label">Member Details</div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="kim-name">Name <span class="req">*</span></label>
        <input id="kim-name" type="text" name="name" class="form"
               value="<?= View::e(($old ?? [])['name'] ?? '') ?>"
               required maxlength="100" autofocus>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="kim-email">Email <span class="req">*</span></label>
        <input id="kim-email" type="email" name="email" class="form"
               value="<?= View::e(($old ?? [])['email'] ?? '') ?>"
               required maxlength="255">
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="kim-url">URL</label>
        <input id="kim-url" type="url" name="url" class="form"
               value="<?= View::e(($old ?? [])['url'] ?? '') ?>"
               maxlength="500" placeholder="https://">
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="kim-fanlisting">Fanlisting <span class="req">*</span></label>
        <div>
          <div class="select-wrap">
            <select id="kim-fanlisting" name="fanlisting_id" required>
              <option value="" disabled <?= (($old ?? [])['fanlisting_id'] ?? 0) == 0 ? 'selected' : '' ?>>
                &mdash; Select a Listing &mdash;
              </option>
              <?php foreach ($fanlistings ?? [] as $fl): ?>
                <option value="<?= (int) $fl->id ?>"
                        <?= (int) (($old ?? [])['fanlisting_id'] ?? 0) === $fl->id ? 'selected' : '' ?>>
                  <?= View::e($fl->subject) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="pc-section">
      <div class="pc-section-label">Status</div>

      <div class="listing-field-row">
        <label class="listing-field-label">Approval</label>
        <div>
          <label class="toggle-wrap">
            <input type="checkbox" name="approve_now" value="1">
            <span class="toggle-label">Approve immediately</span>
          </label>
          <p class="form-hint">Leave unchecked to add as pending, which requires manual approval.</p>
        </div>
      </div>
    </div>

    <div class="pc-section">
      <div class="pc-section-label">Password <span class="text-dim" style="font-weight:normal">(optional)</span></div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="kim-password">Password</label>
        <div>
          <div class="input-icon-wrap">
            <input id="kim-password" type="password" name="password" class="form"
                   maxlength="255" autocomplete="new-password" placeholder="Leave blank to set later">
            <button type="button" data-pw-toggle aria-label="Show password" tabindex="-1" class="input-icon-btn">
              <?= View::icon('eye-on') ?>
              <?= View::icon('eye-off') ?>
            </button>
          </div>
          <p class="form-hint">Minimum 12 characters. Leave blank &mdash; the member can use the KIM password reset form to set their own.</p>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Add KIM Entry</button>
      <a href="<?= $b ?>/admin/kim" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
