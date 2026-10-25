<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Shared "One-Time Setup" embed snippet block, included by the Full Embed Wizard,
 the Collective Embed Snippets page, and Edit Listing's Code Snippets tab. Renders
 the two copy-paste setup lines every embed depends on (the LUSTER_EMBED bootstrap
 line and the stylesheet <link>). Expects $setupLine1 and $setupLine5 to be set
 before inclusion. Optional: $setupAsWizardStep (bool, default false) swaps the
 title between a numbered wizard-step node (Full Embed Wizard, step 1 of 3) and a
 bare heading (the other two pages, which have no further steps); $setupShowHelp
 (bool, default true) toggles the "?" tooltip icons explaining what each line does.
*/

use Luster\Core\View;

$setupAsWizardStep ??= false;
$setupShowHelp     ??= true;
?>
<div class="ec-setup-top">
  <div class="ec-setup-head">
    <?php if ($setupAsWizardStep): ?>
      <div class="wizard-step-node wizard-step-node--active ec-setup-step" id="ec-step1-node">
        <div class="wizard-step-bubble">1</div>
        <span class="wizard-step-label"
              data-step-sub="Paste these snippets into your header files"><span class="ec-step-title">Include This <span class="badge badge-error badge-inline">Required!</span></span></span>
      </div>
    <?php else: ?>
      <strong class="ec-step-title">Include This <span class="badge badge-error badge-inline">Required!</span></strong>
    <?php endif; ?>
  </div>

  <div class="ec-setup-head">
    <p style="margin:0; color: var(--text-muted);">Paste this in the very beginning of your header.php, <em>before</em> any HTML begins (above <code>&lt;!doctype&gt;</code> or <code>&lt;html&gt;</code>):<?php if ($setupShowHelp): ?> <span class="ec-setup-help" data-desc="This one line sets up your page to work with Luster: it tells your page where Luster is installed, loads the tools Luster needs to run, and lets your page serve Luster's stylesheet and uploaded images without showing visitors where Luster lives on your server.">?</span><?php endif; ?></p>
    <button type="button" class="btn btn-primary btn-xs es-copy ec-setup-cp-btn" data-snippet="<?= View::e($setupLine1) ?>">Copy</button>
  </div>
  <div class="ec-setup-snip">
    <div class="ec-setup-snip-line"><code><?= View::e($setupLine1) ?></code></div>
  </div>

  <div class="ec-setup-head">
    <p style="margin:0; color: var(--text-muted);">Paste this inside <code>&lt;head&gt;</code>, <em>before</em> your own stylesheets are linked:<?php if ($setupShowHelp): ?> <span class="ec-setup-help" data-desc="This loads Luster's default styles for embeds so they're styled automatically. It uses ?luster_css instead of a direct file path so visitors can't see where Luster is installed on your server. Placing it before your own stylesheets lets your CSS override Luster's default.">?</span><?php endif; ?></p>
    <button type="button" class="btn btn-primary btn-xs es-copy ec-setup-cp-btn" data-snippet="<?= View::e($setupLine5) ?>">Copy</button>
  </div>
  <div class="ec-setup-snip">
    <div class="ec-setup-snip-line"><code><?= View::e($setupLine5) ?></code></div>
  </div>
</div>
