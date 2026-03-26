<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var string  $embed_path  Absolute server path to public/embed/
 * @var int     $example_id  A real listing ID to use in snippets
 * @var array   $listings    All Listing objects
 * @var string  $base_url
 * @var string  $csrf_token
 */

$b  = rtrim(View::e($base_url ?? ''), '/');
$ep = rtrim($embed_path ?? '/path/to/luster/public/embed', '/');
$id = max(1, (int) ($example_id ?? 1));

// ── Quick Copy snippet code strings (raw PHP, JSON-encoded for JS) ────────────

$snippets = [

  'join' => implode("\n", [
    '<?php',
    "\$luster_fanlisting_id = {$id};",
    "require('{$ep}/join.php');",
    '?>',
  ]),

  'members' => implode("\n", [
    '<?php',
    "\$luster_fanlisting_id = {$id};",
    "// \$luster_per_page    = 20;     // members per page (overrides site default)",
    "// \$luster_sort_by     = 'name'; // name | country",
    "// \$luster_members_base = strtok(\$_SERVER['REQUEST_URI'], '?');",
    "require('{$ep}/members.php');",
    '?>',
  ]),

  'stats' => implode("\n", [
    '<?php',
    "\$luster_fanlisting_id = {$id};",
    '// Template is set in Admin → Listings → Edit → Templates',
    '// Available tokens: {stat_opened}, {stat_approved}, {stat_updated}, ...',
    "require('{$ep}/stats.php');",
    '?>',
  ]),

  'affiliates' => implode("\n", [
    '<?php',
    "\$luster_fanlisting_id = {$id}; // use 0 for collective affiliates",
    "require('{$ep}/affiliates.php');",
    '?>',
  ]),

  'update' => implode("\n", [
    '<?php',
    "\$luster_fanlisting_id = {$id};",
    "require('{$ep}/update.php');",
    '?>',
  ]),

  'remove' => implode("\n", [
    '<?php',
    "\$luster_fanlisting_id = {$id};",
    "require('{$ep}/remove.php');",
    '?>',
  ]),

  'reset' => implode("\n", [
    '<?php',
    "\$luster_fanlisting_id = {$id};",
    "// \$luster_reset_step = 'confirm'; // show new-password form only",
    "require('{$ep}/reset.php');",
    '?>',
  ]),

  'owned' => implode("\n", [
    '<?php',
    "// \$luster_status        = 'current'; // current | upcoming | trouble | all",
    "// \$luster_hide_dropdown = false;     // true = hide category filter",
    "require('{$ep}/owned.php');",
    '?>',
  ]),

  'joined' => implode("\n", [
    '<?php',
    "// \$luster_category = ''; // '' = dropdown | 'all' = all | or exact name",
    "require('{$ep}/joined.php');",
    '?>',
  ]),

  'cstats' => implode("\n", [
    '<?php',
    "require('{$ep}/collective-stats.php');",
    '// Use the exposed variables anywhere on your page:',
    '// echo $luster_owned_active;   // e.g. 12',
    '// echo $luster_members_total;  // e.g. 3450',
    '?>',
  ]),

  'aff-col' => implode("\n", [
    '<?php',
    "\$luster_fanlisting_id = 0; // 0 = collective / site-wide affiliates",
    "require('{$ep}/affiliates.php');",
    '?>',
  ]),

  'kim' => implode("\n", [
    '<?php',
    '// Requires KIM List add-on — install from Admin → Add-ons',
    "require('{$ep}/kim-signup.php');",
    '?>',
  ]),

];

// ── Item metadata (label, badge, desc, file, custType) ────────────────────────

