<?php

declare(strict_types=1);

/**
 * @var string  $embed_path  Absolute server path to public/embed/
 * @var int     $example_id  A real listing ID to use in snippets
 * @var array   $listings    All Listing objects
 * @var string  $base_url
 * @var string  $csrf_token
 */

$b  = rtrim(\Luster\Core\View::e($base_url ?? ''), '/');
$ep = rtrim($embed_path ?? '/path/to/luster/public/embed', '/');
$id = max(1, (int) ($example_id ?? 1));

// Pre-render every code snippet into a PHP array for clean output below
$snip = [];
$div  = '# -----------------------------------------------------------';

$snip['join'] = htmlspecialchars(
    '<?php' . "\n" .
    $div . "\n" .
    '$luster_fanlisting_id = ' . $id . ';' . "\n" .
    $div . "\n" .
    'require(\'' . $ep . '/join.php\');' . "\n" .
    $div . "\n" .
    '?>',
    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
);

$snip['update'] = htmlspecialchars(
    '<?php' . "\n" .
    $div . "\n" .
    '$luster_fanlisting_id = ' . $id . ';' . "\n" .
    $div . "\n" .
    'require(\'' . $ep . '/update.php\');' . "\n" .
    $div . "\n" .
    '?>',
    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
);

$snip['remove'] = htmlspecialchars(
    '<?php' . "\n" .
    $div . "\n" .
    '$luster_fanlisting_id = ' . $id . ';' . "\n" .
    $div . "\n" .
    'require(\'' . $ep . '/remove.php\');' . "\n" .
    $div . "\n" .
    '?>',
    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
);

$snip['reset'] = htmlspecialchars(
    '<?php' . "\n" .
    $div . "\n" .
    '$luster_fanlisting_id = ' . $id . ';' . "\n" .
    $div . "\n" .
    'require(\'' . $ep . '/reset.php\');' . "\n" .
    $div . "\n" .
    '?>',
    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
);

$snip['members'] = htmlspecialchars(
    '<?php' . "\n" .
    $div . "\n" .
    '$luster_fanlisting_id = ' . $id . ';' . "\n" .
    $div . "\n" .
    'require(\'' . $ep . '/members.php\');' . "\n" .
    $div . "\n" .
    '?>',
    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
);

$snip['stats'] = htmlspecialchars(
    '<?php' . "\n" .
    $div . "\n" .
    '$luster_fanlisting_id = ' . $id . ';' . "\n" .
    $div . "\n" .
    'require(\'' . $ep . '/stats.php\');' . "\n" .
    $div . "\n" .
    '?>',
    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
);

$snip['affiliates'] = htmlspecialchars(
    '<?php' . "\n" .
    $div . "\n" .
    '$luster_fanlisting_id = ' . $id . ';' . "\n" .
    $div . "\n" .
    'require(\'' . $ep . '/affiliates.php\');' . "\n" .
    $div . "\n" .
    '?>',
    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
);

$snip['owned'] = htmlspecialchars(
    '<?php' . "\n" .
    $div . "\n" .
    '// $luster_status        = \'current\'; // current | upcoming | trouble | all' . "\n" .
    '// $luster_hide_dropdown = false;       // true = hide the category filter' . "\n" .
    $div . "\n" .
    'require(\'' . $ep . '/owned.php\');' . "\n" .
    $div . "\n" .
    '?>',
    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
);

$snip['joined'] = htmlspecialchars(
    '<?php' . "\n" .
    $div . "\n" .
    '// $luster_category = \'\'; // \'\' = dropdown | \'all\' = all categories | or a category name' . "\n" .
    $div . "\n" .
    'require(\'' . $ep . '/joined.php\');' . "\n" .
    $div . "\n" .
    '?>',
    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
);

$snip['cstats'] = htmlspecialchars(
    '<?php' . "\n" .
    $div . "\n" .
    'require(\'' . $ep . '/collective-stats.php\');' . "\n" .
    $div . "\n" .
    '// Use the variables anywhere on your page:' . "\n" .
    'echo $luster_owned_active;   // e.g. "12"' . "\n" .
    'echo $luster_members_total;  // e.g. "3,450"' . "\n" .
    $div . "\n" .
    '?>',
    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
);

$listingsJson = json_encode(
    array_map(fn($fl) => ['id' => (int) $fl->id, 'name' => $fl->name], $listings ?? []),
    JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG
);

$snip['kim'] = htmlspecialchars(
    '<?php' . "\n" .
    $div . "\n" .
    'require(\'' . $ep . '/kim-signup.php\');' . "\n" .
    $div . "\n" .
    '?>',
    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
);

?>
<style>
/* ─── Theme tokens ────────────────────────────────────────────── */
[data-theme="dark"]  {
  --ec-bg:   #07040f;
  --ec-text: #ddd6fe;
  --ec-copy: rgba(255,255,255,.07);
}
[data-theme="light"] {
  --ec-bg:   #f3f0ff;
  --ec-text: #1e1340;
  --ec-copy: rgba(0,0,0,.05);
}

