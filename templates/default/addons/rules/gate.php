<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Rules gate — content fragment.
 *
 * Used by both View::renderPublic() (standalone page) and
 * View::renderFragment() (embed/rules-gate.php).
 *
 * Variables injected by RulesPublicController / embed/rules-gate.php:
 *   $rules             \Luster\Addons\Rules\RuleSet
 *   $already_agreed    bool
 *   $form_action       string   POST URL for the agree form
 *   $return_to         string   hidden field value — redirect destination after agree
 *   $csrf_token        string
 *   $rules_intro_html  string   pre-rendered HTML intro (may be empty)
 */
?>
<div class="fl-embed fl-embed--rules-gate">

  <?php if ($already_agreed ?? false): ?>

    <div class="fl-rules-agreed">
      <p>You have already agreed to the <?= View::e($rules->title) ?>.</p>
    </div>

  <?php else: ?>

    <div class="fl-rules-gate">

      <?php if (($rules_intro_html ?? '') !== ''): ?>
      <div class="fl-rules-intro"><?= $rules_intro_html ?></div>
      <?php endif; ?>

      <h3 class="fl-rules-title"><?= View::e($rules->title) ?></h3>

      <div class="fl-rules-body">
        <?= nl2br(htmlspecialchars($rules->body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
      </div>

      <form class="fl-form fl-rules-form" method="POST" action="<?= View::e($form_action) ?>">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <input type="hidden" name="return_to" value="<?= View::e($return_to) ?>">

        <div class="fl-field fl-rules-agree-field">
          <label class="fl-rules-agree-label">
            <input type="checkbox" name="agree" value="1" required class="fl-rules-agree-check">
            I have read and agree to the rules above.
          </label>
        </div>

        <div class="fl-form-actions">
          <button type="submit" class="fl-submit">Agree and Continue</button>
        </div>
      </form>

    </div>

  <?php endif; ?>

</div>