$items = [
  'join' => [
    'label'    => 'Join form',
    'badge'    => 'form',
    'file'     => 'embed/join.php',
    'custType' => 'join',
    'desc'     => 'Embeds the join form. Visitors submit their name, email, URL, and country. Extra fields from Edit Listing additional fields also appear here.',
  ],
  'members' => [
    'label'    => 'Members list',
    'badge'    => 'data',
    'file'     => 'embed/members.php',
    'custType' => 'members',
    'desc'     => 'Paginated list of approved members, rendered using the row template from Edit Listing templates.',
  ],
  'stats' => [
    'label'    => 'Statistics',
    'badge'    => 'stat',
    'file'     => 'embed/stats.php',
    'custType' => 'stats',
    'desc'     => 'Outputs your stats template with live data — member count, dates, newest member, and more. Edit the template in Edit Listing → Templates.',
  ],
  'affiliates' => [
    'label'    => 'Affiliates',
    'badge'    => 'data',
    'file'     => 'embed/affiliates.php',
    'custType' => 'affiliates',
    'desc'     => 'Displays this listing affiliates using the template from Edit Listing → Templates.',
  ],
  'update' => [
    'label'    => 'Update details',
    'badge'    => 'form',
    'file'     => 'embed/update.php',
    'custType' => 'update',
    'desc'     => 'Lets existing members update their name, URL, and other details.',
  ],
  'remove' => [
    'label'    => 'Remove membership',
    'badge'    => 'form',
    'file'     => 'embed/remove.php',
    'custType' => 'remove',
    'desc'     => 'Lets members remove themselves from the fanlisting.',
  ],
  'reset' => [
    'label'    => 'Password reset',
    'badge'    => 'form',
    'file'     => 'embed/reset.php',
    'custType' => 'reset',
    'desc'     => 'Two-step password reset — members enter their email, then receive a link to set a new password.',
  ],
  'owned' => [
    'label'    => 'Owned listings',
    'badge'    => 'data',
    'file'     => 'embed/owned.php',
    'custType' => 'owned',
    'desc'     => 'Displays all your owned fanlistings with an optional category filter dropdown.',
  ],
  'joined' => [
    'label'    => 'Joined listings',
    'badge'    => 'data',
    'file'     => 'embed/joined.php',
    'custType' => 'joined',
    'desc'     => 'Lists the fanlistings you have joined, grouped by category.',
  ],
  'cstats' => [
    'label'    => 'Site statistics',
    'badge'    => 'stat',
    'file'     => 'embed/collective-stats.php',
    'custType' => 'cstats',
    'desc'     => 'Exposes site-wide stats as PHP variables — owned count, total members, and more. No template needed: use the variables directly.',
  ],
  'aff-col' => [
    'label'    => 'Collective affiliates',
    'badge'    => 'data',
    'file'     => 'embed/affiliates.php',
    'custType' => 'aff-col',
    'desc'     => 'Displays your site-wide collective affiliates (not tied to any single listing).',
  ],
  'kim' => [
    'label'    => 'KIM sign-up',
    'badge'    => 'ext',
    'file'     => 'embed/kim-signup.php',
    'custType' => 'kim',
    'desc'     => 'Embeds the KIM List sign-up form. The KIM List add-on must be installed and active. If the add-on is not active, a short error appears instead.',
  ],
];

// Attach snippets to item metadata for a single data attribute
foreach ($items as $k => &$item) {
  $item['code'] = $snippets[$k] ?? '';
}
unset($item);

$itemsJson = json_encode(
  $items,
  JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG
);

$listingsJson = json_encode(
  array_map(fn($fl) => ['id' => (int) $fl->id, 'name' => $fl->name], $listings ?? []),
  JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG
);

$epSafe = View::e($ep);

// Nav item groups
$navGroups = [
  ['label' => 'For a fanlisting page', 'keys' => ['join', 'members', 'stats', 'affiliates', 'update', 'remove', 'reset']],
  ['label' => 'For a collective page',  'keys' => ['owned', 'joined', 'cstats', 'aff-col', 'kim']],
];

// Customizer type groups (mirror nav groups)
$custGroups = [
  ['label' => 'For a fanlisting page', 'keys' => ['join', 'members', 'stats', 'affiliates', 'update', 'remove', 'reset']],
  ['label' => 'For a collective page',  'keys' => ['owned', 'joined', 'cstats', 'aff-col', 'kim']],
];

?>