/* ─── Page intro ──────────────────────────────────────────────── */
.ec-intro {
  font-size: .875rem;
  color: var(--text-muted);
  line-height: 1.65;
  margin-bottom: 1.5rem;
}
.ec-intro code {
  font-family: 'DM Mono', monospace;
  font-size: .85em;
  color: var(--accent);
  background: var(--accent-glow);
  padding: .1em .4em;
  border-radius: 3px;
}

/* ─── Picker layout ───────────────────────────────────────────── */
.ec-picker {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  margin-bottom: 1.5rem;
}
@media (max-width: 580px) {
  .ec-picker { grid-template-columns: 1fr; }
}

.ec-group {
  background: var(--bg-card);
  border: 1px solid var(--border-soft);
  border-radius: 12px;
  overflow: hidden;
}
.ec-group-label {
  padding: .55rem 1rem;
  font-size: .7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--text-dim);
  background: var(--bg-subtle);
  border-bottom: 1px solid var(--border-soft);
}
.ec-opts { display: flex; flex-direction: column; }
.ec-opt {
  display: flex;
  align-items: center;
  gap: .6rem;
  padding: .6rem 1rem;
  font-size: .8375rem;
  color: var(--text-muted);
  background: none;
  border: none;
  border-bottom: 1px solid var(--border-soft);
  cursor: pointer;
  text-align: left;
  transition: background .12s, color .12s;
  font-family: inherit;
  line-height: 1.2;
}
.ec-opt:last-child { border-bottom: none; }
.ec-opt::before {
  content: '';
  width: 6px; height: 6px;
  border-radius: 50%;
  border: 1.5px solid var(--border);
  flex-shrink: 0;
  transition: background .12s, border-color .12s;
}
.ec-opt:hover { background: var(--bg-subtle); color: var(--text); }
.ec-opt[aria-selected="true"] {
  background: var(--accent-glow);
  color: var(--accent);
  font-weight: 600;
}
.ec-opt[aria-selected="true"]::before {
  background: var(--accent);
  border-color: var(--accent);
}

/* ─── Active code panel ───────────────────────────────────────── */
.ec-panel-wrap { position: relative; }
.ec-panel {
  display: none;
  background: var(--bg-card);
  border: 1px solid var(--border-soft);
  border-radius: 12px;
  overflow: hidden;
}
.ec-panel.ec-panel--active { display: block; }

.ec-panel-head {
  display: flex;
  align-items: center;
  gap: .6rem;
  flex-wrap: wrap;
  padding: .7rem 1.1rem;
  background: var(--bg-subtle);
  border-bottom: 1px solid var(--border-soft);
}
.ec-filename {
  font-family: 'DM Mono', monospace;
  font-size: .825rem;
  font-weight: 700;
  color: var(--accent);
}
.ec-badge {
  font-size: .68rem;
  font-weight: 600;
  padding: .15rem .5rem;
  border-radius: 4px;
  white-space: nowrap;
}
.ec-badge-id   { background: var(--accent-glow); color: var(--accent); }
.ec-badge-free { background: var(--bg-card); color: var(--text-dim);
                 border: 1px solid var(--border-soft); }
.ec-panel-desc {
  margin-left: auto;
  font-size: .775rem;
  color: var(--text-dim);
}

.ec-panel-body { padding: 1rem 1.1rem; }

/* ─── Paste tip ───────────────────────────────────────────────── */
.ec-paste-tip {
  font-size: .8rem;
  color: var(--text-dim);
  margin: 0 0 .65rem;
  line-height: 1.5;
}

