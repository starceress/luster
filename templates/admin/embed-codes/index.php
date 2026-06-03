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

// ── icon + badge maps ─────────────────────────────────────────────────────
$iconMap = [
    'join'         => 'add',
    'members'      => 'members',
    'stats'        => 'sparkles',
    'affiliates'   => 'link',
    'aff-apply'    => 'link',
    'update'       => 'notes',
    'remove'       => 'signout',
    'reset'        => 'gear',
    'codes'        => 'embed',
    'codes-submit' => 'image',
    'contact'      => 'email',
    'updates'      => 'updates',
    'adopt'        => 'adoptions',
    'aff-apply'    => 'link',
    'owned'        => 'star',
    'joined'       => 'heart',
    'cstats'       => 'sparkles',
    'aff-col'      => 'link',
    'col-aff-apply'=> 'link',
    'random'       => 'wand',
    'latest'       => 'updates',
    'kim'          => 'kim',
    'kim-list'     => 'kim',
    'kim-update'   => 'kim',
    'kim-reset'    => 'kim',
    'col-updates'  => 'updates',
    'col-contact'  => 'email',
    'wishlist'     => 'wishlist',
];
$badgeMeta = [
    'form' => ['cls' => 'badge-info-pink', 'label' => 'Form'],
    'data' => ['cls' => 'badge-info',      'label' => 'Data'],
    'stat' => ['cls' => 'badge-info-gold', 'label' => 'Stats'],
    'ext'  => ['cls' => 'badge-upcoming',  'label' => 'Add-on'],
];

