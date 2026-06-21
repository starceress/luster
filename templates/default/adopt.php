<?php
/**
 * Adoption application form — content fragment.
 *
 * Variables injected by AdoptPublicController / embed/adopt.php:
 *   $listing_title  string
 *   $form_action   string
 *   $csrf_token    string
 *   $errors        string  pre-escaped HTML error block, or ''
 *   $f             array<string,string>  keys: 'name', 'email', 'url', 'message', 'captcha_answer'
 *   $form_html     string|null  when set, renders custom template and short-circuits default
 *   $success_html  string|null  when set, renders the success block and short-circuits the form
 */

declare(strict_types=1);

use Luster\Core\View;

if (isset($success_html) && $success_html !== '') {
    echo $success_html;
    return;
}
if (isset($form_html)) {
    ?>
<?php if ($errors !== ''): ?><div class="fl-errors"><?= $errors ?></div><?php endif; ?>
<?= $form_html ?>
    <?php
    return;
}
?>

<?php if ($errors !== ''): ?>
  <div class="fl-errors"><?= $errors ?></div>
<?php endif; ?>

<form class="fl-form fl-form-adopt" method="POST" action="<?= View::e($form_action) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  <?php if (!empty($form_marker ?? '')): ?><input type="hidden" name="_luster_embed_form" value="<?= View::e($form_marker) ?>"><?php endif; ?>

  <?= $f['name'] ?>
  <?= $f['email'] ?>
  <?= $f['url'] ?>
  <?= $f['message'] ?>

  <!-- Honeypot — hidden from humans, filled by bots -->
  <div style="display:none" aria-hidden="true">
    <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
  </div>

  <?= $f['captcha_answer'] ?>

  <div class="fl-form-actions">
    <button type="submit" class="fl-submit">Submit Application</button>
    <button type="reset" class="fl-btn-secondary">Clear</button>
  </div>
</form>