/* ─── Code block ──────────────────────────────────────────────── */
.ec-code-wrap { position: relative; margin-bottom: .75rem; }
.ec-pre {
  background: var(--ec-bg);
  border: 1px solid var(--border-soft);
  border-radius: 8px;
  padding: .9rem 3.5rem .9rem 1rem;
  font-size: .775rem;
  line-height: 1.8;
  overflow-x: auto;
  color: var(--ec-text);
  white-space: pre;
  font-family: 'DM Mono', monospace;
  margin: 0;
}
.ec-copy {
  position: absolute;
  top: .6rem; right: .6rem;
  padding: .25rem .6rem;
  font-size: .68rem;
  border-radius: 5px;
  cursor: pointer;
  border: 1px solid var(--border);
  background: var(--ec-copy);
  color: var(--text-muted);
  font-family: inherit;
  transition: background .14s, color .14s, border-color .14s;
  white-space: nowrap;
}
.ec-copy:hover  { background: var(--accent); color: #fff; border-color: var(--accent); }
.ec-copy.ec-copied { background: var(--success); color: #fff; border-color: var(--success); }

/* ─── Collapsible extras ──────────────────────────────────────── */
.ec-detail {
  border: 1px solid var(--border-soft);
  border-radius: 8px;
  overflow: hidden;
  margin-top: .6rem;
}
.ec-detail + .ec-detail { margin-top: .4rem; }
.ec-detail summary {
  padding: .55rem .9rem;
  font-size: .78rem;
  font-weight: 600;
  color: var(--text-muted);
  cursor: pointer;
  user-select: none;
  list-style: none;
  display: flex;
  align-items: center;
  gap: .45rem;
  background: var(--bg-subtle);
  transition: color .12s;
}
.ec-detail summary::-webkit-details-marker { display: none; }
.ec-detail summary::before {
  content: '▶';
  font-size: .58rem;
  color: var(--accent);
  transition: transform .14s;
  flex-shrink: 0;
}
.ec-detail[open] summary { color: var(--text); border-bottom: 1px solid var(--border-soft); }
.ec-detail[open] summary::before { transform: rotate(90deg); }
.ec-detail summary:hover { color: var(--text); }

.ec-detail-body { padding: .85rem .9rem; }

/* ─── Two-column variable table ───────────────────────────────── */
.ec-vars-list {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: .45rem .85rem;
  align-items: baseline;
}
.ec-var-row { display: contents; }
.ec-var-name {
  font-family: 'DM Mono', monospace;
  font-size: .775rem;
  color: var(--accent);
  background: var(--accent-glow);
  padding: .15em .45em;
  border-radius: 3px;
  white-space: nowrap;
  align-self: start;
}
.ec-var-desc {
  font-size: .8rem;
  color: var(--text-muted);
  line-height: 1.5;
}
.ec-var-desc code {
  font-family: 'DM Mono', monospace;
  font-size: .85em;
  color: var(--accent);
  background: var(--accent-glow);
  padding: .1em .35em;
  border-radius: 3px;
}

/* ─── Note callout ────────────────────────────────────────────── */
.ec-note {
  font-size: .8rem;
  color: var(--text-muted);
  line-height: 1.55;
  padding: .55rem .85rem;
  background: var(--accent-glow);
  border-left: 3px solid var(--accent);
  border-radius: 0 5px 5px 0;
  margin: 0;
}
.ec-note code {
  font-family: 'DM Mono', monospace;
  font-size: .85em;
  color: var(--accent);
  background: var(--accent-glow);
  padding: .1em .35em;
  border-radius: 3px;
}

/* ─── Wizard ──────────────────────────────────────────────────── */
.ec-wiz {
  background: var(--bg-card);
  border: 1px solid var(--border-soft);
  border-radius: 12px;
  overflow: hidden;
  margin-bottom: 1.5rem;
}
.ec-wiz-head {
  display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
  padding: .65rem 1.1rem;
  background: var(--bg-subtle);
  border-bottom: 1px solid var(--border-soft);
}
.ec-wiz-title {
  display: flex; align-items: center; gap: .45rem;
  font-size: .78rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .07em; color: var(--accent);
}
.ec-wiz-steps-bar { display: flex; align-items: center; gap: 5px; margin-left: auto; }
.ec-wiz-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--border); transition: background .2s;
}
.ec-wiz-dot.active { background: var(--accent); }
.ec-wiz-dot.done   { background: var(--success); }
.ec-wiz-connector  { width: 22px; height: 1px; background: var(--border-soft); }
.ec-wiz-step-label { font-size: .75rem; color: var(--text-dim); white-space: nowrap; }
.ec-wiz-body { padding: 1.1rem 1.1rem .8rem; }
/* Type grid (step 1) */
.ec-wiz-group + .ec-wiz-group { margin-top: .9rem; }
.ec-wiz-group-label {
  font-size: .68rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: var(--text-dim); margin: 0 0 .45rem;
}
.ec-wiz-type-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(148px, 1fr)); gap: .35rem;
}
.ec-wiz-type-btn {
  display: flex; flex-direction: column; gap: .15rem;
  padding: .55rem .7rem;
  background: var(--bg-subtle);
  border: 1.5px solid var(--border-soft);
  border-radius: 8px; cursor: pointer; text-align: left;
  font-family: inherit; transition: border-color .12s, background .12s;
}
.ec-wiz-type-btn:hover { border-color: var(--border); background: var(--bg-card); }
.ec-wiz-type-btn[aria-selected="true"] { border-color: var(--accent); background: var(--accent-glow); }
.ec-wiz-type-label { font-size: .82rem; font-weight: 600; color: var(--text); }
.ec-wiz-type-desc  { font-size: .71rem; color: var(--text-dim); line-height: 1.4; }
/* Options form (step 2) */
.ec-wiz-no-opts { font-size: .85rem; color: var(--text-muted); padding: .25rem 0 .5rem; font-style: italic; }
.ec-wiz-opts-note { font-size: .78rem; color: var(--text-dim); margin: 0 0 .75rem; }
.ec-wiz-field { margin-bottom: .8rem; }
.ec-wiz-field:last-child { margin-bottom: 0; }
.ec-wiz-label { display: block; font-size: .82rem; font-weight: 600; color: var(--text-muted); margin-bottom: .3rem; }
.ec-wiz-label em { font-style: normal; color: var(--accent); }
.ec-wiz-hint { font-size: .74rem; color: var(--text-dim); margin-top: .3rem; }
/* Nav bar */
.ec-wiz-nav {
  display: flex; align-items: center; gap: .6rem;
  padding: .75rem 1.1rem;
  border-top: 1px solid var(--border-soft);
  background: var(--bg-subtle);
}
.ec-wiz-restart {
  margin-left: auto; font-size: .76rem; color: var(--text-dim);
  background: none; border: none; cursor: pointer; font-family: inherit;
  padding: 0; text-decoration: underline; text-underline-offset: 2px;
}
.ec-wiz-restart:hover { color: var(--accent); }
/* Divider between wizard and reference section */
.ec-wiz-divider {
  display: flex; align-items: center; gap: .85rem;
  margin: 1.75rem 0 1.25rem; color: var(--text-dim); font-size: .78rem;
}
.ec-wiz-divider::before, .ec-wiz-divider::after {
  content: ''; flex: 1; height: 1px; background: var(--border-soft);
}