// ── item metadata ─────────────────────────────────────────────────────────
$items = [
    'join' => [
        'label' => 'Join Form',
        'badge' => 'Form',
        'file'  => 'join.php',
        'desc'  => 'Embeds the join form where fans can submit their information. Extra fields also appear here.',
    ],
    'members' => [
        'label' => 'Members List',
        'badge' => 'Data',
        'file'  => 'members.php',
        'desc'  => 'Paginated list of approved members, sorted by Name or Country.  Edit the the template\'s format in Edit Listing → Templates.',
    ],
    'stats' => [
        'label' => 'Statistics',
        'badge' => 'Stats',
        'file'  => 'stats.php',
        'desc'  => 'Listing statistics with live data — member count, dates, newest member, and etc. Edit the the template\'s format in Edit Listing → Templates.',
    ],
    'affiliates' => [
        'label' => 'Affiliates',
        'badge' => 'Data',
        'file'  => 'affiliates.php',
        'desc'  => 'Displays the listing\'s affiliates. Edit the the template\'s format in Edit Listing → Templates.',
    ],
    'aff-apply' => [
        'label' => 'Affiliate Form',
        'badge' => 'Form',
        'file'  => 'affiliate-apply.php',
        'desc'  => 'Must be enabled for the listing under Edit Listing → Affiliates. A form where fans can submit affiliate applications. Applications are queued as pending and appear on the site after admin approval.',
    ],
    'update' => [
        'label' => 'Update Details',
        'badge' => 'Form',
        'file'  => 'update.php',
        'desc'  => 'Lets existing members update their name, URL, and other details. Fields are the same as the join form, so extra fields appear here too.',
    ],
    'remove' => [
        'label' => 'Remove Membership',
        'badge' => 'Form',
        'file'  => 'remove.php',
        'desc'  => 'Lets members remove themselves from the fanlisting. Members enter their email to confirm. The listing owner receives an email with the member\'s name and email, but no confirmation link is needed — removals are effective immediately to avoid broken links and orphaned data.',
    ],
    'reset' => [
        'label' => 'Password Reset',
        'badge' => 'Form',
        'file'  => 'reset.php',
        'desc'  => 'Two-step password reset — members enter their email, then receive a link to set a new password.',
    ],
    'codes' => [
        'label' => 'Codes',
        'badge' => 'Data',
        'file'  => 'codes.php',
        'desc'  => 'Displays graphic codes for the listing, grouped by size. Donated codes appear in a separate section with donor credit. Edit the template\'s format in Edit Listing → Templates.',
    ],
    'codes-submit' => [
        'label' => 'Codes Donation Form',
        'badge' => 'Form',
        'file'  => 'codes-submit.php',
        'desc'  => 'A form for fans to submit graphic codes. Submitted codes are queued as pending and appear on the site after admin approval. Edit the template\'s format in Edit Listing → Templates.',
    ],
    'contact' => [
        'label' => 'Contact Form',
        'badge' => 'Form',
        'file'  => 'contact.php',
        'desc'  => 'A contact form for this listing. Messages are sent to the listing owner\'s email address. ',
    ],
    'updates' => [
        'label' => 'Updates Feed',
        'badge' => 'Addon',
        'file'  => 'updates.php',
        'desc'  => 'Displays the updates feed for this listing. Requires the Updates Feed add-on to be installed.',
    ],
    'adopt' => [
        'label' => 'Adoption Form',
        'badge' => 'Addon',
        'file'  => 'adopt.php',
        'desc'  => 'An adoption application form for this listing. Requires the Adoption add-on to be installed and the listing to have adoption enabled.',
    ],
    'aff-apply' => [
        'label' => 'Affiliate Form',
        'badge' => 'Form',
        'file'  => 'affiliate-apply.php',
        'desc'  => 'Application form for sites wanting to affiliate with this listing. Enable applications in Admin → Listings → Edit → Affiliates.',
    ],
    'owned' => [
        'label' => 'Owned Listings',
        'badge' => 'Data',
        'file'  => 'owned.php',
        'desc'  => 'Displays owned fanlistings. Enable browse-by-category mode to show a category index first; clicking a category then lists its listings. Edit the templates\' formats in Admin → Collective.',
    ],
    'joined' => [
        'label' => 'Joined Listings',
        'badge' => 'Data',
        'file'  => 'joined.php',
        'desc'  => 'Lists the fanlistings you have joined. Enable browse-by-category mode to show a category index first. Edit the templates\' formats in Admin → Collective.',
    ],
    'cstats' => [
        'label' => 'Site Statistics',
        'badge' => 'Stats',
        'file'  => 'collective-stats.php',
        'desc'  => 'Collective site-wide statistics with live data — owned count, total members, and more. No template needed: use the variables directly on your page. See the variable reference tab for details.',
    ],
    'aff-col' => [
        'label' => 'Affiliates',
        'badge' => 'Data',
        'file'  => 'affiliates.php',
        'desc'  => 'Displays your site-wide collective affiliates (not tied to any single listing). Edit the the template\'s format in Admin → Collective.',
    ],
    'col-aff-apply' => [
        'label' => 'Affiliate Form',
        'badge' => 'Form',
        'file'  => 'affiliate-apply.php',
        'desc'  => 'A site-wide affiliate application form. Submitted applications are queued as collective affiliate applications and reviewed in Admin → Affiliates → Pending.',
    ],
    'random' => [
        'label' => 'Random Listing',
        'badge' => 'Data',
        'file'  => 'random-listing.php',
        'desc'  => 'Picks one active listing at random and renders it using the owned-listings row template from Admin → Collective.',
    ],
    'latest' => [
        'label' => 'Latest Listing',
        'badge' => 'Data',
        'file'  => 'latest-listing.php',
        'desc'  => 'Renders the most recently opened active listing using the owned-listings row template from Admin → Collective.',
    ],
    'kim' => [
        'label' => 'KIM Form',
        'badge' => 'Addon',
        'file'  => 'kim-signup.php',
        'desc'  => 'Embeds the KIM List sign-up form. The KIM List add-on must be installed and active.',
    ],
    'kim-list' => [
        'label' => 'KIM List',
        'badge' => 'Addon',
        'file'  => 'kim-list.php',
        'desc'  => 'Displays the public KIM List — people waiting to hear about available fanlistings.',
    ],
    'kim-update' => [
        'label' => 'KIM Update Details',
        'badge' => 'Addon',
        'file'  => 'kim-update.php',
        'desc'  => 'Form for KIM members to update their name, URL, or email address.',
    ],
    'kim-reset' => [
        'label' => 'KIM Password Reset',
        'badge' => 'Addon',
        'file'  => 'kim-reset.php',
        'desc'  => 'Password reset form for KIM List members.',
    ],
    'col-codes' => [
        'label' => 'Codes',
        'badge' => 'Data',
        'file'  => 'codes.php',
        'desc'  => 'Displays collective-wide fan codes (not tied to a specific listing).',
    ],
    'col-codes-submit' => [
        'label' => 'Codes Donation Form',
        'badge' => 'Form',
        'file'  => 'codes-submit.php',
        'desc'  => 'Form for fans to submit collective codes. Submissions are reviewed in Admin → Codes.',
    ],
    'col-updates' => [
        'label' => 'Updates Feed',
        'badge' => 'Addon',
        'file'  => 'collective-updates.php',
        'desc'  => 'Displays the site-wide collective updates feed. Requires the Updates Feed add-on to be installed.',
    ],
    'col-contact' => [
        'label' => 'Contact Form',
        'badge' => 'Form',
        'file'  => 'collective-contact.php',
        'desc'  => 'A contact form for the collective. Messages are sent to the site owner\'s email address.',
    ],
    'wishlist' => [
        'label' => 'Wishlist',
        'badge' => 'Addon',
        'file'  => 'wishlist.php',
        'desc'  => 'Displays the wishlist. Filter by type to show only wanted, top, granted, or upcoming entries. Requires the Wishlist add-on.',
    ],
];

