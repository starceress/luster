<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Rules gate content fragment (Rules add-on). Displays the per-listing joining
 rules that a visitor must agree to before accessing the join form. Renders
 either a "you have already agreed" message or the rules body with an agreement
 form (checkbox + button or single button, depending on gate style). Served as
 a full page via View::renderPublic() or as an embeddable fragment via
 View::renderFragment() from embed/rules-gate.php.
*/

declare(strict_types=1);

use Luster\Core\View;
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
