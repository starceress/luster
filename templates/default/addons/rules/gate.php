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
 *   $rules_body_html   string   pre-rendered HTML (template body with tokens substituted)
 */
?>
<?php if ($already_agreed ?? false): ?>

<div class="fl-rules-agreed">
  <p>You have already agreed to the Join Rules.</p>
</div>

<?php else: ?>

<div class="fl-rules-gate">
  <form class="fl-form fl-rules-form" method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <?php if (!empty($form_marker ?? '')): ?><input type="hidden" name="_luster_embed_form" value="<?= View::e($form_marker) ?>"><?php endif; ?>
    <input type="hidden" name="return_to" value="<?= View::e($return_to) ?>">
    <?= $rules_body_html ?>
  </form>
</div>

<?php endif; ?>
