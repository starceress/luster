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
// ──── QUICK COPY SNIPPET CODE STRINGS (RAW PHP, JSON-ENCODED FOR JS) ────────
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

  'aff-apply' => implode("\n", [
    '<?php',
    '// Enable affiliate applications in Admin → Listings → Edit → Affiliates',
    "\$luster_fanlisting_id = {$id};",
    "require('{$ep}/affiliate-apply.php');",
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
    "// \$luster_status             = 'current'; // current | upcoming | pending | all",
    "// \$luster_browse_by_category = true;      // category index then filter",
    "// \$luster_hide_dropdown      = false;     // true = hide category filter",
    "// \$luster_show_list          = false;     // true = category links vs dropdown",
    "// \$luster_exclude_pending    = true;      // hide pending when status = 'all'",
    "require('{$ep}/owned.php');",
    '?>',
  ]),

  'joined' => implode("\n", [
    '<?php',
    "// \$luster_browse_by_category = true;  // category index then filter",
    "// \$luster_category           = '';    // '' = dropdown | 'all' = all grouped | or exact name",
    "// \$luster_show_list          = false; // true = category links vs dropdown",
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

  'random' => implode("\n", [
    '<?php',
    "// \$luster_status = 'current'; // current | upcoming | all",
    "require('{$ep}/random-listing.php');",
    '?>',
  ]),

  'latest' => implode("\n", [
    '<?php',
    "// Renders the listing with the most recent open date.",
    "require('{$ep}/latest-listing.php');",
    '?>',
  ]),

];
// ──── ITEM METADATA (LABEL, BADGE, DESC, FILE, CUSTTYPE) ─────────────────
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
  'aff-apply' => [
    'label'    => 'Affiliate application',
    'badge'    => 'form',
    'file'     => 'embed/affiliate-apply.php',
    'custType' => 'aff-apply',
    'desc'     => 'Affiliate application form — must be enabled per-listing in Edit Listing → Affiliates. Applicants submit their site name, URL, and optional message; you review them in Admin → Affiliates → Pending.',
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
    'desc'     => 'Displays your owned fanlistings. Enable browse-by-category mode to show a category index first; clicking a category then lists its listings using your HTML template.',
  ],
  'joined' => [
    'label'    => 'Joined listings',
    'badge'    => 'data',
    'file'     => 'embed/joined.php',
    'custType' => 'joined',
    'desc'     => 'Lists the fanlistings you have joined. Enable browse-by-category mode to show a category index first; clicking a category then lists its listings using your HTML template.',
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
  'random' => [
    'label'    => 'Random listing',
    'badge'    => 'data',
    'file'     => 'embed/random-listing.php',
    'custType' => 'random',
    'desc'     => 'Picks one active listing at random and renders it using the owned-listings row template from Admin &rarr; Collective Templates. Use <code>$luster_status</code> to control which pool to draw from.',
  ],
  'latest' => [
    'label'    => 'Latest listing',
    'badge'    => 'data',
    'file'     => 'embed/latest-listing.php',
    'custType' => 'latest',
    'desc'     => 'Renders the most recently opened active listing using the owned-listings row template from Admin &rarr; Collective Templates.',
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
  ['label' => 'For a fanlisting page', 'keys' => ['join', 'members', 'stats', 'affiliates', 'aff-apply', 'update', 'remove', 'reset']],
  ['label' => 'For a collective page',  'keys' => ['owned', 'joined', 'cstats', 'aff-col', 'random', 'latest', 'kim']],
];

// Customizer type groups (mirror nav groups)
$custGroups = [
  ['label' => 'For a fanlisting page', 'keys' => ['join', 'members', 'stats', 'affiliates', 'aff-apply', 'update', 'remove', 'reset']],
  ['label' => 'For a collective page',  'keys' => ['owned', 'joined', 'cstats', 'aff-col', 'random', 'latest', 'kim']],
];

?>

<div class="ec-root" id="ec-root"
     data-embed-path="<?= $epSafe ?>"
     data-listings='<?= $listingsJson ?>'
     data-items='<?= $itemsJson ?>'>

  <p class="text-muted embed-code-intro">
    Add Luster forms and data to any PHP page on your site with a single snippet.
    The embed path is already filled in based on where Luster is installed on this server.
  </p>

  <div class="callout">
    <strong>Before you start:</strong> every snippet needs your
    <code>$luster_fanlisting_id</code> (the integer ID of the listing &mdash;
    find it in the URL when editing a listing in this admin panel).
    Collective embeds like owned listings and site statistics do not need a listing ID.
  </div>
  
  <div class="tabs-wrap" id="ec-tabs-wrap">
    <div class="tabs" id="ec-tabs" role="tablist" aria-label="Embed codes sections">
      <button class="tabs-btn active" data-ec-tab="qc"   role="tab" aria-selected="true"  aria-controls="ec-panel-qc">Quick copy</button>
      <button class="tabs-btn"        data-ec-tab="cust" role="tab" aria-selected="false" aria-controls="ec-panel-cust">Customizer</button>
      <button class="tabs-btn"        data-ec-tab="ref"  role="tab" aria-selected="false" aria-controls="ec-panel-ref">Variable reference</button>
    </div>
  </div>
<!-- ──── QUICK COPY ─────────────────────────────────────────────────── -->
  <div class="tabs-panel active" id="ec-panel-qc" role="tabpanel">
    <div class="ec-qc-layout">
<!-- ──── SIDEBAR NAV ────────────────────────────────────────────────── -->
      <nav class="ec-qc-nav" aria-label="Embed types">
        <div class="ec-qc-nav-head">Embed Types</div>
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
<!-- ──── CONTENT PANE (POPULATED BY JS) ─────────────────────────────── -->
      <div class="ec-qc-pane" id="ec-qc-pane">
        <div class="ec-qc-head">
          <span class="ec-qc-title" id="ec-qc-title">&nbsp;</span>
          <span class="badge" id="ec-qc-badge"></span>
        </div>
        <div class="ec-qc-desc" id="ec-qc-desc"></div>
        <div class="ec-code-wrap">
          <div class="ec-code-toolbar">
            <span class="ec-code-file">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              <span id="ec-qc-file"></span>
            </span>
            <button class="ec-cp-btn" id="ec-qc-cp">Copy</button>
          </div>
          <pre class="ec-pre" id="ec-qc-code"></pre>
        </div>
        <div class="ec-qc-foot">
          <span class="ec-qc-hint" id="ec-qc-hint"></span>
          <button class="ec-cust-link" id="ec-qc-cust">Customize options &rarr;</button>
        </div>
      </div>

    </div>
  </div><!-- /#ec-panel-qc -->
<!-- ──── CUSTOMIZER ─────────────────────────────────────────────────── -->
  <div class="tabs-panel" id="ec-panel-cust" role="tabpanel">
    <div class="ec-cust-layout">
<!-- ──── LEFT COLUMN: TYPE PICKER + OPTIONS ─────────────────────────── -->
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
<!-- ──── RIGHT COLUMN: GENERATED CODE ───────────────────────────────── -->
      <div class="ec-cust-right">
        <div class="ec-live-card">
          <div class="ec-live-head">
            <span class="ec-live-title">Generated code</span>
            <button class="ec-live-cp" id="ec-live-cp">Copy</button>
          </div>
          <textarea class="ec-live-ta" id="ec-live-ta"
                    spellcheck="false" autocomplete="off" autocorrect="off"
                    autocapitalize="off"></textarea>
        </div>
        <div class="ec-live-note" id="ec-live-note"></div>
      </div>

    </div>
  </div><!-- /#ec-panel-cust -->
<!-- ──── VARIABLE REFERENCE ─────────────────────────────────────────── -->
  <div class="tabs-panel" id="ec-panel-ref" role="tabpanel">

    <p class="text-muted embed-ref-intro">
      Variables are PHP variables you set <em>before</em> the <code>require</code> line.
      Tokens like <code>{stat_approved}</code> go inside your stats or member row template strings
      &mdash; Luster replaces them with live data when the page loads.
    </p>

    <div class="ec-ref-layout">
<!-- ──── REF NAV ────────────────────────────────────────────────────── -->
      <nav class="ec-ref-nav" aria-label="Reference sections">
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-general">General variables</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-members">Members list</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-reset">Password reset</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-owned">Owned listings</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-joined">Joined listings</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-stat-tokens">Stat tokens</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-cstat-vars">Collective stat vars</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-row-tokens">Member row tokens</button>
      </nav>
<!-- ──── REF PANELS ─────────────────────────────────────────────────── -->
      <div>

        <div class="ec-ref-panel" id="ec-ref-general">
          <h3 class="pc-head-title embed-ref-heading">General variables</h3>
          <p class="text-muted embed-ref-copy">Set these PHP variables <em>before</em> the <code>require</code> line. They apply to most embeds.</p>
          <div class="ec-ref-card">
            <table class="ec-vt">
              <thead><tr><th>Variable</th><th>Description</th><th></th></tr></thead>
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
        </div>

        <div class="ec-ref-panel" id="ec-ref-members">
          <h3 class="pc-head-title embed-ref-heading">Members list options</h3>
          <p class="text-muted embed-ref-copy">Options for <code>embed/members.php</code>.</p>
          <div class="ec-ref-card">
            <table class="ec-vt">
              <thead><tr><th>Variable</th><th>Description</th><th></th></tr></thead>
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
        </div>

        <div class="ec-ref-panel" id="ec-ref-reset">
          <h3 class="pc-head-title embed-ref-heading">Password reset options</h3>
          <p class="text-muted embed-ref-copy">Options for <code>embed/reset.php</code>.</p>
          <div class="ec-ref-card">
            <table class="ec-vt">
              <thead><tr><th>Variable</th><th>Description</th><th></th></tr></thead>
              <tbody>
                <tr>
                  <td><code>$luster_reset_step</code></td>
                  <td>By default both steps (email entry and new-password form) appear on the same page. Set to <code>'confirm'</code> to show only step 2 (new-password form) &mdash; useful if you host both steps on separate pages.</td>
                  <td><span class="ec-pill ec-pill--opt">Optional</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="ec-ref-panel" id="ec-ref-owned">
          <h3 class="pc-head-title embed-ref-heading">Owned listings options</h3>
          <p class="text-muted embed-ref-copy">Options for <code>embed/owned.php</code>.</p>
          <div class="ec-ref-card">
            <table class="ec-vt">
              <thead><tr><th>Variable</th><th>Description</th><th></th></tr></thead>
              <tbody>
                <tr>
                  <td><code>$luster_browse_by_category</code></td>
                  <td>Set to <code>true</code> to enable browse-by-category mode. The landing view shows a category index with listing counts. Clicking a category loads its listings formatted with your HTML template from Admin &rarr; Collective Templates.</td>
                  <td><span class="ec-pill ec-pill--opt">Optional</span></td>
                </tr>
                <tr>
                  <td><code>$luster_status</code></td>
                  <td>Filter by status: <code>'current'</code>, <code>'upcoming'</code>, <code>'pending'</code>, or <code>'all'</code>. Defaults to <code>'current'</code> only.</td>
                  <td><span class="ec-pill ec-pill--opt">Optional</span></td>
                </tr>
                <tr>
                  <td><code>$luster_category_id</code></td>
                  <td>Pre-filter to a specific category by its integer ID. When <code>0</code> (default), reads <code>$_GET['cat']</code> automatically so the built-in selector works without extra PHP.</td>
                  <td><span class="ec-pill ec-pill--opt">Optional</span></td>
                </tr>
                <tr>
                  <td><code>$luster_hide_dropdown</code></td>
                  <td>Set to <code>true</code> to hide the category filter entirely. Useful when combining with <code>$luster_category_id</code> to show a single fixed category with no selector.</td>
                  <td><span class="ec-pill ec-pill--opt">Optional</span></td>
                </tr>
                <tr>
                  <td><code>$luster_show_list</code></td>
                  <td>Set to <code>true</code> to render categories as a bulleted link list instead of a <code>&lt;select&gt;</code> dropdown.</td>
                  <td><span class="ec-pill ec-pill--opt">Optional</span></td>
                </tr>
                <tr>
                  <td><code>$luster_exclude_pending</code></td>
                  <td>When <code>true</code> (default) and <code>$luster_status</code> is <code>'all'</code>, pending listings are silently omitted. Has no effect for other status values.</td>
                  <td><span class="ec-pill ec-pill--opt">Optional</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="ec-ref-panel" id="ec-ref-joined">
          <h3 class="pc-head-title embed-ref-heading">Joined listings options</h3>
          <p class="text-muted embed-ref-copy">Options for <code>embed/joined.php</code>.</p>
          <div class="ec-ref-card">
            <table class="ec-vt">
              <thead><tr><th>Variable</th><th>Description</th><th></th></tr></thead>
              <tbody>
                <tr>
                  <td><code>$luster_browse_by_category</code></td>
                  <td>Set to <code>true</code> to enable browse-by-category mode. The landing view shows all category names as links. Clicking a category loads its listings formatted with your HTML template from Admin &rarr; Collective Templates.</td>
                  <td><span class="ec-pill ec-pill--opt">Optional</span></td>
                </tr>
                <tr>
                  <td><code>$luster_category</code></td>
                  <td>Leave blank (<code>''</code>) for a dropdown selector. Set to <code>'all'</code> to show every listing grouped by category heading. Or enter an exact category name to show only that category&rsquo;s listings.</td>
                  <td><span class="ec-pill ec-pill--opt">Optional</span></td>
                </tr>
                <tr>
                  <td><code>$luster_show_list</code></td>
                  <td>Set to <code>true</code> to render the category selector as a bulleted link list instead of a <code>&lt;select&gt;</code> dropdown. Has no effect when <code>$luster_category</code> is <code>'all'</code> or a fixed name.</td>
                  <td><span class="ec-pill ec-pill--opt">Optional</span></td>
                </tr>
                <tr>
                  <td><code>$luster_show_all_by_default</code></td>
                  <td>Set to <code>true</code> to show the dropdown selector AND all listings grouped below it on the initial page load (no category needs to be selected first).</td>
                  <td><span class="ec-pill ec-pill--opt">Optional</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="ec-ref-panel" id="ec-ref-stat-tokens">
          <h3 class="pc-head-title embed-ref-heading">Per-listing stat tokens &mdash; <code>{stat_*}</code></h3>
          <p class="text-muted embed-ref-copy">Use these tokens inside the stats template in <strong>Edit Listing &rarr; Templates</strong>. The template is processed by <code>embed/stats.php</code>.</p>
          <div class="ec-ref-card">
            <table class="ec-vt">
              <thead><tr><th>Token</th><th>Output</th></tr></thead>
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
        </div>

        <div class="ec-ref-panel" id="ec-ref-cstat-vars">
          <h3 class="pc-head-title embed-ref-heading">Collective stat variables &mdash; <code>$luster_*</code></h3>
          <p class="text-muted embed-ref-copy">After <code>require()</code>-ing <code>collective-stats.php</code>, these PHP variables are exposed for you to use directly anywhere on your page.</p>
          <div class="ec-ref-card">
            <table class="ec-vt">
              <thead><tr><th>Variable</th><th>Output</th></tr></thead>
              <tbody>
                <tr><td><code>$luster_owned</code></td><td>Total owned listings (all statuses).</td></tr>
                <tr><td><code>$luster_owned_active</code></td><td>Open / active owned listings.</td></tr>
                <tr><td><code>$luster_owned_upcoming</code></td><td>Upcoming owned listings.</td></tr>
                <tr><td><code>$luster_owned_pending</code></td><td>Pending / inactive owned listings.</td></tr>
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
        </div>

        <div class="ec-ref-panel" id="ec-ref-row-tokens">
          <h3 class="pc-head-title embed-ref-heading">Member row template tokens &mdash; <code>{fan_*}</code></h3>
          <p class="text-muted embed-ref-copy">Use these tokens in the member row template in <strong>Edit Listing &rarr; Templates</strong>. Each token is replaced per row when rendering the members list.</p>
          <div class="ec-ref-card">
            <table class="ec-vt">
              <thead><tr><th>Token</th><th>Output</th></tr></thead>
              <tbody>
                <tr><td><code class="ec-tk">{fan_name}</code></td><td>Member&rsquo;s display name. <span class="ec-ex">e.g. <em>Alexia</em></span></td></tr>
                <tr><td><code class="ec-tk">{fan_url}</code></td><td>Member&rsquo;s website URL.</td></tr>
                <tr><td><code class="ec-tk">{fan_email}</code></td><td>Member&rsquo;s email address &mdash; visible on the page, use with care.</td></tr>
                <tr><td><code class="ec-tk">{fan_country}</code></td><td>Country name. <span class="ec-ex">e.g. <em>Philippines</em></span></td></tr>
              </tbody>
            </table>
          </div>
        </div>

      </div><!-- /ref panels -->

    </div><!-- /.ec-ref-layout -->

  </div><!-- /#ec-panel-ref -->

</div><!-- /.ec-root -->
