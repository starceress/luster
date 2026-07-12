<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin collective embed snippets page. Shows ready-to-copy PHP embed code
 snippets for all collective-level features, including owned listings,
 joined listings, statistics, affiliates, codes, contact form, and add-on
 embeds.
*/

declare(strict_types=1);
use Luster\Core\View;

$b  = rtrim(View::e($base_url ?? ''), '/');
$ep = rtrim(str_replace('\\', '/', (string) ($embed_path ?? '/path/to/luster/public/embed')), '/');
$escapedEp  = str_replace(["\\", "'"], ["\\\\", "\\'"], $ep);
$setupLine1 = "<?php defined('LUSTER_EMBED') || define('LUSTER_EMBED', '" . $escapedEp . "'); ?>";
$setupLine2 = "<?php require_once dirname(LUSTER_EMBED) . '/../vendor/autoload.php'; ?>";
$setupLine3 = "<?php if (isset(\$_GET['luster_css'])) { Luster\\Core\\View::serveCss(); exit; } ?>";
$setupLine4 = '<link rel="stylesheet" href="?luster_css">';

// Same PHP syntax highlighter used by the Full Embed Wizard's setup snippet.
$hlPhp = static function (string $code): string {
    $e = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return (string) preg_replace_callback(
        '/(\/\/[^\n]*)|(&#039;(?:[^&]|&(?!#039;))*&#039;)|(&lt;\?php|\?&gt;)|(\b(?:require_once|require|defined|define|isset|exit|true|false|null|if)\b)|(\$\w+)|(\b\d+\b)/',
        static function (array $m): string {
            if ($m[1] !== '') return '<span class="ec-hl-c">' . $m[0] . '</span>';
            if ($m[2] !== '') return '<span class="ec-hl-s">' . $m[0] . '</span>';
            if ($m[3] !== '') return '<span class="ec-hl-k">' . $m[0] . '</span>';
            if ($m[4] !== '') return '<span class="ec-hl-k">' . $m[0] . '</span>';
            if ($m[5] !== '') return '<span class="ec-hl-v">' . $m[0] . '</span>';
            if ($m[6] !== '') return '<span class="ec-hl-n">' . $m[0] . '</span>';
            return $m[0];
        },
        $e,
    );
};