// Augment each item with icon + badge data
foreach ($items as $k => &$item) {
    $iconName              = $iconMap[$k] ?? 'embed';
    $item['icon_html']     = View::icon($iconName, ['width' => 13, 'height' => 13]);
    $item['icon_html_lg']  = View::icon($iconName, ['width' => 19, 'height' => 19]);
    $bm                    = $badgeMeta[$item['badge']] ?? $badgeMeta['data'];
    $item['badge_cls']     = $bm['cls'];
    $item['badge_label']   = $bm['label'];
}
unset($item);

$itemsJson = json_encode($items, JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);

$listingsJson = json_encode(
    array_map(fn($fl) => ['id' => (int) $fl->id, 'name' => $fl->name, 'subject' => $fl->subject], $listings ?? []),
    JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG
);

$epSafe = View::e($ep);

?>

<div class="ec-root" id="ec-root"
     data-embed-path="<?= $epSafe ?>"
     data-listings='<?= $listingsJson ?>'
     data-items='<?= $itemsJson ?>'
     data-base-url="<?= $b ?>">

  <p>
    Add Luster forms and data to any PHP page on your site with a single snippet.
    The embed path is already filled in based on where Luster is installed on this server.
  </p>

  <div class="callout callout-info">
    <?= View::icon('notes') ?>
    <span><strong>Before you start:</strong> every snippet needs your <code>$luster_fanlisting_id</code> (the listing&rsquo;s integer ID, shown in the URL when you edit it). Collective embeds like owned listings and site stats don&rsquo;t need one.</span>
  </div>

  <div class="tabs-wrap" id="ec-tabs-wrap">
    <div class="tabs" id="ec-tabs" role="tablist" aria-label="Embed codes sections">
      <button class="tabs-btn active" data-ec-tab="main" role="tab"
              aria-selected="true" aria-controls="ec-panel-main">Embed Codes</button>
      <button class="tabs-btn"        data-ec-tab="ref"  role="tab"
              aria-selected="false" aria-controls="ec-panel-ref">Variable reference</button>
    </div>
  </div>

<!-- ──── EMBED PICKER: cascading selectors + content pane ─────────────────── -->
  <div class="tabs-panel active" id="ec-panel-main" role="tabpanel" style="margin-bottom:1.5rem;">

<!-- ──── CASCADING SELECTORS ────────────────────────────────────────────── -->
    <div class="ec-sel-bar">
      <div class="ec-sel-row">

        <div class="ec-sel-step">
          <div class="wizard-step-node wizard-step-node--active">
            <div class="wizard-step-bubble">1</div>
            <span class="wizard-step-label"
                  data-step-sub="Use this on your collective or one listing.">Where It Goes</span>
          </div>
          <div class="select-wrap" style="margin-top:.5rem;">
            <select id="ec-sec-sel">
              <option value="">&mdash; Choose a Type &mdash;</option>
              <option value="c">Collective</option>
              <option value="l">Listings</option>
            </select>
          </div>
        </div>

        <span class="ec-sel-arrow" aria-hidden="true">&rarr;</span>

        <div class="ec-sel-step">
          <div class="wizard-step-node wizard-step-node--active">
            <div class="wizard-step-bubble">2</div>
            <span class="wizard-step-label"
                  data-step-sub="Choose the section you want to show.">What to Embed</span>
          </div>
          <div class="select-wrap" style="margin-top:.5rem;">
            <select id="ec-emb-sel" disabled>
              <option value="">&mdash; Choose a Section &mdash;</option>
            </select>
          </div>
        </div>

        <button type="button" class="btn btn-quaternary" id="ec-reset-btn">Reset</button>

      </div>
    </div>