<div class="ec-root" id="ec-root"
     data-embed-path="<?= $epSafe ?>"
     data-listings='<?= $listingsJson ?>'
     data-items='<?= $itemsJson ?>'>

  <p class="ec-intro">
    Add Luster forms and data to any PHP page on your site with a single snippet.
    The embed path is already filled in based on where Luster is installed on this server.
  </p>

  <div class="ec-callout">
    <strong>Before you start:</strong> every snippet needs your
    <code>$luster_fanlisting_id</code> (the integer ID of the listing &mdash;
    find it in the URL when editing a listing in this admin panel).
    Collective embeds like owned listings and site statistics do not need a listing ID.
  </div>

  <!-- Tab bar (reuses Nebula .neb-tab-btn / .neb-tab-panel) -->
  <div class="neb-tabs-wrap" id="ec-tabs-wrap">
    <div class="neb-tabs" id="ec-tabs" role="tablist" aria-label="Embed codes sections">
      <button class="neb-tab-btn active" data-ec-tab="qc"   role="tab" aria-selected="true"  aria-controls="ec-panel-qc">Quick copy</button>
      <button class="neb-tab-btn"        data-ec-tab="cust" role="tab" aria-selected="false" aria-controls="ec-panel-cust">Customizer</button>
      <button class="neb-tab-btn"        data-ec-tab="ref"  role="tab" aria-selected="false" aria-controls="ec-panel-ref">Variable reference</button>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════════
       QUICK COPY
       ════════════════════════════════════════════════════════ -->
  <div class="neb-tab-panel active ec-tab-panel" id="ec-panel-qc" role="tabpanel">
    <div class="ec-qc-layout">

      <!-- Sidebar nav -->
      <nav class="ec-qc-nav" aria-label="Embed types">
        <?php foreach ($navGroups as $group): ?>
          <div class="ec-qc-group">
            <span class="ec-qc-label"><?= View::e($group['label']) ?></span>
            <?php foreach ($group['keys'] as $key): if (!isset($items[$key])) continue; $item = $items[$key]; ?>
              <button class="ec-qc-item" data-ec-item="<?= View::e($key) ?>">
                <span class="ec-qc-dot" aria-hidden="true"></span>
                <?= View::e($item['label']) ?>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </nav>

      <!-- Content pane (populated by JS) -->
      <div class="ec-qc-pane" id="ec-qc-pane">
        <div class="ec-qc-head">
          <span class="ec-qc-title" id="ec-qc-title">&nbsp;</span>
          <span class="ec-badge" id="ec-qc-badge"></span>
        </div>
        <div class="ec-qc-desc" id="ec-qc-desc"></div>
        <div class="ec-code-wrap">
          <pre class="ec-pre" id="ec-qc-code"></pre>
          <button class="ec-cp-btn" id="ec-qc-cp">Copy</button>
        </div>
        <div class="ec-qc-foot">
          <span class="ec-qc-file" id="ec-qc-file"></span>
          <button class="ec-cust-link" id="ec-qc-cust">Customize options &rarr;</button>
        </div>
      </div>

    </div>
  </div><!-- /#ec-panel-qc -->

  <!-- ════════════════════════════════════════════════════════
       CUSTOMIZER
       ════════════════════════════════════════════════════════ -->
  <div class="neb-tab-panel ec-tab-panel" id="ec-panel-cust" role="tabpanel">
    <div class="ec-cust-layout">

      <!-- Left column: type picker + options -->
      <div class="ec-cust-left">
        <?php foreach ($custGroups as $group): ?>
          <div class="ec-type-sec">
            <div class="ec-type-label"><?= View::e($group['label']) ?></div>
            <div class="ec-type-grid">
              <?php foreach ($group['keys'] as $key): if (!isset($items[$key])) continue; ?>
                <button class="ec-type-btn"
                        data-cust-type="<?= View::e($key) ?>">
                  <?= View::e($items[$key]['label']) ?>
                </button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <div class="ec-opts-card">
          <div class="ec-opts-head" id="ec-opts-head">Options</div>
          <div class="ec-opts-body" id="ec-opts-body"></div>
        </div>
      </div>

      <!-- Right column: generated code + preview -->
      <div class="ec-cust-right">
        <div class="ec-live-card">
          <div class="ec-live-head">
            <span class="ec-live-title">Generated code</span>
            <div class="ec-live-actions">
              <button class="ec-live-rst" id="ec-live-rst" title="Restore to generated code">Reset</button>
              <button class="ec-live-cp"  id="ec-live-cp">Copy</button>
            </div>
          </div>
          <textarea class="ec-live-ta" id="ec-live-ta"
                    spellcheck="false" autocomplete="off" autocorrect="off"
                    autocapitalize="off"></textarea>
        </div>
        <div class="ec-live-foot">
          <div class="ec-live-note" id="ec-live-note"></div>
          <button class="ec-preview-btn" id="ec-preview-btn">Preview with example data &rarr;</button>
        </div>
        <div class="ec-preview-card" id="ec-preview-card" hidden>
          <div class="ec-preview-head">
            <span>Preview &mdash; example data</span>
            <button class="ec-preview-close" id="ec-preview-close" aria-label="Close preview">&times;</button>
          </div>
          <div class="ec-preview-body" id="ec-preview-body"></div>
        </div>
      </div>

    </div>
  </div><!-- /#ec-panel-cust -->

  <!-- ════════════════════════════════════════════════════════
       VARIABLE REFERENCE
       ════════════════════════════════════════════════════════ -->
  <div class="neb-tab-panel ec-tab-panel" id="ec-panel-ref" role="tabpanel">

    <p class="ec-ref-intro">
      Variables are PHP variables you set <em>before</em> the <code>require</code> line.
      Tokens like <code>{stat_approved}</code> go inside your stats or members template string
      &mdash; Luster replaces them with live data when the page loads.
    </p>

    <details class="ec-acc" open>
      <summary>General &mdash; used by most embeds</summary>
      <div class="ec-acc-body">
        <table class="ec-vt">
          <thead>
            <tr><th>Variable</th><th>Description</th><th></th></tr>
          </thead>
          <tbody>
            <tr>
              <td><code>$luster_fanlisting_id</code></td>
              <td>Integer ID of the listing to embed. Find it in the URL when editing a listing (e.g. <samp>/admin/listings/3/edit</samp> &rarr; ID is <samp>3</samp>). Use <samp>0</samp> for collective-level affiliates.</td>
              <td><span class="ec-pill ec-pill--req">Most embeds</span></td>
            </tr>
            <tr>
              <td><code>$luster_return</code></td>
              <td>When <code>true</code>, the embed returns its HTML string instead of echoing it. Useful when you need to place the output in a variable.</td>
              <td><span class="ec-pill ec-pill--opt">Optional</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </details>

    <details class="ec-acc">
      <summary>Members list options</summary>
      <div class="ec-acc-body">
        <table class="ec-vt">
          <thead>
            <tr><th>Variable</th><th>Description</th><th></th></tr>
          </thead>
          <tbody>
            <tr>
              <td><code>$luster_per_page</code></td>
              <td>Members per page. Overrides the site-wide default from Settings.</td>
              <td><span class="ec-pill ec-pill--opt">Optional</span></td>
            </tr>
            <tr>
              <td><code>$luster_sort_by</code></td>
              <td>Force a default sort: <code>'name'</code> or <code>'country'</code>. When set, the visitor&rsquo;s sort link clicks are overridden.</td>
              <td><span class="ec-pill ec-pill--opt">Optional</span></td>
            </tr>
            <tr>
              <td><code>$luster_show_search</code></td>
              <td>Set to <code>false</code> to hide the sort and filter controls.</td>
              <td><span class="ec-pill ec-pill--opt">Optional</span></td>
            </tr>
            <tr>
              <td><code>$luster_members_base</code></td>
              <td>The URL of the page where you&rsquo;re embedding the list. Keeps pagination and sort links pointing back to your page instead of the Luster install URL.</td>
              <td><span class="ec-pill ec-pill--opt">Optional</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </details>

    <details class="ec-acc">
      <summary>Password reset options</summary>
      <div class="ec-acc-body">
        <table class="ec-vt">
          <thead>
            <tr><th>Variable</th><th>Description</th><th></th></tr>
          </thead>
          <tbody>
            <tr>
              <td><code>$luster_reset_step</code></td>
              <td>By default both steps (email entry and new-password form) appear on the same page. Set to <code>'confirm'</code> to show only step 2 (new-password form) &mdash; useful if you host both steps on separate pages.</td>
              <td><span class="ec-pill ec-pill--opt">Optional</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </details>

    <details class="ec-acc">
      <summary>Owned listings options</summary>
      <div class="ec-acc-body">
        <table class="ec-vt">
          <thead>
            <tr><th>Variable</th><th>Description</th><th></th></tr>
          </thead>
          <tbody>
            <tr>
              <td><code>$luster_status</code></td>
              <td>Filter by status: <code>'current'</code>, <code>'upcoming'</code>, <code>'trouble'</code>, or <code>'all'</code>. Defaults to current only.</td>
              <td><span class="ec-pill ec-pill--opt">Optional</span></td>
            </tr>
            <tr>
              <td><code>$luster_hide_dropdown</code></td>
              <td>Set to <code>true</code> to hide the category filter dropdown. Useful when you want to show listings for one category per page.</td>
              <td><span class="ec-pill ec-pill--opt">Optional</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </details>

    <details class="ec-acc">
      <summary>Joined listings options</summary>
      <div class="ec-acc-body">
        <table class="ec-vt">
          <thead>
            <tr><th>Variable</th><th>Description</th><th></th></tr>
          </thead>
          <tbody>
            <tr>
              <td><code>$luster_category</code></td>
              <td>Leave blank for a dropdown. Set to <code>'all'</code> to show all categories grouped. Or enter an exact category name to show only that category.</td>
              <td><span class="ec-pill ec-pill--opt">Optional</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </details>

    <details class="ec-acc">
      <summary>Per-listing stat tokens &mdash; <code>{stat_*}</code></summary>
      <div class="ec-acc-body">
        <p class="ec-acc-desc">
          Use these tokens inside the stats template in <strong>Edit Listing &rarr; Templates</strong>.
          The template is processed by <code>embed/stats.php</code>.
        </p>
        <table class="ec-vt">
          <thead>
            <tr><th>Token</th><th>Output</th></tr>
          </thead>
          <tbody>
            <tr><td><code class="ec-tk">{stat_approved}</code></td><td>Approved member count. <span class="ec-ex">e.g. <em>33</em></span></td></tr>
            <tr><td><code class="ec-tk">{stat_pending}</code></td><td>Members awaiting approval. <span class="ec-ex">e.g. <em>2</em></span></td></tr>
            <tr><td><code class="ec-tk">{stat_opened}</code></td><td>Date the listing opened. <span class="ec-ex">e.g. <em>January 29th 2026</em></span></td></tr>
            <tr><td><code class="ec-tk">{stat_updated}</code></td><td>Date of the most recently approved member.</td></tr>
            <tr><td><code class="ec-tk">{stat_last_updated}</code></td><td>Admin-controlled &ldquo;last updated&rdquo; date from listing settings.</td></tr>
            <tr><td><code class="ec-tk">{stat_newmembers}</code></td><td>Comma-separated list of the 5 most recently approved member names.</td></tr>
            <tr><td><code class="ec-tk">{stat_growth}</code></td><td>Members per day since the listing opened (2 decimal places).</td></tr>
            <tr><td><code class="ec-tk">{listing_name}</code></td><td>The listing name. <span class="ec-ex">e.g. <em>Huntrix</em></span></td></tr>
            <tr><td><code class="ec-tk">{listing_url}</code></td><td>The listing URL.</td></tr>
          </tbody>
        </table>
      </div>
    </details>

    <details class="ec-acc">
      <summary>Collective stat variables &mdash; <code>$luster_*</code></summary>
      <div class="ec-acc-body">
        <p class="ec-acc-desc">
          After <code>require()</code>-ing <code>collective-stats.php</code>, these PHP variables are exposed
          for you to use directly anywhere on your page.
        </p>
        <table class="ec-vt">
          <thead>
            <tr><th>Variable</th><th>Output</th></tr>
          </thead>
          <tbody>
            <tr><td><code>$luster_owned</code></td><td>Total owned listings (all statuses).</td></tr>
            <tr><td><code>$luster_owned_active</code></td><td>Open / active owned listings.</td></tr>
            <tr><td><code>$luster_owned_upcoming</code></td><td>Upcoming owned listings.</td></tr>
            <tr><td><code>$luster_owned_trouble</code></td><td>Trouble / inactive owned listings.</td></tr>
            <tr><td><code>$luster_joined</code></td><td>Total joined listings.</td></tr>
            <tr><td><code>$luster_joined_active</code></td><td>Active joined listings.</td></tr>
            <tr><td><code>$luster_members_approved</code></td><td>Approved members across all listings.</td></tr>
            <tr><td><code>$luster_members_total</code></td><td>Total members (approved + pending) across all listings.</td></tr>
            <tr><td><code>$luster_affiliates</code></td><td>Active collective affiliates.</td></tr>
            <tr><td><code>$luster_owned_newest</code></td><td>Array of newest active owned listings (Listing objects).</td></tr>
            <tr><td><code>$luster_joined_newest</code></td><td>Array of newest active joined listings (JoinedListing objects).</td></tr>
          </tbody>
        </table>
      </div>
    </details>

    <details class="ec-acc">
      <summary>Members row template tokens &mdash; <code>{fan_*}</code></summary>
      <div class="ec-acc-body">
        <p class="ec-acc-desc">
          Use these tokens in the member row template in <strong>Edit Listing &rarr; Templates</strong>.
          Each token is replaced per row when rendering the members list.
        </p>
        <table class="ec-vt">
          <thead>
            <tr><th>Token</th><th>Output</th></tr>
          </thead>
          <tbody>
            <tr><td><code class="ec-tk">{fan_name}</code></td><td>Member&rsquo;s display name. <span class="ec-ex">e.g. <em>Alexia</em></span></td></tr>
            <tr><td><code class="ec-tk">{fan_url}</code></td><td>Member&rsquo;s website URL.</td></tr>
            <tr><td><code class="ec-tk">{fan_email}</code></td><td>Member&rsquo;s email address &mdash; visible on the page, use with care.</td></tr>
            <tr><td><code class="ec-tk">{fan_country}</code></td><td>Country name. <span class="ec-ex">e.g. <em>Philippines</em></span></td></tr>
          </tbody>
        </table>
      </div>
    </details>

  </div><!-- /#ec-panel-ref -->

</div><!-- /.ec-root -->