// Builds an HTML-escaped PHP snippet from an array of code lines. Every embed
// line refers to the LUSTER_EMBED constant defined by the one-time setup
// snippet above, rather than baking the install path into every snippet.
$snip = static function(array $lines): string {
    $lines[] = '?>';
    return htmlspecialchars(implode("\n", $lines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/collective" class="link">&larr; Back to Collective</a>
  <a href="<?= $b ?>/admin/embed-codes" class="btn btn-quaternary btn-sm"><?= View::icon('embed', ['width' => 14, 'height' => 14]) ?> Full Embed Wizard</a>
</div>

<p>Paste the setup snippet below once — ideally in your site's shared header file — then drop any of the ready-to-copy snippets underneath it into your pages. The <a href="<?= $b ?>/admin/embed-codes" class="link">Full Embed Wizard</a> has per-listing snippets and all customisation options.</p>

<!-- ── One-Time Setup ───────────────────────────────────────────────────── -->
<section class="es-section">
  <div class="card ec-wizard-card">
    <div class="ec-setup-top">
      <div class="ec-setup-head">
        <strong class="ec-step-title">Include This <span class="badge badge-error badge-inline">Required!</span></strong>
        <button type="button" class="btn btn-primary btn-sm ec-setup-cp-btn" id="ec-setup-cp"
                data-setup-text="<?= htmlspecialchars($setupLine1 . "\n" . $setupLine2 . "\n" . $setupLine3 . "\n\n" . $setupLine4, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Copy All</button>
      </div>

      <p style="margin:0.75em 0 0.25em; color: var(--text-muted);">Paste this in the very beginning of your header.php, <em>before</em> any HTML begins (above <code>&lt;!doctype&gt;</code> or <code>&lt;html&gt;</code>): <span class="ec-setup-help" data-desc="These three lines set up your page to work with Luster. The first line tells your page where Luster is installed. The second loads the tools Luster needs to run. The third lets your page serve Luster&rsquo;s stylesheet without showing visitors where Luster lives on your server.">?</span></p>
      <div class="ec-setup-snip">
        <div class="ec-setup-snip-line"><code><?= $hlPhp($setupLine1) ?></code></div>
        <div class="ec-setup-snip-line"><code><?= $hlPhp($setupLine2) ?></code></div>
        <div class="ec-setup-snip-line"><code><?= $hlPhp($setupLine3) ?></code></div>
      </div>

      <p style="margin:0.75em 0 0.25em; color: var(--text-muted);">Paste this inside <code>&lt;head&gt;</code>, <em>before</em> your own stylesheets are linked: <span class="ec-setup-help" data-desc="This loads Luster&rsquo;s default styles for embeds so they're styled automatically. It uses ?luster_css instead of a direct file path so visitors can&rsquo;t see where Luster is installed on your server. Placing it before your own stylesheets lets your CSS override Luster&rsquo;s default.">?</span></p>
      <div class="ec-setup-snip">
        <div class="ec-setup-snip-line"><code>&lt;<span class="ec-hl-k">link</span> <span class="ec-hl-v">rel</span>=<span class="ec-hl-s">&quot;stylesheet&quot;</span> <span class="ec-hl-v">href</span>=<span class="ec-hl-s">&quot;?luster_css&quot;</span>&gt;</code></div>
      </div>
    </div>
  </div>
</section>


<section class="es-section">
  <div class="card card--gradient">
    <div class="card-head">Collective Specific Embed Codes</div>
    <ul class="es-list" role="list">

      <?php $_s = $snip([
          '<?php',
          '// Requires Adoption add-on (Admin → Add-ons)',
          "require LUSTER_EMBED . '/adopt.php';",
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
          '// Enable collective affiliate applications in Admin → Settings',
          '$luster_fanlisting_id = 0; // collective affiliate application',
          "require LUSTER_EMBED . '/affiliate-apply.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('link', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Affiliate Application</span>
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
          '$luster_fanlisting_id = 0; // collective affiliates',
          "require LUSTER_EMBED . '/affiliates.php';",
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
          '$luster_fanlisting_id = 0; // collective codes',
          "require LUSTER_EMBED . '/codes.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('image', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Codes</span>
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
          "require LUSTER_EMBED . '/collective-codes-submit.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('image', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Code Donation Form</span>
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
          "require LUSTER_EMBED . '/collective-update.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('notes', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Collective Update Form</span>
            <span class="es-item-desc">Lets a visitor update their name, URL, and country across every fanlisting they belong to in one go via an emailed verification link.</span>
          </div>
          <span class="badge badge-info2">Form</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          "require LUSTER_EMBED . '/collective-contact.php';",
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
          "require LUSTER_EMBED . '/joined.php';",
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
          '// Requires KIM List add-on (Admin → Add-ons)',
          "require LUSTER_EMBED . '/kim-list.php';",
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
          "require LUSTER_EMBED . '/kim-reset.php';",
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
          '// Requires KIM List add-on (Admin → Add-ons)',
          "require LUSTER_EMBED . '/kim-signup.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('kim', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">KIM Signup Form</span>
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
          "require LUSTER_EMBED . '/kim-update.php';",
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
          "// Renders the joined listing with the most recent join date.",
          "require LUSTER_EMBED . '/latest-joined-listing.php';",
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
          "// Renders the listing with the most recent open date.",
          "require LUSTER_EMBED . '/latest-listing.php';",
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
          '// Requires News Feed add-on (Admin → Add-ons)',
          "require LUSTER_EMBED . '/collective-newsfeed.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('updates', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">News Feed</span>
            <span class="es-item-desc">Site-wide news feed. Requires the News Feed add-on.</span>
          </div>
          <span class="badge badge-upcoming">Add-On</span> <span class="badge badge-info">Data</span> ✦
          <button type="button" class="btn btn-quaternary btn-xs es-toggle" aria-expanded="false">View Code</button>
          <button type="button" class="btn btn-primary btn-xs es-copy" data-snippet="<?= $_s ?>">Copy</button>
        </div>
        <div class="es-snippet" hidden><pre class="es-snippet-pre"><?= $_s ?></pre></div>
      </li>

      <?php $_s = $snip([
          '<?php',
          "require LUSTER_EMBED . '/owned.php';",
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
          "\$luster_status = 'past'; // combines Closed + Adopted Out",
          "require LUSTER_EMBED . '/owned.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('timer', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Past Fanlistings</span>
            <span class="es-item-desc">Shows closed and adopted-out listings together, in one merged list.</span>
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
          "require LUSTER_EMBED . '/random-joined-listing.php';",
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
          "// Renders one current owned listing at random.",
          "require LUSTER_EMBED . '/random-listing.php';",
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
          "require LUSTER_EMBED . '/collective-stats.php';",
      ]); ?>
      <li class="es-item">
        <div class="es-item-row">
          <span class="es-item-icon"><?= View::icon('sparkles', ['width' => 15, 'height' => 15]) ?></span>
          <div class="es-item-meta">
            <span class="es-item-label">Statistics</span>
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
          '// Requires Wishlist add-on (Admin → Add-ons)',
          "require LUSTER_EMBED . '/wishlist.php';",
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
    </ul>
  </div>
</section>
