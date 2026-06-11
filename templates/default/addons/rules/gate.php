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
 *   $rules_body_html   string   pre-rendered HTML rules body (RulesBodyRenderer)
 */
?>
<div class="fl-embed fl-embed--rules-gate">

  <?php if ($already_agreed ?? false): ?>

    <div class="fl-rules-agreed">
      <p>You have already agreed to the Join Rules.</p>
    </div>

  <?php else: ?>

    <div class="fl-rules-gate">

      <?php if (($rules_intro_html ?? '') !== ''): ?>
      <div class="fl-rules-intro"><?= $rules_intro_html ?></div>
      <?php endif; ?>

      <h3 class="fl-rules-title">Join Rules</h3>

      <div class="fl-rules-body">
        <?= $rules_body_html ?>
      </div>

      <form class="fl-form fl-rules-form" method="POST" action="<?= View::e($form_action) ?>">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <input type="hidden" name="return_to" value="<?= View::e($return_to) ?>">

        <?php if ($rules->gateType === 'button'): ?>
          <input type="hidden" name="agree" value="1">

          <div class="fl-form-actions">
            <button type="submit" class="fl-submit fl-rules-gate-btn"><?= View::e($rules->effectiveGateLabel()) ?></button>
          </div>
        <?php else: ?>
          <div class="fl-field fl-rules-agree-field">
            <label class="fl-rules-agree-label">
              <input type="checkbox" name="agree" value="1" required class="fl-rules-agree-check">
              <?= View::e($rules->effectiveGateLabel()) ?>
            </label>
          </div>

          <div class="fl-form-actions">
            <button type="submit" class="fl-submit fl-rules-gate-btn">Agree and Continue</button>
          </div>
        <?php endif; ?>
      </form>

    </div>

  <?php endif; ?>

</div>
