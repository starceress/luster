<?php
/**
 * KIM List update dashboard — step 2.
 *
 * Variables:
 *   $auth_email     string  signed-in email
 *   $entries        array   [{id, fanlisting_id, listing_name, name, url, email, pending}]
 *   $save_action    string  POST URL for the main save form
 *   $sign_out_url   string
 *   $csrf_token     string
 *   $errors         string  pre-escaped HTML, or ''
 *   $success_html   string  pre-rendered success HTML, or ''
 *   $f              array<string,string>  keys: new_name, new_email, new_url,
 *                                               new_password, new_password_confirm (and *_input variants)
 *   $save_form_marker string  embed POST marker — optional
 *   $link_reset     string|null
 *   $link_list      string|null
 *   $base_url       string
 */

declare(strict_types=1);

use Luster\Core\View;

$b          = rtrim(View::e($base_url), '/');
$_linkReset = (($link_reset ?? null) !== null && $link_reset !== '') ? $link_reset : null;
$_linkList  = (($link_list  ?? null) !== null && $link_list  !== '') ? $link_list  : null;

if (($success_html ?? '') !== '') {
    echo '<div class="fl-embed fl-embed--kim-dashboard"><div class="fl-kim">' . $success_html . '</div></div>';
    return;
}
?>
<div class="fl-embed fl-embed--kim-dashboard">
  <div class="fl-kim">

    <p class="fl-hint">
      Signed in as <strong><?= View::e($auth_email) ?></strong>.
      <a href="<?= View::e($sign_out_url) ?>">Not you?</a>
      <?php if ($_linkList !== null): ?>
      &middot; <a href="<?= View::e($_linkList) ?>">View the KIM list</a>
      <?php endif; ?>
    </p>

    <?php if (($errors ?? '') !== ''): ?>
      <div class="fl-errors"><?= $errors ?></div>
    <?php endif; ?>

    <h2 class="fl-kim-heading">Update Your Entries</h2>
    <p class="fl-kim-intro">
      Select the entries you want to update, fill in any fields you want to change,
      and click <strong>Save Changes</strong>. Leave fields blank to keep the current value.
      Check <strong>Remove?</strong> on any row to delete that entry instead.
    </p>

    <form class="fl-form fl-form-kim-save" method="POST" action="<?= View::e($save_action) ?>">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <?php if (!empty($save_form_marker ?? '')): ?><input type="hidden" name="_luster_embed_form" value="<?= View::e($save_form_marker) ?>"><?php endif; ?>

      <!-- Honeypot -->
      <div style="display:none" aria-hidden="true">
        <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
      </div>

      <div class="fl-kim-table-wrap">
        <table class="fl-kim-table">
          <thead>
            <tr>
              <th></th>
              <th>Fanlisting</th>
              <th>Name</th>
              <th>Email</th>
              <th>Website URL</th>
              <th>Remove?</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($entries as $_e): ?>
            <tr>
              <td><input type="checkbox" name="entry_ids[]" value="<?= View::e((string) $_e['id']) ?>" checked></td>
              <td><?= View::e($_e['listing_name']) ?></td>
              <td>
                <?= View::e($_e['name']) ?>
                <?php if ($_e['pending']): ?><span class="fl-kim-entry-pending">(pending)</span><?php endif; ?>
              </td>
              <td><?= View::e($_e['email']) ?></td>
              <td><?= $_e['url'] !== '' ? '<a href="' . View::e($_e['url']) . '">' . View::e($_e['url']) . '</a>' : '' ?></td>
              <td style="text-align:center"><input type="checkbox" name="remove_ids[]" value="<?= View::e((string) $_e['id']) ?>"></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <fieldset class="fl-fieldset fl-kim-changes">
        <legend class="fl-label">Changes to apply to selected entries</legend>
        <?= $f['new_name'] ?>
        <?= $f['new_email'] ?>
        <?= $f['new_url'] ?>
        <?= $f['new_password'] ?>
        <?= $f['new_password_confirm'] ?>
      </fieldset>

      <div class="fl-form-actions">
        <button class="fl-submit fl-btn" type="submit">Save Changes</button>
        <button class="fl-btn" type="reset">Clear</button>
      </div>
    </form>

    <?php if ($_linkReset !== null): ?>
    <p class="fl-kim-meta"><a href="<?= View::e($_linkReset) ?>">Forgot password?</a></p>
    <?php endif; ?>

  </div>
</div>
