<?php declare(strict_types=1);
use Luster\Core\View;

$b  = rtrim(View::e($base_url ?? ''), '/');
$ep = rtrim(str_replace('\\', '/', (string) ($embed_path ?? '/path/to/luster/public/embed')), '/');

// Returns the HTML-escaped PHP snippet for a given embed file.
// Pass $withId = true to prepend $luster_fanlisting_id = 0; for collective embeds.
$snip = static function(string $file, bool $withId = false) use ($ep): string {
    $lines = ['<?php'];
    if ($withId) $lines[] = '$luster_fanlisting_id = 0;';
    $lines[] = "require '" . $ep . '/' . $file . "';";
    return htmlspecialchars(implode("\n", $lines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/collective" class="link">&larr; Back to Collective</a>
  <a href="<?= $b ?>/admin/embed-codes" class="btn btn-quaternary btn-sm"><?= View::icon('embed', ['width' => 14, 'height' => 14]) ?> Full Embed Wizard</a>
</div>

<p class="es-intro">Drop any snippet into a PHP page on your site. The path is already filled in based on where Luster is installed. The <a href="<?= $b ?>/admin/embed-codes" class="link">Full Embed Wizard</a> has per-listing snippets and all customisation options.</p>


<!-- ── Listings & Data ──────────────────────────────────────────────────── -->
<section class="es-section">
  <div class="gradient-card">
    <div class="card-head">Listings &amp; Data</div>
    <ul class="es-list" role="list">

      <?php $_s = $snip('owned.php'); ?>
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

      <?php $_s = $snip('random-listing.php'); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('wand', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Random Listing</span>
            <span class="es-item-desc">Shows one current owned listing at random. Template editable under Collective &rarr; Templates.</span>
          </div>
          <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip('latest-listing.php'); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('updates', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Latest Listing</span>
            <span class="es-item-desc">Shows the newest current owned listing. Template editable under Collective &rarr; Templates.</span>
          </div>
          <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip('joined.php'); ?>
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

      <?php $_s = $snip('random-joined-listing.php'); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('wand', ['width' => 15, 'height' => 15]) ?></span>
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

      <?php $_s = $snip('latest-joined-listing.php'); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('updates', ['width' => 15, 'height' => 15]) ?></span>
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

      <?php $_s = $snip('collective-stats.php'); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('sparkles', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Site Statistics</span>
            <span class="es-item-desc">Shows collective-wide stats.</span>
          </div>
          <span class="badge badge-info-gold">Stats</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip('affiliates.php', true); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('link', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Collective Affiliates</span>
            <span class="es-item-desc">Shows only collective affiliates.</span>
          </div>
          <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip('codes.php', true); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('embed', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Collective Codes</span>
            <span class="es-item-desc">Shows collective-wide fan codes.</span>
          </div>
          <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

    </ul>
  </div>
</section>


<!-- ── Forms ────────────────────────────────────────────────────────────── -->
<section class="es-section">
  <div class="gradient-card">
    <div class="card-head">Forms</div>
    <ul class="es-list" role="list">

      <?php $_s = $snip('affiliate-apply.php', true); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('link', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Affiliate Form</span>
            <span class="es-item-desc">Lets visitors apply as collective affiliates.</span>
          </div>
          <span class="badge badge-info-pink">Form</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip('collective-codes-submit.php'); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('image', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Code Donation</span>
            <span class="es-item-desc">Lets visitors submit fan codes for review.</span>
          </div>
          <span class="badge badge-info-pink">Form</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip('collective-contact.php'); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('email', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Contact Form</span>
            <span class="es-item-desc">Shows the collective contact form.</span>
          </div>
          <span class="badge badge-info-pink">Form</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip('adopt.php'); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('heart', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Adoption Form</span>
            <span class="es-item-desc">Lets visitors apply to adopt a listing. Requires the Adoption add-on.</span>
          </div>
          <span class="badge badge-info-pink">Form</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

    </ul>
  </div>
</section>


<!-- ── Add-ons ──────────────────────────────────────────────────────────── -->
<section class="es-section">
  <div class="gradient-card">
    <div class="card-head">Add-ons</div>
    <ul class="es-list" role="list">

      <?php $_s = $snip('collective-updates.php'); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('updates', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">News Feed</span>
            <span class="es-item-desc">Site-wide updates feed. Requires the News Feed add-on.</span>
          </div>
          <span class="badge badge-upcoming">Add-on</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip('wishlist.php'); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('wishlist', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Wishlist</span>
            <span class="es-item-desc">Shows wishlist entries. Requires the Wishlist add-on.</span>
          </div>
          <span class="badge badge-upcoming">Add-on</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip('kim-signup.php'); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('kim', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">KIM Signup</span>
            <span class="es-item-desc">KIM List signup form. Requires the Keep In Mind add-on.</span>
          </div>
          <span class="badge badge-upcoming">Add-on</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip('kim-list.php'); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('kim', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">KIM List</span>
            <span class="es-item-desc">Shows approved KIM entries.</span>
          </div>
          <span class="badge badge-upcoming">Add-on</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip('kim-update.php'); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('kim', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">KIM Update Form</span>
            <span class="es-item-desc">Lets KIM members update their details.</span>
          </div>
          <span class="badge badge-upcoming">Add-on</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip('kim-reset.php'); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('kim', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">KIM Password Reset</span>
            <span class="es-item-desc">Shows the KIM password reset form.</span>
          </div>
          <span class="badge badge-upcoming">Add-on</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

    </ul>
  </div>
</section>
