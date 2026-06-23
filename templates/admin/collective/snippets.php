<?php declare(strict_types=1);
use Luster\Core\View;

$b  = rtrim(View::e($base_url ?? ''), '/');
$ep = rtrim(str_replace('\\', '/', (string) ($embed_path ?? '/path/to/luster/public/embed')), '/');
$escapedEp = str_replace(["\\", "'"], ["\\\\", "\\'"], $ep);

// Builds an HTML-escaped PHP snippet from an array of code lines.
$snip = static function(array $lines) use ($escapedEp): string {
    $lines[] = '?>';
    $code = implode("\n", $lines);
    $code = str_replace('EMBED_PATH', $escapedEp, $code);
    return htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/collective" class="link">&larr; Back to Collective</a>
  <a href="<?= $b ?>/admin/embed-codes" class="btn btn-quaternary btn-sm"><?= View::icon('embed', ['width' => 14, 'height' => 14]) ?> Full Embed Wizard</a>
</div>

<p>Drop any snippet into a PHP page on your site. The path is already filled in based on where Luster is installed. The <a href="<?= $b ?>/admin/embed-codes" class="link">Full Embed Wizard</a> has per-listing snippets and all customisation options.</p>


<!-- ── Listings & Data ──────────────────────────────────────────────────── -->
<section class="es-section">
  <div class="gradient-card">
    <div class="card-head">Listings &amp; Data</div>
    <ul class="es-list" role="list">

      <?php $_s = $snip([
          '<?php',
          "require 'EMBED_PATH/owned.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('star', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Owned Listings</span>
            <span class="es-item-desc">Shows owned listings using the collective owned template.</span>
          </div>
          <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          "// Renders the listing with the most recent open date.",
          "require 'EMBED_PATH/latest-listing.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('star', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Latest Owned Listing</span>
            <span class="es-item-desc">Shows the newest current owned listing. Template editable under Collective &rarr; Templates.</span>
          </div>
          <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          "// Renders one current owned listing at random.",
          "require 'EMBED_PATH/random-listing.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('star', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Random Owned Listing</span>
            <span class="es-item-desc">Shows one current owned listing at random. Template editable under Collective &rarr; Templates.</span>
          </div>
          <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          "require 'EMBED_PATH/joined.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('heart', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Joined Listings</span>
            <span class="es-item-desc">Shows listings you have joined.</span>
          </div>
          <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          "// Renders the joined listing with the most recent join date.",
          "require 'EMBED_PATH/latest-joined-listing.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('heart', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Latest Joined Listing</span>
            <span class="es-item-desc">Shows the newest joined listing. Template editable under Collective &rarr; Templates.</span>
          </div>
          <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          "// Renders one active, approved joined listing at random.",
          "require 'EMBED_PATH/random-joined-listing.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('heart', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Random Joined Listing</span>
            <span class="es-item-desc">Shows one joined listing at random. Template editable under Collective &rarr; Templates.</span>
          </div>
          <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          "require 'EMBED_PATH/collective-stats.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('sparkles', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Collective Statistics</span>
            <span class="es-item-desc">Shows collective-wide stats.</span>
          </div>
          <span class="badge badge-info3">Stats</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          '$luster_fanlisting_id = 0; // collective affiliates',
          "require 'EMBED_PATH/affiliates.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('link', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Affiliates</span>
            <span class="es-item-desc">Shows only collective affiliates.</span>
          </div>
          <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          '// Enable collective affiliate applications in Admin → Settings',
          '$luster_fanlisting_id = 0; // collective affiliate application',
          "require 'EMBED_PATH/affiliate-apply.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('link', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Affiliate Application Form</span>
            <span class="es-item-desc">Lets visitors apply as collective affiliates.</span>
          </div>
          <span class="badge badge-info2">Form</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          '$luster_fanlisting_id = 0; // collective codes',
          "require 'EMBED_PATH/codes.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('image', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Codes Display</span>
            <span class="es-item-desc">Shows collective-wide fan codes.</span>
          </div>
          <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          '$luster_fanlisting_id = 0; // collective codes',
          "require 'EMBED_PATH/collective-codes-submit.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('image', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Codes Donation Form</span>
            <span class="es-item-desc">Lets visitors submit fan codes for review.</span>
          </div>
          <span class="badge badge-info2">Form</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          "require 'EMBED_PATH/collective-contact.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('email', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Contact Form</span>
            <span class="es-item-desc">Shows the collective contact form.</span>
          </div>
          <span class="badge badge-info2">Form</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          '// Requires Adoption add-on (Admin → Add-ons)',
          "require 'EMBED_PATH/adopt.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('adoptions', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Adoption Form</span>
            <span class="es-item-desc">Lets visitors apply to adopt a listing. Requires the Adoption add-on.</span>
          </div>
          <span class="badge badge-upcoming">Add-on</span> <span class="badge badge-info2">Form</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          '// Requires KIM List add-on (Admin → Add-ons)',
          "require 'EMBED_PATH/kim-list.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('kim', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">KIM List</span>
            <span class="es-item-desc">Shows approved KIM entries.</span>
          </div>
          <span class="badge badge-upcoming">Add-on</span> <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          '// Requires KIM List add-on (Admin → Add-ons)',
          "require 'EMBED_PATH/kim-signup.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('kim', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">KIM Signup</span>
            <span class="es-item-desc">KIM List signup form. Requires the Keep In Mind add-on.</span>
          </div>
          <span class="badge badge-upcoming">Add-on</span> <span class="badge badge-info2">Form</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          '// Requires KIM List add-on (Admin → Add-ons)',
          "require 'EMBED_PATH/kim-update.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('kim', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">KIM Update Form</span>
            <span class="es-item-desc">Lets KIM members update their details.</span>
          </div>
          <span class="badge badge-upcoming">Add-on</span> <span class="badge badge-info2">Form</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          '// Requires KIM List add-on (Admin → Add-ons)',
          "require 'EMBED_PATH/kim-reset.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('kim', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">KIM Password Reset</span>
            <span class="es-item-desc">Shows the KIM password reset form.</span>
          </div>
          <span class="badge badge-upcoming">Add-on</span> <span class="badge badge-info2">Form</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          '// Requires Wishlist add-on (Admin → Add-ons)',
          "require 'EMBED_PATH/wishlist.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('wishlist', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Wishlist</span>
            <span class="es-item-desc">Shows wishlist entries. Requires the Wishlist add-on.</span>
          </div>
          <span class="badge badge-upcoming">Add-on</span> <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          '// Requires News Feed add-on (Admin → Add-ons)',
          "require 'EMBED_PATH/collective-updates.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('updates', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">News Feed</span>
            <span class="es-item-desc">Site-wide updates feed. Requires the News Feed add-on.</span>
          </div>
          <span class="badge badge-upcoming">Add-On</span> <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>
    </ul>
  </div>
</section>
