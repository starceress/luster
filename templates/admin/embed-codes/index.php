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
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-join"><?= $snip['join'] ?></pre>
        <button class="ec-copy" onclick="ecCopy('ec-pre-join',this)">Copy</button>
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
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-members"><?= $snip['members'] ?></pre>
        <button class="ec-copy" onclick="ecCopy('ec-pre-members',this)">Copy</button>
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
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-stats"><?= $snip['stats'] ?></pre>
        <button class="ec-copy" onclick="ecCopy('ec-pre-stats',this)">Copy</button>
      </div>
      <details class="ec-detail">
        <summary>Placeholders you can use in your stats template</summary>
        <div class="ec-detail-body">
          <p style="font-size:.8rem;color:var(--text-muted);margin:0 0 .75rem">
            Write a template in the listing&rsquo;s <strong>Website Templates</strong> settings.
            Use any of these placeholders — they get replaced with live data when the page loads.
          </p>
          <div class="ec-token-grid">
            <span class="ec-token">$stat_approved$</span>
            <span class="ec-token">$stat_pending$</span>
            <span class="ec-token">$stat_newmembers$</span>
            <span class="ec-token">$stat_opened$</span>
            <span class="ec-token">$stat_updated$</span>
            <span class="ec-token">$stat_last_updated$</span>
            <span class="ec-token">$stat_growth$</span>
            <span class="ec-token">$listing_name$</span>
            <span class="ec-token">$listing_url$</span>
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
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-update"><?= $snip['update'] ?></pre>
        <button class="ec-copy" onclick="ecCopy('ec-pre-update',this)">Copy</button>
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
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-remove"><?= $snip['remove'] ?></pre>
        <button class="ec-copy" onclick="ecCopy('ec-pre-remove',this)">Copy</button>
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
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-reset"><?= $snip['reset'] ?></pre>
        <button class="ec-copy" onclick="ecCopy('ec-pre-reset',this)">Copy</button>
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
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-affiliates"><?= $snip['affiliates'] ?></pre>
        <button class="ec-copy" onclick="ecCopy('ec-pre-affiliates',this)">Copy</button>
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
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-owned"><?= $snip['owned'] ?></pre>
        <button class="ec-copy" onclick="ecCopy('ec-pre-owned',this)">Copy</button>
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
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-joined"><?= $snip['joined'] ?></pre>
        <button class="ec-copy" onclick="ecCopy('ec-pre-joined',this)">Copy</button>
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
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-cstats"><?= $snip['cstats'] ?></pre>
        <button class="ec-copy" onclick="ecCopy('ec-pre-cstats',this)">Copy</button>
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
        <button class="ec-copy" onclick="ecCopy('ec-pre-affiliates-collective',this)">Copy</button>
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
      <div class="ec-code-wrap">
        <pre class="ec-pre" id="ec-pre-kim"><?= $snip['kim'] ?></pre>
        <button class="ec-copy" onclick="ecCopy('ec-pre-kim',this)">Copy</button>
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

<script>
(function () {
  var currentTarget = null;

  function showPanel(target) {
    // Deactivate previous panel
    if (currentTarget) {
      var prev = document.getElementById('ec-panel-' + currentTarget);
      if (prev) prev.classList.remove('ec-panel--active');
    }
    // Activate new panel
    var next = document.getElementById('ec-panel-' + target);
    if (next) next.classList.add('ec-panel--active');
    currentTarget = target;

    // Update button states
    document.querySelectorAll('.ec-opt').forEach(function (btn) {
      btn.setAttribute('aria-selected', btn.dataset.target === target ? 'true' : 'false');
    });
  }

  // Wire up all option buttons
  document.querySelectorAll('.ec-opt').forEach(function (btn) {
    btn.addEventListener('click', function () {
      showPanel(btn.dataset.target);
    });
  });

  // Default: show join
  showPanel('join');
}());

function ecCopy(preId, btn) {
  var text = (document.getElementById(preId) || {}).textContent || '';
  if (navigator.clipboard) {
    navigator.clipboard.writeText(text).catch(function () {});
  } else {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }
  btn.textContent = 'Copied!';
  btn.classList.add('ec-copied');
  setTimeout(function () {
    btn.textContent = 'Copy';
    btn.classList.remove('ec-copied');
  }, 1800);
}
</script>