<!-- ──── CONTENT PANE ────────────────────────────────────────────────────── -->
    <div class="gradient-card" id="ec-pane" style="margin-top:1.5rem;">

      <div id="ec-empty" class="empty">
        <div class="empty-icon"><?= View::icon('embed', ['width' => 32, 'height' => 32]) ?></div>
        <div class="empty-title">Use the dropdowns above to get started.</div>
        <div>Choose a type, either Listing or Collective (Step 1), and then the section you want to embed (Step 2).</div>
      </div>

      <!-- Header: icon + title + badge -->
      <div class="pc-head" id="ec-pane-head" style="display:none">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('embed') ?></div>
        <div class="pc-head-meta" style="flex:1;min-width:0">
          <div class="pc-head-title" id="ec-head-title"></div>
          <div class="pc-head-sub" id="ec-head-desc"></div>
        </div>
        <span id="ec-head-badge" class="pc-head-badge"></span>
      </div>

      <!-- Body -->
      <div class="pc-section" id="ec-pane-body" style="display:none;flex-direction:column;gap:1rem">

        <!-- Code block -->
        <div class="ec-code-wrap">
          <div class="ec-code-toolbar">
            <span class="ec-code-file">
              <?= View::icon('embed', ['width' => 12, 'height' => 12]) ?>
              <span id="ec-code-file">embed/join.php</span>
            </span>
            <div style="display:flex;align-items:center;gap:.5rem">
              <span class="badge badge-info" id="ec-opts-badge" style="display:none"></span>
              <button id="ec-prev-btn" class="btn btn-preview btn-xs" style="display:none"><?= View::icon('eye-on') ?> Preview</button>
              <button class="btn btn-primary btn-xs" id="ec-code-cp">Copy</button>
            </div>
          </div>
          <pre class="ec-pre" id="ec-code-pre"></pre>
        </div>

        <!-- Options card -->
          <div class="pc-section-label">Customize</div>
          <div class="ec-opts-body" id="ec-opts-body"></div>

      </div>
    </div>
  </div>

<!-- ──── VARIABLE REFERENCE ──────────────────────────────────────────────── -->
  <div class="tabs-panel" id="ec-panel-ref" role="tabpanel">

    <p class="text-muted embed-ref-intro">
      Variables are PHP variables you set <em>before</em> the <code>require</code> line.
      Tokens like <code>{stat_approved}</code> go inside your stats or member row template strings
      &mdash; Luster replaces them with live data when the page loads.
    </p>

    <div class="ec-ref-layout">
<!-- ──── REF NAV ──────────────────────────────────────────────────────────── -->
      <nav class="ec-ref-nav" aria-label="Reference sections">
        <span class="ec-ref-nav-group">Embed variables</span>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-general">General</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-members">Members &amp; reset</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-listings">Listings display</button>
        <span class="ec-ref-nav-group">Template tokens</span>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-stat-tokens">Stats</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-row-tokens">Member row</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-owned-tokens">Owned listings</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-joined-tokens">Joined listings</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-aff-tokens">Affiliates</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-cstat-tokens">Collective stats</button>
        <span class="ec-ref-nav-group">Collective stats</span>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-cstat-vars">Output variables</button>
      </nav>
<!-- ──── REF PANELS ───────────────────────────────────────────────────────── -->
      <div>
        <div class="gradient-card" id="ec-ref-general">
          <div class="pc-head">
            <div class="pc-head-meta">
          <div class="pc-head-title">General variables</div>
          <div class="pc-head-sub">Set these PHP variables <em>before</em> the <code>require</code> line. They apply to most embeds.</div>