/* ─── Tokens (stats placeholders) ────────────────────────────── */
.ec-token-grid {
  display: flex;
  flex-wrap: wrap;
  gap: .3rem;
  margin-top: .35rem;
}
.ec-token {
  font-family: 'DM Mono', monospace;
  font-size: .75rem;
  color: var(--accent);
  background: var(--accent-glow);
  padding: .2em .55em;
  border-radius: 3px;
}
</style>

<p class="ec-intro">
  Pick what you want to add from the list below, then copy the code and paste it into your
  PHP page — right where you want the form or list to appear. The file path is already filled
  in for you based on where Luster is installed on this server, so you don&rsquo;t need to
  change it. Each embed drops into your existing page without any extra setup.
</p>

<!-- ═══ WIZARD ═══════════════════════════════════════════════════ -->
<div class="ec-wiz" id="ec-wiz"
     data-embed-path="<?= \Luster\Core\View::e($ep) ?>"
     data-listings='<?= $listingsJson ?>'>

  <div class="ec-wiz-head">
    <span class="ec-wiz-title">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg>
      Quick Setup
    </span>
    <div class="ec-wiz-steps-bar" aria-hidden="true">
      <span class="ec-wiz-dot" data-wiz-dot="1"></span>
      <span class="ec-wiz-connector"></span>
      <span class="ec-wiz-dot" data-wiz-dot="2"></span>
      <span class="ec-wiz-connector"></span>
      <span class="ec-wiz-dot" data-wiz-dot="3"></span>
    </div>
    <span class="ec-wiz-step-label" id="ec-wiz-step-label"></span>
  </div>

  <!-- Step 1: choose type -->
  <div class="ec-wiz-body" id="ec-wiz-p1">
    <div class="ec-wiz-group">
      <p class="ec-wiz-group-label">For a fanlisting page</p>
      <div class="ec-wiz-type-grid">
        <button class="ec-wiz-type-btn" data-wiz-type="join" aria-selected="false">
          <span class="ec-wiz-type-label">Join form</span>
          <span class="ec-wiz-type-desc">Let visitors join your fanlisting</span>
        </button>
        <button class="ec-wiz-type-btn" data-wiz-type="members" aria-selected="false">
          <span class="ec-wiz-type-label">Member list</span>
          <span class="ec-wiz-type-desc">Paginated list of approved members</span>
        </button>
        <button class="ec-wiz-type-btn" data-wiz-type="stats" aria-selected="false">
          <span class="ec-wiz-type-label">Statistics</span>
          <span class="ec-wiz-type-desc">Member count using your custom template</span>
        </button>
        <button class="ec-wiz-type-btn" data-wiz-type="update" aria-selected="false">
          <span class="ec-wiz-type-label">Update details</span>
          <span class="ec-wiz-type-desc">Members update their name and URL</span>
        </button>
        <button class="ec-wiz-type-btn" data-wiz-type="remove" aria-selected="false">
          <span class="ec-wiz-type-label">Leave listing</span>
          <span class="ec-wiz-type-desc">Members remove their membership</span>
        </button>
        <button class="ec-wiz-type-btn" data-wiz-type="reset" aria-selected="false">
          <span class="ec-wiz-type-label">Password reset</span>
          <span class="ec-wiz-type-desc">Two-step password reset flow</span>
        </button>
        <button class="ec-wiz-type-btn" data-wiz-type="affiliates" aria-selected="false">
          <span class="ec-wiz-type-label">Affiliates</span>
          <span class="ec-wiz-type-desc">Affiliates for this fanlisting</span>
        </button>
      </div>
    </div>
    <div class="ec-wiz-group">
      <p class="ec-wiz-group-label">For a collective page</p>
      <div class="ec-wiz-type-grid">
        <button class="ec-wiz-type-btn" data-wiz-type="owned" aria-selected="false">
          <span class="ec-wiz-type-label">Owned listings</span>
          <span class="ec-wiz-type-desc">Grid of all your fanlistings</span>
        </button>
        <button class="ec-wiz-type-btn" data-wiz-type="joined" aria-selected="false">
          <span class="ec-wiz-type-label">Joined listings</span>
          <span class="ec-wiz-type-desc">Fanlistings you&rsquo;ve joined</span>
        </button>
        <button class="ec-wiz-type-btn" data-wiz-type="cstats" aria-selected="false">
          <span class="ec-wiz-type-label">Site statistics</span>
          <span class="ec-wiz-type-desc">Site-wide stats into PHP variables</span>
        </button>
        <button class="ec-wiz-type-btn" data-wiz-type="aff-col" aria-selected="false">
          <span class="ec-wiz-type-label">Collective affiliates</span>
          <span class="ec-wiz-type-desc">Affiliates for your whole site</span>
        </button>
        <button class="ec-wiz-type-btn" data-wiz-type="kim" aria-selected="false">
          <span class="ec-wiz-type-label">KIM sign-up</span>
          <span class="ec-wiz-type-desc">KIM List adoption-interest form</span>
        </button>
      </div>
    </div>
  </div>

  <!-- Step 2: configure -->
  <div class="ec-wiz-body" id="ec-wiz-p2" hidden>
    <div id="ec-wiz-opts"></div>
  </div>

  <!-- Step 3: copy code -->
  <div class="ec-wiz-body" id="ec-wiz-p3" hidden>
    <p class="ec-paste-tip" style="margin-bottom:.65rem">Copy this snippet and paste it into your PHP page where you want the embed to appear.</p>
    <div class="ec-code-wrap">
      <pre class="ec-pre" id="ec-wiz-code"></pre>
      <button class="ec-copy" data-ec-copy="ec-wiz-code">Copy</button>
    </div>
  </div>

  <div class="ec-wiz-nav">
    <button class="btn btn-ghost btn-sm" id="ec-wiz-back" hidden>&larr; Back</button>
    <button class="btn btn-primary btn-sm" id="ec-wiz-next" disabled>Choose an option above</button>
    <button class="ec-wiz-restart" id="ec-wiz-restart" hidden>Start over</button>
  </div>

