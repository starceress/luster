<?php

declare(strict_types=1);

/**
 * @var string  $embed_path   Absolute server path to public/embed/
 * @var int     $example_id   A real listing ID to use in snippets
 * @var array   $listings  All Listing objects
 * @var string  $base_url
 * @var string  $csrf_token
 */

$b  = rtrim(\Luster\Core\View::e($base_url ?? ''), '/');
$ep = rtrim($embed_path ?? '/path/to/luster/public/embed', '/');
$id = max(1, (int) ($example_id ?? 1));

// Build ID selector options for the "try a different listing" hint
$flOptions = [];
foreach ($listings ?? [] as $fl) {
    $flOptions[$fl->id] = \Luster\Core\View::e($fl->name);
}

?>

<style>
/* ── Theme-aware code block tokens ───────────────────────────── */
[data-theme="dark"] {
  --ec-code-bg:   #07040f;
  --ec-code-text: #ddd6fe;
  --ec-copy-bg:   rgba(255,255,255,.07);
}
[data-theme="light"] {
  --ec-code-bg:   #f3f0ff;
  --ec-code-text: #1e1340;
  --ec-copy-bg:   rgba(0,0,0,.05);
}

.ec-intro      { margin-bottom: 1.5rem; font-size: .875rem; color: var(--text-muted); line-height: 1.6; }
.ec-grid       { display: flex; flex-direction: column; gap: 1.5rem; }
.ec-card       { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; box-shadow: var(--shadow); }
.ec-card-head  { display: flex; align-items: center; gap: .75rem; padding: .9rem 1.25rem;
                 background: var(--bg-subtle); border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.ec-filename   { font-family: 'DM Mono', monospace; font-size: .9rem; font-weight: 700; color: var(--accent); }
.ec-badge      { font-size: .7rem; padding: .15rem .5rem; border-radius: 4px; font-weight: 600; }
.ec-badge-req  { background: var(--accent-glow); color: var(--accent); }
.ec-badge-opt  { background: var(--success-bg); color: var(--success); }
.ec-badge-free { background: rgba(251,191,36,.15); color: var(--text-muted); }
.ec-desc       { margin-left: auto; font-size: .8rem; color: var(--text-dim); }
.ec-body       { padding: 1.25rem; }
.ec-pre-wrap   { position: relative; margin-bottom: 1rem; }
.ec-pre        { background: var(--ec-code-bg); border: 1px solid var(--border); border-radius: 8px;
                 padding: .85rem 3rem .85rem 1rem; font-size: .78rem; line-height: 1.7; overflow-x: auto;
                 color: var(--ec-code-text); white-space: pre; font-family: 'DM Mono', monospace; margin: 0; }
.ec-copy       { position: absolute; top: .5rem; right: .5rem; padding: .25rem .65rem;
                 font-size: .7rem; border-radius: 4px; cursor: pointer; border: 1px solid var(--border);
                 background: var(--ec-copy-bg); color: var(--text-muted);
                 font-family: inherit; transition: background .15s, color .15s, border-color .15s; }
.ec-copy:hover  { background: var(--accent); color: #fff; border-color: var(--accent); }
.ec-copy.copied { background: var(--success); color: #fff; border-color: var(--success); }
.ec-vars       { width: 100%; border-collapse: collapse; font-size: .8rem; margin-top: .25rem; }
.ec-vars th    { text-align: left; padding: .4rem .75rem; font-size: .7rem; font-weight: 700;
                 color: var(--text-dim); text-transform: uppercase; letter-spacing: .05em;
                 border-bottom: 1px solid var(--border); }
.ec-vars td    { padding: .45rem .75rem; border-bottom: 1px solid var(--border-soft);
                 vertical-align: top; }
.ec-vars tr:last-child td { border-bottom: none; }
.ec-vars code  { font-family: 'DM Mono', monospace; font-size: .8em; color: var(--accent);
                 background: var(--accent-glow); padding: .1em .35em; border-radius: 3px; }
.ec-vars .ec-type { color: var(--text-dim); font-size: .75rem; white-space: nowrap; }
.ec-vars .ec-def  { color: var(--text-dim); font-size: .75rem; }
.ec-note       { font-size: .8rem; color: var(--text-muted); margin-top: .85rem; padding: .6rem .85rem;
                 background: var(--accent-glow); border-left: 3px solid var(--accent); border-radius: 0 4px 4px 0; }
.ec-note code  { font-family: 'DM Mono', monospace; font-size: .85em; color: var(--accent);
                 background: var(--accent-glow); padding: .1em .35em; border-radius: 3px; }
.ec-section    { font-size: .7rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase;
                 letter-spacing: .08em; margin: .85rem 0 .3rem; }
.ec-tokens     { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: .5rem; }
.ec-token      { font-family: 'DM Mono', monospace; font-size: .78rem; color: var(--accent);
                 background: var(--accent-glow); padding: .15em .5em; border-radius: 3px; }
</style>

<p class="ec-intro">
  Each embed file is a self-contained PHP fragment — set the required variables, then
  <code>require</code> the file. It handles its own bootstrapping. Use
  <code>$luster_return = true</code> on any embed that supports it to capture the output
  as a string instead of echoing directly.
</p>

<?php if (!empty($flOptions)): ?>
  <p class="ec-intro" style="margin-top:-.75rem">
    Your listing IDs:
    <?php foreach ($flOptions as $fid => $fname): ?>
      <strong>#<?= (int) $fid ?></strong> — <?= $fname ?>&nbsp;&nbsp;
    <?php endforeach; ?>
  </p>
<?php endif; ?>

<div class="ec-grid">

  <?php
  // ── Helper to render a copyable code block ──────────────────────────────
  $codeBlock = static function (string $id, string $code): void { ?>
    <div class="ec-pre-wrap">
      <pre class="ec-pre" id="ec-pre-<?= \Luster\Core\View::e($id) ?>"><?= $code ?></pre>
      <button class="ec-copy" onclick="ecCopy('ec-pre-<?= \Luster\Core\View::e($id) ?>', this)">Copy</button>
    </div>
  <?php }; ?>

  <!-- ══════════════════════════════════════════════════════════════════
       stats.php
  ═══════════════════════════════════════════════════════════════════ -->
  <div class="ec-card">
    <div class="ec-card-head">
      <span class="ec-filename">embed/stats.php</span>
      <span class="ec-badge ec-badge-req">Requires ID</span>
      <span class="ec-desc">Per-listing stats block with token substitution</span>
    </div>
    <div class="ec-body">
      <?php $codeBlock('stats', htmlspecialchars(
        '<?php' . "\n" .
        '$luster_fanlisting_id = ' . $id . ';' . "\n" .
        '// $luster_return = true;  // capture instead of echo' . "\n" .
        'require \'' . $ep . '/stats.php\';' . "\n",
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
      )); ?>

      <div class="ec-section">Available <code>$token$</code> placeholders (set in the listing&rsquo;s Stats Template field)</div>
      <div class="ec-tokens">
        <span class="ec-token">$stat_opened$</span>
        <span class="ec-token">$stat_updated$</span>
        <span class="ec-token">$stat_last_updated$</span>
        <span class="ec-token">$stat_approved$</span>
        <span class="ec-token">$stat_pending$</span>
        <span class="ec-token">$stat_newmembers$</span>
        <span class="ec-token">$stat_growth$</span>
        <span class="ec-token">$listing_name$</span>
        <span class="ec-token">$listing_url$</span>
      </div>
      <p class="ec-note">
        <code>$stat_last_updated$</code> uses the admin-controlled date from listing settings.
        Falls back to the <code>last_updated_fallback</code> config value (default: &ldquo;Never&rdquo;).
      </p>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════
       owned.php
  ═══════════════════════════════════════════════════════════════════ -->
  <div class="ec-card">
    <div class="ec-card-head">
      <span class="ec-filename">embed/owned.php</span>
      <span class="ec-badge ec-badge-free">No ID needed</span>
      <span class="ec-desc">Filterable list of owned listings</span>
    </div>
    <div class="ec-body">
      <?php $codeBlock('owned', htmlspecialchars(
        '<?php' . "\n" .
        '// $luster_status          = \'current\'; // current | upcoming | trouble | all' . "\n" .
        '// $luster_category_id     = 0;          // 0 = reads $_GET[\'cat\']' . "\n" .
        '// $luster_hide_dropdown   = false;' . "\n" .
        '// $luster_show_list       = false;       // list links instead of <select>' . "\n" .
        '// $luster_exclude_trouble = true;        // hide trouble listings when status=all' . "\n" .
        '// $luster_return          = true;' . "\n" .
        'require \'' . $ep . '/owned.php\';' . "\n",
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
      )); ?>

      <table class="ec-vars">
        <thead><tr><th>Variable</th><th>Type</th><th>Default</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td><code>$luster_status</code></td><td class="ec-type">string</td><td class="ec-def">'current'</td><td>Which listings to show: <code>current</code>, <code>upcoming</code>, <code>trouble</code>, or <code>all</code></td></tr>
          <tr><td><code>$luster_category_id</code></td><td class="ec-type">int</td><td class="ec-def">0</td><td>Pre-filter to a category ID. 0 reads <code>$_GET['cat']</code> so the built-in selector works automatically</td></tr>
          <tr><td><code>$luster_hide_dropdown</code></td><td class="ec-type">bool</td><td class="ec-def">false</td><td>Suppress the category selector entirely</td></tr>
          <tr><td><code>$luster_show_list</code></td><td class="ec-type">bool</td><td class="ec-def">false</td><td>Show categories as a bulleted link list instead of a dropdown</td></tr>
          <tr><td><code>$luster_exclude_trouble</code></td><td class="ec-type">bool</td><td class="ec-def">true</td><td>When <code>status=all</code>, omit trouble/inactive listings</td></tr>
          <tr><td><code>$luster_return</code></td><td class="ec-type">bool</td><td class="ec-def">false</td><td>Return HTML string instead of echoing</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════
       members.php
  ═══════════════════════════════════════════════════════════════════ -->
  <div class="ec-card">
    <div class="ec-card-head">
      <span class="ec-filename">embed/members.php</span>
      <span class="ec-badge ec-badge-req">Requires ID</span>
      <span class="ec-desc">Paginated approved members list</span>
    </div>
    <div class="ec-body">
      <?php $codeBlock('members', htmlspecialchars(
        '<?php' . "\n" .
        '$luster_fanlisting_id = ' . $id . ';' . "\n" .
        '// $luster_per_page    = 25;      // override config per_page' . "\n" .
        '// $luster_sort_by     = \'name\'; // force sort: \'name\' or \'country\'' . "\n" .
        '// $luster_show_search = false;   // hide sort links and filter controls' . "\n" .
        '// $luster_return      = true;' . "\n" .
        'require \'' . $ep . '/members.php\';' . "\n",
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
      )); ?>

      <table class="ec-vars">
        <thead><tr><th>Variable</th><th>Type</th><th>Default</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td><code>$luster_per_page</code></td><td class="ec-type">int</td><td class="ec-def">config <code>per_page</code></td><td>Members per page, overriding the global config value</td></tr>
          <tr><td><code>$luster_sort_by</code></td><td class="ec-type">string</td><td class="ec-def"><code>$_GET['sort']</code></td><td>Force a default sort: <code>'name'</code> or <code>'country'</code>. When set, <code>$_GET['sort']</code> is ignored so visitors cannot switch</td></tr>
          <tr><td><code>$luster_show_search</code></td><td class="ec-type">bool</td><td class="ec-def">true</td><td>Set <code>false</code> to hide the Sort by / country filter controls entirely</td></tr>
          <tr><td><code>$luster_return</code></td><td class="ec-type">bool</td><td class="ec-def">false</td><td>Return HTML string instead of echoing</td></tr>
        </tbody>
      </table>
      <p class="ec-note">
        <code>$_GET['country']</code> and <code>$_GET['page']</code> are always read
        automatically — pagination and country filtering work without any extra PHP.
      </p>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════
       join.php
  ═══════════════════════════════════════════════════════════════════ -->
  <div class="ec-card">
    <div class="ec-card-head">
      <span class="ec-filename">embed/join.php</span>
      <span class="ec-badge ec-badge-req">Requires ID</span>
      <span class="ec-desc">Join form — renders the full join form on your site</span>
    </div>
    <div class="ec-body">
      <?php $codeBlock('join', htmlspecialchars(
        '<?php' . "\n" .
        '$luster_fanlisting_id = ' . $id . ';' . "\n" .
        '// $luster_return = true;' . "\n" .
        'require \'' . $ep . '/join.php\';' . "\n",
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
      )); ?>

      <p class="ec-note">
        The form POSTs to Luster&rsquo;s <code>/join/<?= $id ?></code> URL.
        On <strong>success</strong> the visitor is sent to the redirect URL configured in
        the listing&rsquo;s Embed settings (Redirect after Join), or to Luster&rsquo;s
        own success page if none is set.<br>
        On <strong>validation error</strong> the visitor is sent to the
        <em>Form URL — Join</em> field in the listing&rsquo;s Embed settings.
        Set that to the URL of this page to keep users on your site after errors.
      </p>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════
       update.php
  ═══════════════════════════════════════════════════════════════════ -->
  <div class="ec-card">
    <div class="ec-card-head">
      <span class="ec-filename">embed/update.php</span>
      <span class="ec-badge ec-badge-req">Requires ID</span>
      <span class="ec-desc">Update membership form</span>
    </div>
    <div class="ec-body">
      <?php $codeBlock('update', htmlspecialchars(
        '<?php' . "\n" .
        '$luster_fanlisting_id = ' . $id . ';' . "\n" .
        '// $luster_return = true;' . "\n" .
        'require \'' . $ep . '/update.php\';' . "\n",
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
      )); ?>

      <p class="ec-note">
        On <strong>success</strong> uses the redirect URL from Embed settings (Redirect after Update).
        On <strong>error</strong> redirects to the <em>Form URL — Update</em> embed setting.
      </p>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════
       remove.php
  ═══════════════════════════════════════════════════════════════════ -->
  <div class="ec-card">
    <div class="ec-card-head">
      <span class="ec-filename">embed/remove.php</span>
      <span class="ec-badge ec-badge-req">Requires ID</span>
      <span class="ec-desc">Remove membership form</span>
    </div>
    <div class="ec-body">
      <?php $codeBlock('remove', htmlspecialchars(
        '<?php' . "\n" .
        '$luster_fanlisting_id = ' . $id . ';' . "\n" .
        '// $luster_return = true;' . "\n" .
        'require \'' . $ep . '/remove.php\';' . "\n",
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
      )); ?>

      <p class="ec-note">
        On <strong>success</strong> uses the redirect URL from Embed settings (Redirect after Remove).
        On <strong>error</strong> redirects to the <em>Form URL — Remove</em> embed setting.
      </p>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════
       reset.php
  ═══════════════════════════════════════════════════════════════════ -->
  <div class="ec-card">
    <div class="ec-card-head">
      <span class="ec-filename">embed/reset.php</span>
      <span class="ec-badge ec-badge-req">Requires ID</span>
      <span class="ec-desc">Password reset request form</span>
    </div>
    <div class="ec-body">
      <?php $codeBlock('reset', htmlspecialchars(
        '<?php' . "\n" .
        '$luster_fanlisting_id = ' . $id . ';' . "\n" .
        '// $luster_return = true;' . "\n" .
        'require \'' . $ep . '/reset.php\';' . "\n",
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
      )); ?>

      <p class="ec-note">
        Sends the reset email and redirects to a confirmation page.
        On <strong>error</strong> redirects to the <em>Form URL — Reset</em> embed setting.
        After a successful reset the visitor is sent to the <em>Redirect after Password Reset</em> embed setting.
      </p>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════
       affiliates.php
  ═══════════════════════════════════════════════════════════════════ -->
  <div class="ec-card">
    <div class="ec-card-head">
      <span class="ec-filename">embed/affiliates.php</span>
      <span class="ec-badge ec-badge-req">ID or 0</span>
      <span class="ec-desc">Affiliates list — per-listing or site-wide collective</span>
    </div>
    <div class="ec-body">
      <?php $codeBlock('affiliates', htmlspecialchars(
        '<?php' . "\n" .
        '// Per-listing:' . "\n" .
        '$luster_fanlisting_id = ' . $id . ';' . "\n\n" .
        '// OR site-wide collective (fanlisting_id IS NULL):' . "\n" .
        '// $luster_fanlisting_id = 0;' . "\n\n" .
        '// $luster_per_page = 50;   // default 50' . "\n" .
        '// $luster_page     = 1;    // default: reads $_GET[\'page\']' . "\n" .
        '// $luster_return   = true;' . "\n" .
        'require \'' . $ep . '/affiliates.php\';' . "\n",
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
      )); ?>

      <table class="ec-vars">
        <thead><tr><th>Variable</th><th>Type</th><th>Default</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td><code>$luster_fanlisting_id</code></td><td class="ec-type">int</td><td class="ec-def">—</td><td><strong>Required.</strong> Listing ID, or <code>0</code> for site-wide collective affiliates</td></tr>
          <tr><td><code>$luster_per_page</code></td><td class="ec-type">int</td><td class="ec-def">50</td><td>Affiliates per page</td></tr>
          <tr><td><code>$luster_page</code></td><td class="ec-type">int</td><td class="ec-def"><code>$_GET['page']</code></td><td>Current page; defaults to reading <code>$_GET['page']</code></td></tr>
          <tr><td><code>$luster_return</code></td><td class="ec-type">bool</td><td class="ec-def">false</td><td>Return HTML string instead of echoing</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════
       joined.php
  ═══════════════════════════════════════════════════════════════════ -->
  <div class="ec-card">
    <div class="ec-card-head">
      <span class="ec-filename">embed/joined.php</span>
      <span class="ec-badge ec-badge-free">No ID needed</span>
      <span class="ec-desc">Joined listings — categorised list</span>
    </div>
    <div class="ec-body">
      <?php $codeBlock('joined', htmlspecialchars(
        '<?php' . "\n" .
        '// $luster_category  = \'\';   // \'\' = selector, \'all\' = all grouped, or exact name' . "\n" .
        '// $luster_show_list = false; // show category links as list, not dropdown' . "\n" .
        '// $luster_return    = true;' . "\n" .
        'require \'' . $ep . '/joined.php\';' . "\n",
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
      )); ?>

      <table class="ec-vars">
        <thead><tr><th>Variable</th><th>Type</th><th>Default</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td><code>$luster_category</code></td><td class="ec-type">string</td><td class="ec-def">''</td>
            <td>
              <code>''</code> — show category selector (reads <code>$_GET['cat']</code>)<br>
              <code>'all'</code> — show all active listings grouped by category heading<br>
              Other — exact category name; show only that category
            </td>
          </tr>
          <tr><td><code>$luster_show_list</code></td><td class="ec-type">bool</td><td class="ec-def">false</td><td>When the selector is visible, render it as a bulleted link list instead of a dropdown</td></tr>
          <tr><td><code>$luster_return</code></td><td class="ec-type">bool</td><td class="ec-def">false</td><td>Return HTML string instead of echoing</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════
       collective-stats.php
  ═══════════════════════════════════════════════════════════════════ -->
  <div class="ec-card">
    <div class="ec-card-head">
      <span class="ec-filename">embed/collective-stats.php</span>
      <span class="ec-badge ec-badge-free">No ID needed</span>
      <span class="ec-desc">Site-wide statistics — exposes PHP variables + optional HTML block</span>
    </div>
    <div class="ec-body">
      <?php $codeBlock('cstats', htmlspecialchars(
        '<?php' . "\n" .
        '// $luster_count     = 3;    // how many newest/random listings to fetch' . "\n" .
        '// $luster_cache_ttl = 300;  // cache seconds (0 = disabled)' . "\n" .
        '// $luster_return    = true; // return pre-rendered HTML instead of echo' . "\n" .
        'require \'' . $ep . '/collective-stats.php\';' . "\n\n" .
        '// Then use the exposed variables directly:' . "\n" .
        'echo $luster_owned_active;        // e.g. 24' . "\n" .
        'echo $luster_members_total;       // e.g. 1482' . "\n" .
        'echo $luster_owned_newest[0]->name;  // newest listing name' . "\n",
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
      )); ?>

      <div class="ec-section">Exposed variables — counts</div>
      <table class="ec-vars">
        <thead><tr><th>Variable</th><th>Type</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td><code>$luster_owned</code></td><td class="ec-type">int</td><td>Total owned listings</td></tr>
          <tr><td><code>$luster_owned_active</code></td><td class="ec-type">int</td><td>Open/active owned (not upcoming/trouble)</td></tr>
          <tr><td><code>$luster_owned_upcoming</code></td><td class="ec-type">int</td><td>Upcoming owned</td></tr>
          <tr><td><code>$luster_owned_trouble</code></td><td class="ec-type">int</td><td>Trouble/inactive owned</td></tr>
          <tr><td><code>$luster_joined</code></td><td class="ec-type">int</td><td>Total joined listings</td></tr>
          <tr><td><code>$luster_joined_active</code></td><td class="ec-type">int</td><td>Active joined</td></tr>
          <tr><td><code>$luster_members_approved</code></td><td class="ec-type">int</td><td>Approved members across all listings</td></tr>
          <tr><td><code>$luster_members_pending</code></td><td class="ec-type">int</td><td>Pending members across all listings</td></tr>
          <tr><td><code>$luster_members_total</code></td><td class="ec-type">int</td><td>Total members across all listings</td></tr>
          <tr><td><code>$luster_affiliates</code></td><td class="ec-type">int</td><td>Active collective affiliates</td></tr>
        </tbody>
      </table>

      <div class="ec-section">Exposed variables — growth rates</div>
      <table class="ec-vars">
        <thead><tr><th>Variable</th><th>Type</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td><code>$luster_owned_growth_rate</code></td><td class="ec-type">string</td><td>Active owned listings per year (2 dp)</td></tr>
          <tr><td><code>$luster_joined_growth_rate</code></td><td class="ec-type">string</td><td>Active joined listings per year (2 dp)</td></tr>
          <tr><td><code>$luster_members_growth_rate</code></td><td class="ec-type">string</td><td>Approved members per day across all listings (2 dp)</td></tr>
        </tbody>
      </table>

      <div class="ec-section">Exposed variables — object arrays</div>
      <table class="ec-vars">
        <thead><tr><th>Variable</th><th>Type</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td><code>$luster_owned_newest</code></td><td class="ec-type">Listing[]</td><td>Newest <code>$luster_count</code> active owned. Properties: <code>name</code>, <code>url</code>, <code>subject</code>, <code>joined</code>, <code>id</code></td></tr>
          <tr><td><code>$luster_joined_newest</code></td><td class="ec-type">JoinedListing[]</td><td>Newest <code>$luster_count</code> active joined. Properties: <code>name</code>, <code>url</code>, <code>category</code>, <code>dateJoined</code>, <code>image</code></td></tr>
          <tr><td><code>$luster_owned_random</code></td><td class="ec-type">?Listing</td><td>One random active owned listing, or <code>null</code></td></tr>
          <tr><td><code>$luster_joined_random</code></td><td class="ec-type">?JoinedListing</td><td>One random active joined listing, or <code>null</code></td></tr>
          <tr><td><code>$luster_categories</code></td><td class="ec-type">array</td><td>Category breakdown: <code>[{id, name, source, count}, …]</code> — count = active owned in that category</td></tr>
        </tbody>
      </table>

      <div class="ec-section">Backwards-compat aliases (matches Enthusiast naming)</div>
      <table class="ec-vars">
        <thead><tr><th>Alias</th><th>Same as</th></tr></thead>
        <tbody>
          <tr><td><code>$luster_total_cats</code></td><td><code>count($luster_categories)</code></td></tr>
          <tr><td><code>$luster_owned_current</code></td><td><code>$luster_owned_active</code></td></tr>
          <tr><td><code>$luster_collective_fans</code></td><td><code>$luster_members_total</code></td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /.ec-grid -->

<script>
function ecCopy(preId, btn) {
  const pre  = document.getElementById(preId);
  const text = pre ? pre.textContent : '';
  if (!navigator.clipboard) {
    // Fallback for HTTP
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;opacity:0';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  } else {
    navigator.clipboard.writeText(text).catch(() => {});
  }
  btn.textContent = 'Copied!';
  btn.classList.add('copied');
  setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 1800);
}
</script>