</div></div>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Variable</th><th>Description</th></tr></thead>
              <tbody>
                <tr>
                  <td class="act-col"><code>$luster_fanlisting_id</code></td>
                  <td>Integer ID of the listing to embed. Find it in the URL when editing a listing (e.g. <samp>/admin/listings/3/edit</samp> &rarr; ID is <samp>3</samp>). Use <samp>0</samp> for collective-level affiliates.</td>
                </tr>
                <tr>
                  <td class="act-col"><code>$luster_return</code></td>
                  <td>When <code>true</code>, the embed returns its HTML string instead of echoing it. Useful when you need to place the output in a variable.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="ec-ref-panel" id="ec-ref-members">
          <h3 class="pc-head-title embed-ref-heading">Members &amp; reset</h3>
          <p class="text-muted embed-ref-copy">Options for <code>embed/members.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Variable</th><th>Description</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_per_page</code></td><td>Members per page. Overrides the site-wide default from Settings.</td></tr>
                <tr><td class="act-col"><code>$luster_sort_by</code></td><td>Force a default sort: <code>'name'</code> or <code>'country'</code>. When set, the visitor's sort link clicks are overridden.</td></tr>
                <tr><td class="act-col"><code>$luster_show_search</code></td><td>Set to <code>false</code> to hide the sort and filter controls.</td></tr>
                <tr><td class="act-col"><code>$luster_members_base</code></td><td>The URL of the page where you're embedding the list. Keeps pagination and sort links pointing back to your page instead of the Luster install URL.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Options for <code>embed/reset.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Variable</th><th>Description</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_reset_step</code></td><td>By default both steps (email entry and new-password form) appear on the same page. Set to <code>'confirm'</code> to show only step 2 (new-password form) &mdash; useful if you host both steps on separate pages.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Options for <code>embed/update.php</code> and <code>embed/remove.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Variable</th><th>Description</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_lost_password_url</code></td><td>Override the &ldquo;Forgot your password?&rdquo; link URL. Useful when the embed is on a different page than the listing&rsquo;s own <code>reset.php</code>.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Options for <code>embed/stats.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Variable</th><th>Description</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_stats_template</code></td><td>Inline template string. When non-empty, overrides the stats template stored in the database for this listing.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Options for <code>embed/affiliates.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Variable</th><th>Description</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_affiliates_base</code></td><td>Base URL for pagination links &mdash; keeps visitors on your page instead of the Luster install URL.</td></tr>
                <tr><td class="act-col"><code>$luster_per_page</code></td><td>Affiliates per page. Default 50.</td></tr>
                <tr><td class="act-col"><code>$luster_page</code></td><td>Current page number. Defaults to <code>$_GET['page']</code>, then 1.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Options for <code>embed/updates.php</code> and <code>embed/collective-updates.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Variable</th><th>Description</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_per_page</code></td><td>Items per page. Defaults to the site-wide setting.</td></tr>
                <tr><td class="act-col"><code>$luster_show_cats</code></td><td>Set to <code>false</code> to hide the category tags on each update entry. Defaults to <code>true</code>.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Options for <code>embed/contact.php</code> and <code>embed/collective-contact.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Variable</th><th>Description</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_contact_action</code></td><td>Override the form&rsquo;s POST action URL. Useful when the form and its processor live at different paths.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <div class="ec-ref-panel" id="ec-ref-listings">
          <h3 class="pc-head-title embed-ref-heading">Listings display</h3>
          <p class="text-muted embed-ref-copy">Options for <code>embed/owned.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Variable</th><th>Description</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_status</code></td><td>Filter by status: <code>'current'</code>, <code>'upcoming'</code>, <code>'pending'</code>, or <code>'all'</code>. Defaults to <code>'current'</code> only.</td></tr>
                <tr><td class="act-col"><code>$luster_category_id</code></td><td>Pre-filter to a specific category by its integer ID. When <code>0</code> (default), reads <code>$_GET['cat']</code> automatically so the built-in selector works without extra PHP.</td></tr>
                <tr><td class="act-col"><code>$luster_hide_dropdown</code></td><td>Set to <code>true</code> to hide the category filter entirely. Useful when combining with <code>$luster_category_id</code> to show a single fixed category with no selector.</td></tr>
                <tr><td class="act-col"><code>$luster_show_list</code></td><td>Set to <code>true</code> to render categories as a bulleted link list instead of a <code>&lt;select&gt;</code> dropdown.</td></tr>
                <tr><td class="act-col"><code>$luster_browse_by_category</code></td><td>Set to <code>true</code> to enable browse-by-category mode. The landing view shows a category index with listing counts. Clicking a category loads its listings formatted with your HTML template from Admin &rarr; Collective.</td></tr>
                <tr><td class="act-col"><code>$luster_exclude_pending</code></td><td>When <code>true</code> (default) and <code>$luster_status</code> is <code>'all'</code>, pending listings are silently omitted. Has no effect for other status values.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Options for <code>embed/joined.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Variable</th><th>Description</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_category</code></td><td>Leave blank (<code>''</code>) for a dropdown selector. Set to <code>'all'</code> to show every listing grouped by category heading. Or enter an exact category name to show only that category's listings.</td></tr>
                <tr><td class="act-col"><code>$luster_show_list</code></td><td>Set to <code>true</code> to render the category selector as a bulleted link list instead of a <code>&lt;select&gt;</code> dropdown. Has no effect when <code>$luster_category</code> is <code>'all'</code> or a fixed name.</td></tr>
                <tr><td class="act-col"><code>$luster_show_all_by_default</code></td><td>Set to <code>true</code> to show the dropdown selector AND all listings grouped below it on the initial page load (no category needs to be selected first).</td></tr>
                <tr><td class="act-col"><code>$luster_browse_by_category</code></td><td>Set to <code>true</code> to enable browse-by-category mode. The landing view shows all category names as links. Clicking a category loads its listings formatted with your HTML template from Admin &rarr; Collective.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Options for <code>embed/random-listing.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Variable</th><th>Description</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_status</code></td><td>Pool to draw from: <code>'current'</code> (default), <code>'upcoming'</code>, or <code>'all'</code>.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Options for <code>embed/wishlist.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Variable</th><th>Description</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_wishlist_type</code></td><td>Filter by type: <code>'all'</code> (default), <code>'wanted'</code>, <code>'top'</code>, <code>'granted'</code>, or <code>'upcoming'</code>.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Options for the KIM List embeds (<code>kim-signup</code>, <code>kim-list</code>, <code>kim-update</code>, <code>kim-reset</code>).</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Variable</th><th>Description</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_kim_signup_url</code></td><td>Override the KIM sign-up page URL.</td></tr>
                <tr><td class="act-col"><code>$luster_kim_update_url</code></td><td>Override the KIM update-details page URL.</td></tr>
                <tr><td class="act-col"><code>$luster_kim_reset_url</code></td><td>Override the KIM password-reset page URL.</td></tr>
                <tr><td class="act-col"><code>$luster_kim_list_url</code></td><td>Override the KIM public-list page URL.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Input options for <code>embed/collective-stats.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Variable</th><th>Description</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_count</code></td><td>How many listings to fetch for the newest/random arrays. Default 3.</td></tr>
                <tr><td class="act-col"><code>$luster_cache_ttl</code></td><td>Stat cache lifetime in seconds. <code>0</code> (default) disables caching. Newest/random are always fetched fresh.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- STATS TEMPLATE TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-stat-tokens">
          <h3 class="pc-head-title embed-ref-heading">Stats template tokens</h3>
          <p class="text-muted embed-ref-copy">Use these in the stats template under <strong>Edit Listing &rarr; Templates</strong>. Rendered by <code>embed/stats.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Token</th><th>Output</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{stat_approved}</code></td><td>Approved member count. <span class="ec-ex">e.g. <em>33</em></span></td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_pending}</code></td><td>Members awaiting approval.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_opened}</code></td><td>Date the listing opened. <span class="ec-ex">e.g. <em>January 29th 2026</em></span></td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_updated}</code></td><td>Date of the most recently approved member.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_last_updated}</code></td><td>Admin-controlled &ldquo;last updated&rdquo; date from listing settings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_newmembers}</code></td><td>Comma-separated names of the 5 most recently approved members.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_growth}</code></td><td>Average members per day since opened (2 decimal places).</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_name}</code></td><td>Listing name.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_url}</code></td><td>Listing URL.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{approved_by}</code></td><td>Linked network name &mdash; &ldquo;The Fanlistings Network&rdquo; or &ldquo;The Anime Fanlistings Network&rdquo;. Empty string if not approved by either.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_approved_by}&hellip;{/if_approved_by}</code></td><td>Block shown only when <code>{approved_by}</code> is non-empty.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- MEMBER ROW TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-row-tokens">
          <h3 class="pc-head-title embed-ref-heading">Member row template tokens</h3>
          <p class="text-muted embed-ref-copy">Use these in the member row template under <strong>Edit Listing &rarr; Templates</strong>. Each token is replaced per-row by <code>embed/members.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Token</th><th>Output</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{fan_name}</code></td><td>Member's display name. <span class="ec-ex">e.g. <em>Alexia</em></span></td></tr>
                <tr><td class="act-col"><code class="ec-tk">{fan_url}</code></td><td>Member's website URL.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{fan_email}</code></td><td>Member's email address &mdash; visible on the page, use with care.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{fan_country}</code></td><td>Country name. <span class="ec-ex">e.g. <em>Philippines</em></span></td></tr>
                <tr><td class="act-col"><code class="ec-tk">{fan_<em>fieldname</em>}</code></td><td>Value of a custom additional field, where <em>fieldname</em> is the field&rsquo;s machine name as defined under <strong>Edit Listing &rarr; Fields</strong>. <span class="ec-ex">e.g. <code>{fan_favorite}</code></span></td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- OWNED LISTINGS TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-owned-tokens">
          <h3 class="pc-head-title embed-ref-heading">Owned listings tokens</h3>
          <p class="text-muted embed-ref-copy">Row template tokens for <strong>Admin &rarr; Collective &rarr; Owned Listings</strong>. Used by <code>embed/owned.php</code>, <code>embed/latest-listing.php</code>, and <code>embed/random-listing.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Token</th><th>Output</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{listing_name}</code></td><td>Listing name.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_url}</code></td><td>Listing URL.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_subject}</code></td><td>Subject / topic.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_status}</code></td><td>Status label: Current, Upcoming, or Pending.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_email}</code></td><td>Contact email address.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_type}</code></td><td>Listing type field.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_category}</code></td><td>Comma-separated category names.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_image}</code></td><td>Rendered <code>&lt;img&gt;</code> tag, or empty string when no image is set.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{date_opened}</code></td><td>Date the listing opened.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{date_updated}</code></td><td>Date last updated (admin-set).</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{member_count}</code></td><td>Approved member count. Triggers a DB query per listing only when present in the template.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{country_count}</code></td><td>Distinct countries represented. Same lazy-load behaviour as <code>{member_count}</code>.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{countries_count}</code></td><td>Alias for <code>{country_count}</code>.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_countries}&hellip;{/if_countries}</code></td><td>Block shown only when the country count is greater than zero.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Sections layout tokens &mdash; used when <code>$luster_status = 'all'</code> and a sections template is saved under <strong>Admin &rarr; Collective &rarr; Owned Listings &mdash; Sections Layout</strong>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Token</th><th>Output</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{current_content}</code></td><td>Rendered rows for current / active listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{upcoming_content}</code></td><td>Rendered rows for upcoming listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{pending_content}</code></td><td>Rendered rows for pending listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{current_count}</code></td><td>Number of current listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{upcoming_count}</code></td><td>Number of upcoming listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{pending_count}</code></td><td>Number of pending listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{total_count}</code></td><td>Total listings shown.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_current_listings}&hellip;{/if_current_listings}</code></td><td>Block shown only when current listings exist.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_upcoming_listings}&hellip;{/if_upcoming_listings}</code></td><td>Block shown only when upcoming listings exist.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_pending_listings}&hellip;{/if_pending_listings}</code></td><td>Block shown only when pending listings exist.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- JOINED LISTINGS TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-joined-tokens">
          <h3 class="pc-head-title embed-ref-heading">Joined listings tokens</h3>
          <p class="text-muted embed-ref-copy">Row template tokens for <strong>Admin &rarr; Collective &rarr; Joined Listings</strong>. Used by <code>embed/joined.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Token</th><th>Output</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{listing_name}</code></td><td>Listing name.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_url}</code></td><td>Listing URL.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_category}</code></td><td>Category name.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_image}</code></td><td>Rendered <code>&lt;img&gt;</code> tag, or empty string when no image is set.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{image}</code></td><td>Alias for <code>{listing_image}</code>.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_status}</code></td><td>Status text.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{date_joined}</code></td><td>Date joined.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Sections layout tokens &mdash; used when <code>$luster_category = 'all'</code> and a sections template is saved under <strong>Admin &rarr; Collective &rarr; Joined Listings &mdash; Sections Layout</strong>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Token</th><th>Output</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{current_content}</code></td><td>Rendered rows for all active joined listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{current_count}</code></td><td>Number of active listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{total_count}</code></td><td>Total active listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_current_listings}&hellip;{/if_current_listings}</code></td><td>Block shown only when active listings exist.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- AFFILIATES TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-aff-tokens">
          <h3 class="pc-head-title embed-ref-heading">Affiliates tokens</h3>
          <p class="text-muted embed-ref-copy">Row template tokens for <strong>Edit Listing &rarr; Templates</strong> (per-listing) or <strong>Admin &rarr; Collective &rarr; Affiliates</strong> (collective). Used by <code>embed/affiliates.php</code>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Token</th><th>Output</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{aff_name}</code></td><td>Affiliate site name.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{aff_url}</code></td><td>Affiliate URL.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{aff_image}</code></td><td>Image filename only, or empty string.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{aff_image_url}</code></td><td>Full image URL ready for use in <code>src</code>, or empty string.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{aff_image_tag}</code></td><td>Rendered <code>&lt;img&gt;</code> tag, or empty string when no image.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Section layout tokens &mdash; wrap the rendered rows in a full section. Saved under <strong>Edit Listing &rarr; Templates</strong> or <strong>Admin &rarr; Collective &rarr; Affiliates &mdash; Section Layout</strong>.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Token</th><th>Output</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{affiliates}</code></td><td>All rendered affiliate rows.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{affiliates_count}</code></td><td>Total active affiliate count.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{affiliates_list}</code></td><td>Comma-separated <code>&lt;a&gt;</code> link list of affiliate names.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_affiliates}&hellip;{/if_affiliates}</code></td><td>Block shown only when affiliate count &gt; 0.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_no_affiliates}&hellip;{/if_no_affiliates}</code></td><td>Block shown only when affiliate count = 0.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- COLLECTIVE STATS TEMPLATE TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-cstat-tokens">
          <h3 class="pc-head-title embed-ref-heading">Collective stats template tokens</h3>
          <p class="text-muted embed-ref-copy">Use these in the Statistics template under <strong>Admin &rarr; Collective &rarr; Statistics</strong>. Rendered by <code>embed/collective-stats.php</code> when a template is saved.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Token</th><th>Output</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{owned_active}</code></td><td>Active owned listing count.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_upcoming}</code></td><td>Upcoming owned listing count.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_pending}</code></td><td>Pending / inactive owned listing count.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{joined_active}</code></td><td>Active joined listing count.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{affiliates}</code></td><td>Active collective affiliate count.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{members_total}</code></td><td>Total members across all listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{members_approved}</code></td><td>Approved members across all listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{members_pending}</code></td><td>Pending members across all listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_newest_name}</code></td><td>Name of the newest active owned listing, or empty.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_newest_url}</code></td><td>URL of the newest active owned listing, or empty.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_random_name}</code></td><td>Name of a random active owned listing, or empty.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_random_url}</code></td><td>URL of a random active owned listing, or empty.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{countries_count}</code></td><td>Distinct countries across all approved members, collective-wide.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_countries}&hellip;{/if_countries}</code></td><td>Block shown only when the collective countries count is greater than zero.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- COLLECTIVE STATS OUTPUT VARIABLES -->
        <div class="ec-ref-panel" id="ec-ref-cstat-vars">
          <h3 class="pc-head-title embed-ref-heading">Collective stats output variables</h3>
          <p class="text-muted embed-ref-copy">After <code>require()</code>-ing <code>embed/collective-stats.php</code>, these PHP variables are set and ready to echo anywhere on your page. No template needed.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Variable</th><th>Output</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_owned</code></td><td>Total owned listings (all statuses).</td></tr>
                <tr><td class="act-col"><code>$luster_owned_active</code></td><td>Open / active owned listings.</td></tr>
                <tr><td class="act-col"><code>$luster_owned_upcoming</code></td><td>Upcoming owned listings.</td></tr>
                <tr><td class="act-col"><code>$luster_owned_pending</code></td><td>Pending / inactive owned listings.</td></tr>
                <tr><td class="act-col"><code>$luster_joined</code></td><td>Total joined listings.</td></tr>
                <tr><td class="act-col"><code>$luster_joined_active</code></td><td>Active joined listings.</td></tr>
                <tr><td class="act-col"><code>$luster_joined_upcoming</code></td><td>Upcoming joined listings.</td></tr>
                <tr><td class="act-col"><code>$luster_joined_pending</code></td><td>Pending joined listings.</td></tr>
                <tr><td class="act-col"><code>$luster_affiliates</code></td><td>Active collective affiliates.</td></tr>
                <tr><td class="act-col"><code>$luster_members_approved</code></td><td>Approved members across all listings.</td></tr>
                <tr><td class="act-col"><code>$luster_members_pending</code></td><td>Pending members across all listings.</td></tr>
                <tr><td class="act-col"><code>$luster_members_total</code></td><td>Total members (approved + pending) across all listings.</td></tr>
                <tr><td class="act-col"><code>$luster_owned_growth_rate</code></td><td>Active owned listings added per year (formatted string).</td></tr>
                <tr><td class="act-col"><code>$luster_joined_growth_rate</code></td><td>Active joined listings added per year (formatted string).</td></tr>
                <tr><td class="act-col"><code>$luster_members_growth_rate</code></td><td>Approved members added per day across all listings (formatted string).</td></tr>
                <tr><td class="act-col"><code>$luster_owned_newest</code></td><td>Array of newest N active owned listings (Listing objects). N set by <code>$luster_count</code>, default 3.</td></tr>
                <tr><td class="act-col"><code>$luster_joined_newest</code></td><td>Array of newest N active joined listings (JoinedListing objects).</td></tr>
                <tr><td class="act-col"><code>$luster_owned_random</code></td><td>One random active owned listing (Listing object), or <code>null</code>.</td></tr>
                <tr><td class="act-col"><code>$luster_joined_random</code></td><td>One random active joined listing (JoinedListing object), or <code>null</code>.</td></tr>
                <tr><td class="act-col"><code>$luster_categories</code></td><td>Array of <code>[id, name, source, count]</code> &mdash; active owned listings per category.</td></tr>
                <tr><td class="act-col"><code>$luster_total_cats</code></td><td>Alias of <code>count($luster_categories)</code>.</td></tr>
                <tr><td class="act-col"><code>$luster_owned_current</code></td><td>Alias of <code>$luster_owned_active</code>.</td></tr>
                <tr><td class="act-col"><code>$luster_collective_fans</code></td><td>Alias of <code>$luster_members_total</code>.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<!-- ──── EMBED PREVIEW MODAL ─────────────────────────────────────────────── -->
<div class="modal-backdrop is-hidden" id="ec-prev-modal" style="display:none">
  <div class="modal modal--preview">
    <div class="modal-header">
      <span class="modal-title" id="ec-prev-modal-title">Preview</span>
      <div style="display:flex;align-items:center;gap:.5rem">
        <a id="ec-prev-newtab" href="#" target="_blank" rel="noopener"
           class="btn btn-ghost btn-sm" style="text-decoration:none">&#x2197;&ensp;New tab</a>
        <button class="modal-close btn btn-ghost btn-sm">&#x2715;&ensp;Close</button>
      </div>
    </div>
    <div class="modal-body--iframe">
      <iframe id="ec-prev-iframe" src="" title="Embed preview"></iframe>
    </div>
  </div>
</div>