</div><!-- /.ec-wiz -->

<div class="ec-wiz-divider"><span>or browse all embed codes</span></div>

<!-- ═══ PICKER ═══════════════════════════════════════════════════ -->
<div class="ec-picker">

  <div class="ec-group">
    <div class="ec-group-label">Adding to a fanlisting page</div>
    <div class="ec-opts" role="listbox" aria-label="Fanlisting embeds">
      <button class="ec-opt" role="option" aria-selected="false" data-target="join">Join form</button>
      <button class="ec-opt" role="option" aria-selected="false" data-target="members">Member list</button>
      <button class="ec-opt" role="option" aria-selected="false" data-target="stats">Statistics</button>
      <button class="ec-opt" role="option" aria-selected="false" data-target="update">Update details</button>
      <button class="ec-opt" role="option" aria-selected="false" data-target="remove">Remove membership</button>
      <button class="ec-opt" role="option" aria-selected="false" data-target="reset">Password reset</button>
      <button class="ec-opt" role="option" aria-selected="false" data-target="affiliates">Affiliates</button>
    </div>
  </div>

  <div class="ec-group">
    <div class="ec-group-label">Adding to a collective page</div>
    <div class="ec-opts" role="listbox" aria-label="Collective embeds">
      <button class="ec-opt" role="option" aria-selected="false" data-target="owned">Owned listings</button>
      <button class="ec-opt" role="option" aria-selected="false" data-target="joined">Joined listings</button>
      <button class="ec-opt" role="option" aria-selected="false" data-target="cstats">Site statistics</button>
      <button class="ec-opt" role="option" aria-selected="false" data-target="affiliates-collective">Collective affiliates</button>
      <button class="ec-opt" role="option" aria-selected="false" data-target="kim">KIM sign-up form</button>
    </div>
  </div>

</div><!-- /.ec-picker -->

<!-- ═══ CODE PANELS ══════════════════════════════════════════════ -->
<div class="ec-panel-wrap">

  <!-- join -->
  <div class="ec-panel" id="ec-panel-join">
    <div class="ec-panel-head">
      <span class="ec-filename">embed/join.php</span>
      <span class="ec-badge ec-badge-id">Requires listing ID</span>
      <span class="ec-panel-desc">The join form for your fanlisting</span>
    </div>
    <div class="ec-panel-body">
      <p class="ec-paste-tip">Paste this into the PHP page where you want the join form to appear.</p>
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-join"><?= $snip['join'] ?></pre>
        <button class="ec-copy" data-ec-copy="ec-pre-join">Copy</button>
      </div>
      <details class="ec-detail">
        <summary>Where do people go after they submit?</summary>
        <div class="ec-detail-body">
          <p class="ec-note">
            After someone fills in the form, they get sent to the URL you set under the listing&rsquo;s
            <strong>Edit &rarr; Embed tab</strong>. Set <em>Redirect after Join</em> to your success page,
            and <em>Form URL &mdash; Join</em> to this same page (so validation errors bring them back here).
          </p>
        </div>
      </details>
    </div>
  </div>

  <!-- members -->
  <div class="ec-panel" id="ec-panel-members">
    <div class="ec-panel-head">
      <span class="ec-filename">embed/members.php</span>
      <span class="ec-badge ec-badge-id">Requires listing ID</span>
      <span class="ec-panel-desc">Paginated list of approved members</span>
    </div>
    <div class="ec-panel-body">
      <p class="ec-paste-tip">Paste this into the PHP page where you want the member list to appear.</p>
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-members"><?= $snip['members'] ?></pre>
        <button class="ec-copy" data-ec-copy="ec-pre-members">Copy</button>
      </div>
      <details class="ec-detail">
        <summary>Optional customization</summary>
        <div class="ec-detail-body">
          <div class="ec-vars-list">
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_per_page</span>
              <span class="ec-var-desc">How many members to show per page. Example: <code>$luster_per_page = 20;</code> — defaults to the site setting if left out.</span>
            </div>
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_sort_by</span>
              <span class="ec-var-desc">Lock the default sort order. Use <code>'name'</code> or <code>'country'</code>. Leave it out and visitors can choose their own sort.</span>
            </div>
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_show_search</span>
              <span class="ec-var-desc">Set to <code>false</code> to hide the sort and search controls above the list entirely.</span>
            </div>
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_members_base</span>
              <span class="ec-var-desc">The full URL of the page where you put this embed. This keeps pagination links pointing back to your page instead of Luster&rsquo;s internal URL. Example: <code>'https://yoursite.com/members.php'</code></span>
            </div>
          </div>
        </div>
      </details>
    </div>
  </div>

  <!-- stats -->
  <div class="ec-panel" id="ec-panel-stats">
    <div class="ec-panel-head">
      <span class="ec-filename">embed/stats.php</span>
      <span class="ec-badge ec-badge-id">Requires listing ID</span>
      <span class="ec-panel-desc">Member stats using your own custom template</span>
    </div>
    <div class="ec-panel-body">
      <p class="ec-paste-tip">Paste this into the PHP page where you want the stats block to appear.</p>
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-stats"><?= $snip['stats'] ?></pre>
        <button class="ec-copy" data-ec-copy="ec-pre-stats">Copy</button>
      </div>
      <details class="ec-detail">
        <summary>Placeholders you can use in your stats template</summary>
        <div class="ec-detail-body">
          <p style="font-size:.8rem;color:var(--text-muted);margin:0 0 .75rem">
            Write a template in the listing&rsquo;s <strong>Website Templates</strong> settings.
            Use any of these placeholders — they get replaced with live data when the page loads.
          </p>
          <div class="ec-token-grid">
            <span class="ec-token">{stat_approved}</span>
            <span class="ec-token">{stat_pending}</span>
            <span class="ec-token">{stat_newmembers}</span>
            <span class="ec-token">{stat_opened}</span>
            <span class="ec-token">{stat_updated}</span>
            <span class="ec-token">{stat_last_updated}</span>
            <span class="ec-token">{stat_growth}</span>
            <span class="ec-token">{listing_name}</span>
            <span class="ec-token">{listing_url}</span>
          </div>
        </div>
      </details>
    </div>
  </div>

  <!-- update -->
  <div class="ec-panel" id="ec-panel-update">
    <div class="ec-panel-head">
      <span class="ec-filename">embed/update.php</span>
      <span class="ec-badge ec-badge-id">Requires listing ID</span>
      <span class="ec-panel-desc">Lets members update their name, URL, and other details</span>
    </div>
    <div class="ec-panel-body">
      <p class="ec-paste-tip">Paste this into the PHP page where you want the update form to appear.</p>
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-update"><?= $snip['update'] ?></pre>
        <button class="ec-copy" data-ec-copy="ec-pre-update">Copy</button>
      </div>
      <details class="ec-detail">
        <summary>Where do people go after they submit?</summary>
        <div class="ec-detail-body">
          <p class="ec-note">
            Set <em>Redirect after Update</em> and <em>Form URL &mdash; Update</em> in the
            listing&rsquo;s <strong>Edit &rarr; Embed tab</strong>.
          </p>
        </div>
      </details>
    </div>
  </div>

  <!-- remove -->
  <div class="ec-panel" id="ec-panel-remove">
    <div class="ec-panel-head">
      <span class="ec-filename">embed/remove.php</span>
      <span class="ec-badge ec-badge-id">Requires listing ID</span>
      <span class="ec-panel-desc">Lets members remove themselves from the fanlisting</span>
    </div>
    <div class="ec-panel-body">
      <p class="ec-paste-tip">Paste this into the PHP page where you want the leave/remove form to appear.</p>
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-remove"><?= $snip['remove'] ?></pre>
        <button class="ec-copy" data-ec-copy="ec-pre-remove">Copy</button>
      </div>
      <details class="ec-detail">
        <summary>Where do people go after they submit?</summary>
        <div class="ec-detail-body">
          <p class="ec-note">
            Set <em>Redirect after Remove</em> and <em>Form URL &mdash; Remove</em> in the
            listing&rsquo;s <strong>Edit &rarr; Embed tab</strong>.
          </p>
        </div>
      </details>
    </div>
  </div>

  <!-- reset -->
  <div class="ec-panel" id="ec-panel-reset">
    <div class="ec-panel-head">
      <span class="ec-filename">embed/reset.php</span>
      <span class="ec-badge ec-badge-id">Requires listing ID</span>
      <span class="ec-panel-desc">Password reset — email entry and new-password form</span>
    </div>
    <div class="ec-panel-body">
      <p class="ec-paste-tip">Paste this into the PHP page where you want the password reset form to appear.</p>
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-reset"><?= $snip['reset'] ?></pre>
        <button class="ec-copy" data-ec-copy="ec-pre-reset">Copy</button>
      </div>
      <details class="ec-detail">
        <summary>Two-step reset — splitting across two pages</summary>
        <div class="ec-detail-body">
          <p style="font-size:.8rem;color:var(--text-muted);margin:0 0 .75rem">
            The reset flow has two steps: first they enter their email, then they set a new password.
            By default, both steps show on the same page. If you want to put them on separate pages,
            use the variable below on your step-2 page.
          </p>
          <div class="ec-vars-list">
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_reset_step</span>
              <span class="ec-var-desc">Set to <code>'confirm'</code> to show the new-password form (step 2) instead of the email entry form. Use this on a dedicated confirm page that the reset email links to.</span>
            </div>
          </div>
        </div>
      </details>
      <details class="ec-detail">
        <summary>Where do people go after they submit?</summary>
        <div class="ec-detail-body">
          <p class="ec-note">
            Set <em>Redirect after Password Reset</em> and <em>Form URL &mdash; Reset</em> in the
            listing&rsquo;s <strong>Edit &rarr; Embed tab</strong>.
          </p>
        </div>
      </details>
    </div>
  </div>

  <!-- affiliates (per-listing) -->
  <div class="ec-panel" id="ec-panel-affiliates">
    <div class="ec-panel-head">
      <span class="ec-filename">embed/affiliates.php</span>
      <span class="ec-badge ec-badge-id">Requires listing ID</span>
      <span class="ec-panel-desc">Affiliates for this fanlisting</span>
    </div>
    <div class="ec-panel-body">
      <p class="ec-paste-tip">Paste this into the PHP page where you want your affiliates to appear.</p>
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-affiliates"><?= $snip['affiliates'] ?></pre>
        <button class="ec-copy" data-ec-copy="ec-pre-affiliates">Copy</button>
      </div>
      <details class="ec-detail">
        <summary>Optional customization</summary>
        <div class="ec-detail-body">
          <div class="ec-vars-list">
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_affiliates_base</span>
              <span class="ec-var-desc">The full URL of the page where you put this embed. Keeps pagination links pointing back to your page. Example: <code>'https://yoursite.com/affiliates.php'</code></span>
            </div>
          </div>
        </div>
      </details>
    </div>
  </div>

  <!-- owned -->
  <div class="ec-panel" id="ec-panel-owned">
    <div class="ec-panel-head">
      <span class="ec-filename">embed/owned.php</span>
      <span class="ec-badge ec-badge-free">No listing ID needed</span>
      <span class="ec-panel-desc">All your owned fanlistings with optional category filter</span>
    </div>
    <div class="ec-panel-body">
      <p class="ec-paste-tip">Paste this into the PHP page where you want your owned listings to appear.</p>
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-owned"><?= $snip['owned'] ?></pre>
        <button class="ec-copy" data-ec-copy="ec-pre-owned">Copy</button>
      </div>
      <details class="ec-detail">
        <summary>Optional customization</summary>
        <div class="ec-detail-body">
          <div class="ec-vars-list">
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_status</span>
              <span class="ec-var-desc">Which listings to show. Use <code>'current'</code>, <code>'upcoming'</code>, <code>'trouble'</code>, or <code>'all'</code>. Defaults to current listings only.</span>
            </div>
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_hide_dropdown</span>
              <span class="ec-var-desc">Set to <code>true</code> to hide the category filter dropdown entirely.</span>
            </div>
          </div>
        </div>
      </details>
    </div>
  </div>

  <!-- joined -->
  <div class="ec-panel" id="ec-panel-joined">
    <div class="ec-panel-head">
      <span class="ec-filename">embed/joined.php</span>
      <span class="ec-badge ec-badge-free">No listing ID needed</span>
      <span class="ec-panel-desc">Fanlistings you&rsquo;ve joined, grouped by category</span>
    </div>
    <div class="ec-panel-body">
      <p class="ec-paste-tip">Paste this into the PHP page where you want your joined listings to appear.</p>
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-joined"><?= $snip['joined'] ?></pre>
        <button class="ec-copy" data-ec-copy="ec-pre-joined">Copy</button>
      </div>
      <details class="ec-detail">
        <summary>Optional customization</summary>
        <div class="ec-detail-body">
          <div class="ec-vars-list">
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_category</span>
              <span class="ec-var-desc">Controls what gets shown. Leave empty (<code>''</code>) to show a category dropdown. Set to <code>'all'</code> to show everything grouped by category. Or use an exact category name to show only that one category.</span>
            </div>
          </div>
        </div>
      </details>
    </div>
  </div>

  <!-- collective-stats -->
  <div class="ec-panel" id="ec-panel-cstats">
    <div class="ec-panel-head">
      <span class="ec-filename">embed/collective-stats.php</span>
      <span class="ec-badge ec-badge-free">No listing ID needed</span>
      <span class="ec-panel-desc">Loads site-wide stats into variables you can use anywhere on your page</span>
    </div>
    <div class="ec-panel-body">
      <p class="ec-paste-tip">Paste this near the top of any PHP page to load site-wide stats into variables you can use anywhere on that page.</p>
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-cstats"><?= $snip['cstats'] ?></pre>
        <button class="ec-copy" data-ec-copy="ec-pre-cstats">Copy</button>
      </div>
      <details class="ec-detail">
        <summary>All available variables</summary>
        <div class="ec-detail-body">
          <div class="ec-vars-list">
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_owned_active</span>
              <span class="ec-var-desc">How many of your fanlistings are currently active.</span>
            </div>
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_owned_upcoming</span>
              <span class="ec-var-desc">How many of your fanlistings are upcoming (not open yet).</span>
            </div>
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_owned_newest</span>
              <span class="ec-var-desc">An array of your most recently added listings. Use <code>$luster_owned_newest[0]->name</code>, <code>->url</code>, or <code>->subject</code> to get details about the newest one.</span>
            </div>
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_owned_random</span>
              <span class="ec-var-desc">One randomly picked active listing. Use <code>->name</code> or <code>->url</code> to display it — great for a &ldquo;random listing&rdquo; link.</span>
            </div>
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_members_total</span>
              <span class="ec-var-desc">Total number of approved members across all your fanlistings combined.</span>
            </div>
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_joined_active</span>
              <span class="ec-var-desc">How many fanlistings you&rsquo;ve joined that are marked active.</span>
            </div>
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_joined_newest</span>
              <span class="ec-var-desc">An array of the fanlistings you joined most recently. Use <code>$luster_joined_newest[0]->name</code> or <code>->url</code>.</span>
            </div>
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_affiliates</span>
              <span class="ec-var-desc">The total number of active collective-level affiliates.</span>
            </div>
          </div>
        </div>
      </details>
    </div>
  </div>

  <!-- affiliates-collective (separate panel, same file) -->
  <div class="ec-panel" id="ec-panel-affiliates-collective">
    <div class="ec-panel-head">
      <span class="ec-filename">embed/affiliates.php</span>
      <span class="ec-badge ec-badge-free">Use ID 0 for site-wide</span>
      <span class="ec-panel-desc">Affiliates for your whole collective</span>
    </div>
    <div class="ec-panel-body">
      <p class="ec-paste-tip">Paste this into the PHP page where you want your collective affiliates to appear.</p>
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-affiliates-collective"><?= htmlspecialchars(
          '<?php' . "\n" .
          $div . "\n" .
          '$luster_fanlisting_id = 0;' . "\n" .
          $div . "\n" .
          'require(\'' . $ep . '/affiliates.php\');' . "\n" .
          $div . "\n" .
          '?>',
          ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
        ) ?></pre>
        <button class="ec-copy" data-ec-copy="ec-pre-affiliates-collective">Copy</button>
      </div>
      <details class="ec-detail">
        <summary>Optional customization</summary>
        <div class="ec-detail-body">
          <div class="ec-vars-list">
            <div class="ec-var-row">
              <span class="ec-var-name">$luster_affiliates_base</span>
              <span class="ec-var-desc">The full URL of the page where you put this embed. Keeps pagination links pointing back to your page. Example: <code>'https://yoursite.com/affiliates.php'</code></span>
            </div>
          </div>
        </div>
      </details>
    </div>
  </div>

  <!-- kim -->
  <div class="ec-panel" id="ec-panel-kim">
    <div class="ec-panel-head">
      <span class="ec-filename">embed/kim-signup.php</span>
      <span class="ec-badge ec-badge-free">No listing ID needed</span>
      <span class="ec-panel-desc">KIM List sign-up form &mdash; lets visitors join your KIM mailing list</span>
    </div>
    <div class="ec-panel-body">
      <p class="ec-paste-tip">Paste this into the PHP page where you want the KIM sign-up form to appear.</p>
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-kim"><?= $snip['kim'] ?></pre>
        <button class="ec-copy" data-ec-copy="ec-pre-kim">Copy</button>
      </div>
      <details class="ec-detail">
        <summary>Before you use this</summary>
        <div class="ec-detail-body">
          <p class="ec-note">
            The KIM List add-on must be installed and active for this embed to work. If it&rsquo;s not
            installed, the embed will show a short error message instead of the form. You can install
            KIM from the <strong>Add-ons</strong> page in your admin panel.
          </p>
        </div>
      </details>
    </div>
  </div>

</div><!-- /.ec-panel-wrap -->
