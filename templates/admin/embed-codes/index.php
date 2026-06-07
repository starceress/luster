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
        'desc'  => 'Collective site-wide statistics with live data — owned count, total members, and more. See the Template Help tab for the stats you can show.',
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
        'desc'  => 'Shows one current listing at random using your owned listings template from Admin → Collective.',
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
    array_map(fn($fl) => ['id' => (int) $fl->id, 'name' => $fl->title, 'subject' => $fl->subject], $listings ?? []),
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
    <span><strong>Before you start:</strong> most listing snippets need your <code>$luster_fanlisting_id</code>. That is the listing ID number shown in the URL when you edit the listing. Collective snippets, like owned listings and site stats, do not need one.</span>
  </div>

  <div class="tabs-wrap" id="ec-tabs-wrap">
    <div class="tabs" id="ec-tabs" role="tablist" aria-label="Embed codes sections">
      <button class="tabs-btn active" data-ec-tab="main" role="tab"
              aria-selected="true" aria-controls="ec-panel-main">Embed Codes</button>
      <button class="tabs-btn"        data-ec-tab="ref"  role="tab"
              aria-selected="false" aria-controls="ec-panel-ref">Template Help</button>
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
      These settings let you change what the copied code shows on your site.
      Add them above the <code>require</code> line only when you need them.
      Words in <code>{braces}</code> go inside your templates; Luster swaps them for real listing,
      member, or stats information.
    </p>

    <div class="ec-ref-layout">
<!-- ──── REF NAV ──────────────────────────────────────────────────────────── -->
      <nav class="ec-ref-nav" aria-label="Reference sections">
        <span class="ec-ref-nav-group">Snippet settings</span>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-general">Basics</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-members">Members and passwords</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-listings">Listing pages</button>
        <span class="ec-ref-nav-group">Template words</span>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-stat-tokens">Listing stats</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-row-tokens">Member list</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-owned-tokens">Owned listings</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-joined-tokens">Joined listings</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-aff-tokens">Affiliates</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-cstat-tokens">Collective stats template</button>
        <span class="ec-ref-nav-group">Advanced</span>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-cstat-vars">Stats for custom PHP</button>
      </nav>
<!-- ──── REF PANELS ───────────────────────────────────────────────────────── -->
      <div>
        <div class="gradient-card" id="ec-ref-general">
          <div class="pc-head">
            <div class="pc-head-meta">
          <div class="pc-head-title">Basics</div>
          <div class="pc-head-sub">These are the basic settings used by most copied snippets.</div>
</div></div>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr>
                  <td class="act-col"><code>$luster_fanlisting_id</code></td>
                  <td>Tells Luster which listing this form or list is for. You can find the number in the listing edit page URL. For example, <samp>/admin/listings/3/edit</samp> means the listing ID is <samp>3</samp>.</td>
                </tr>
              </tbody>
            </table>
          </div>
          <details class="ec-ref-card ec-ref-advanced">
            <summary>Advanced settings</summary>
            <div class="ec-ref-advanced-body">
              <div class="table-wrap">
              <table class="data-table">
                <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
                <tbody>
                  <tr>
                    <td class="act-col"><code>$luster_return</code></td>
                    <td>Most users should leave this out. It is only for custom PHP pages where you want Luster to prepare the form or list first, then let your own code decide where to show it.</td>
                  </tr>
                </tbody>
              </table>
              </div>
            </div>
          </details>
        </div>

        <div class="ec-ref-panel" id="ec-ref-members">
          <h3 class="pc-head-title embed-ref-heading">Members and passwords</h3>
          <p class="text-muted embed-ref-copy">Settings for the members list.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_per_page</code></td><td>How many members to show before Luster adds a next-page link. Leave this out to use your normal Luster setting.</td></tr>
                <tr><td class="act-col"><code>$luster_sort_by</code></td><td>Choose how the member list starts: by name or by country. Leave this out if you want visitors to choose the order themselves.</td></tr>
                <tr><td class="act-col"><code>$luster_show_search</code></td><td>Set this to <code>false</code> if you want to hide the member search, sort, and filter tools.</td></tr>
                <tr><td class="act-col"><code>$luster_members_base</code></td><td>The page where your member list lives. Use this if page numbers or sort links are sending visitors to the wrong place.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Settings for password reset pages.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_reset_step</code></td><td>Most sites should leave this out. Use <code>'confirm'</code> only if you made a separate page where members enter their new password.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Settings for update and remove forms.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_lost_password_url</code></td><td>Changes where the &ldquo;Forgot your password?&rdquo; link goes. Use this if your password reset form is on a custom page.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Settings for listing stats.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_stats_template</code></td><td>A custom stats layout for this one snippet. Leave this out to use the stats layout saved in Luster.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Settings for affiliate lists.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_affiliates_base</code></td><td>The page where your affiliates list lives. Use this if page numbers are sending visitors to the wrong place.</td></tr>
                <tr><td class="act-col"><code>$luster_per_page</code></td><td>How many affiliates to show before Luster adds a next-page link. The default is 50.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <details class="ec-ref-card ec-ref-advanced">
            <summary>Advanced affiliate setting</summary>
            <div class="ec-ref-advanced-body">
              <div class="table-wrap">
              <table class="data-table">
                <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
                <tbody>
                  <tr><td class="act-col"><code>$luster_page</code></td><td>Chooses which page number to show first. Most users should leave this alone.</td></tr>
                </tbody>
              </table>
              </div>
            </div>
          </details>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Settings for updates feeds.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_per_page</code></td><td>How many updates to show before Luster adds a next-page link. Leave this out to use your normal Luster setting.</td></tr>
                <tr><td class="act-col"><code>$luster_show_cats</code></td><td>Set this to <code>false</code> if you do not want category labels shown on updates.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <details class="ec-ref-card ec-ref-advanced">
            <summary>Advanced contact form setting</summary>
            <div class="ec-ref-advanced-body">
              <div class="table-wrap">
              <table class="data-table">
                <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
                <tbody>
                  <tr><td class="act-col"><code>$luster_contact_action</code></td><td>Changes where the contact form sends the message after someone clicks submit. Most users should leave this out.</td></tr>
                </tbody>
              </table>
              </div>
            </div>
          </details>
        </div>

        <div class="ec-ref-panel" id="ec-ref-listings">
          <h3 class="pc-head-title embed-ref-heading">Listing pages</h3>
          <p class="text-muted embed-ref-copy">Settings for owned listings pages.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_status</code></td><td>Choose which owned listings to show: current, upcoming, pending, or all. Leave this out to show current listings only.</td></tr>
                <tr><td class="act-col"><code>$luster_category_id</code></td><td>Show only one category of owned listings. Most users can leave this out and let visitors choose a category on the page.</td></tr>
                <tr><td class="act-col"><code>$luster_hide_dropdown</code></td><td>Set this to <code>true</code> if you do not want the category dropdown to appear.</td></tr>
                <tr><td class="act-col"><code>$luster_show_list</code></td><td>Set this to <code>true</code> to show category links as a list instead of a dropdown.</td></tr>
                <tr><td class="act-col"><code>$luster_browse_by_category</code></td><td>Set this to <code>true</code> if you want visitors to choose a category first, then see the listings in that category.</td></tr>
                <tr><td class="act-col"><code>$luster_exclude_pending</code></td><td>When showing all statuses, this keeps pending listings hidden. Leave it alone unless you want pending listings to show too.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Settings for joined listings pages.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_category</code></td><td>Choose what joined listings to show. Leave it blank to let visitors choose a category. Use <code>'all'</code> to show everything grouped by category. Or type one category name to show only that category.</td></tr>
                <tr><td class="act-col"><code>$luster_show_list</code></td><td>Set this to <code>true</code> to show category links as a list instead of a dropdown. This only matters when visitors are choosing a category.</td></tr>
                <tr><td class="act-col"><code>$luster_show_all_by_default</code></td><td>Set this to <code>true</code> if you want all joined listings to show right away, while still letting visitors choose a category.</td></tr>
                <tr><td class="act-col"><code>$luster_browse_by_category</code></td><td>Set this to <code>true</code> if you want visitors to choose a category first, then see the joined listings in that category.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Setting for random listing.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_status</code></td><td>Choose which listings the random pick can come from: current, upcoming, or all.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Setting for wishlist pages.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_wishlist_type</code></td><td>Choose which wishlist entries to show: all, wanted, top, granted, or upcoming.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Settings for KIM List pages.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_kim_signup_url</code></td><td>Link to your KIM sign-up page. Use this if it is different from the one saved in Luster.</td></tr>
                <tr><td class="act-col"><code>$luster_kim_update_url</code></td><td>Link to your KIM update page. Use this if it is different from the one saved in Luster.</td></tr>
                <tr><td class="act-col"><code>$luster_kim_reset_url</code></td><td>Link to your KIM password reset page. Use this if it is different from the one saved in Luster.</td></tr>
                <tr><td class="act-col"><code>$luster_kim_list_url</code></td><td>Link to your public KIM list page. Use this if it is different from the one saved in Luster.</td></tr>
                <tr><td class="act-col"><code>$luster_kim_list_base</code></td><td><strong>kim-list.php only.</strong> URL of your KIM list host page. In overview mode, each fanlisting link becomes this URL + <code>?id=N</code> instead of routing through Luster. In subject mode, used as the &ldquo;Back to KIM List&rdquo; link.</td></tr>
                <tr><td class="act-col"><code>$luster_kim_subject_id</code></td><td><strong>kim-list.php only.</strong> When set to a fanlisting ID greater than 0, the embed renders the approved KIM members for that fanlisting (subject view) instead of the overview. Typically set from <code>$_GET[&apos;id&apos;]</code> on the host page.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Setting for collective stats.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_count</code></td><td>How many newest listings Luster should make available. The default is 3.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <details class="ec-ref-card ec-ref-advanced">
            <summary>Advanced stats setting</summary>
            <div class="ec-ref-advanced-body">
              <div class="table-wrap">
              <table class="data-table">
                <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
                <tbody>
                  <tr><td class="act-col"><code>$luster_cache_ttl</code></td><td>How long Luster should reuse the same counted stats before counting again. Use <code>0</code> if you always want fresh numbers.</td></tr>
                </tbody>
              </table>
              </div>
            </div>
          </details>
        </div>

        <!-- STATS TEMPLATE TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-stat-tokens">
          <h3 class="pc-head-title embed-ref-heading">Listing stats</h3>
          <p class="text-muted embed-ref-copy">Put these words in your stats template. Luster replaces each one with real listing information.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{stat_approved}</code></td><td>Number of approved members. <span class="ec-ex">e.g. <em>33</em></span></td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_pending}</code></td><td>Number of members waiting for approval.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_opened}</code></td><td>Date you opened the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_updated}</code></td><td>Date the newest approved member joined.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_last_updated}</code></td><td>The &ldquo;last updated&rdquo; date you set for the listing in Luster.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_newmembers}</code></td><td>Names of the 5 newest approved members.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_growth}</code></td><td>Average new members per day since the listing opened.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_title}</code></td><td>Name of the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_url}</code></td><td>Link to the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{approved_by}</code></td><td>The network that approved the listing, if one is saved.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_approved_by}&hellip;{/if_approved_by}</code></td><td>Shows the text inside only when the listing has an approved-by network saved.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- MEMBER ROW TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-row-tokens">
          <h3 class="pc-head-title embed-ref-heading">Member list</h3>
          <p class="text-muted embed-ref-copy">Put these words in your member list template. Luster fills them in for each member.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{fan_name}</code></td><td>Member&rsquo;s name. <span class="ec-ex">e.g. <em>Alexia</em></span></td></tr>
                <tr><td class="act-col"><code class="ec-tk">{fan_url}</code></td><td>Member&rsquo;s website link.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{fan_email}</code></td><td>Member&rsquo;s email address. Only use this if you really want emails visible on your site.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{fan_country}</code></td><td>Member&rsquo;s country.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{fan_<em>fieldname</em>}</code></td><td>A custom field answer from the member. Replace <em>fieldname</em> with your field name. <span class="ec-ex">e.g. <code>{fan_favorite}</code></span></td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- OWNED LISTINGS TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-owned-tokens">
          <h3 class="pc-head-title embed-ref-heading">Owned listings</h3>
          <p class="text-muted embed-ref-copy">Put these words in your owned listings template. Luster fills them in for each listing. The template is fully self-contained — it controls its own wrapper markup (e.g. <code>&lt;ul&gt;</code>/<code>&lt;li&gt;</code>).</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{each_listing}&hellip;{/each_listing}</code></td><td>Wrap your row markup in this. Luster repeats what&rsquo;s inside once per listing, as a single flat list. This is what the built-in default uses.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{each_current}&hellip;{/each_current}</code>, <code class="ec-tk">{each_upcoming}&hellip;{/each_upcoming}</code>, <code class="ec-tk">{each_pending}&hellip;{/each_pending}</code></td><td>Same idea, but split into separate Current / Upcoming / Pending sections — an alternative to <code>{each_listing}</code>, not an addition to it. This is the same template used by every owned-listing embed (<code>owned</code> at any status, <code>latest-listing</code>, <code>random-listing</code>), so a sectioned template renders its grouped layout everywhere, including single-listing widgets — best suited for an &ldquo;all&rdquo; overview page.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_title}</code></td><td>Name of the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_url}</code></td><td>Link to the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_subject}</code></td><td>What the listing is for.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_status}</code></td><td>Raw status word: <code>current</code>, <code>upcoming</code>, or <code>pending</code>.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_email}</code></td><td>Contact email for the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_type}</code></td><td>Listing type, if you filled one in.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_category}</code></td><td>The listing&rsquo;s categories.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_image_url}</code></td><td>Just the image&rsquo;s web address as plain text. Empty when there is no image.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_image_html}</code></td><td>A ready-to-use <code>&lt;img&gt;</code> tag for the listing image. Empty when there is no image.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{date_opened}</code></td><td>Date you opened the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{date_updated}</code></td><td>The &ldquo;last updated&rdquo; date you set for the listing in Luster.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{member_count}</code></td><td>Number of approved members.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{member_pending_count}</code></td><td>Number of members waiting for approval on this listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{country_count}</code></td><td>Number of different countries represented by approved members.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{countries_count}</code></td><td>Same number as <code>{country_count}</code>.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{approved_by}</code></td><td>Which network this listing is registered with: <code>tfl</code>, <code>tafl</code>, or empty.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_listing_image}&hellip;{/if_listing_image}</code></td><td>Shows the text inside only when the listing has an image.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_countries}&hellip;{/if_countries}</code></td><td>Shows the text inside only when at least one country is counted.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_pending}&hellip;{/if_pending}</code></td><td>Shows the text inside only when this listing has pending members.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_status_current}&hellip;{/if_status_current}</code>, <code class="ec-tk">{if_status_upcoming}&hellip;{/if_status_upcoming}</code>, <code class="ec-tk">{if_status_pending}&hellip;{/if_status_pending}</code></td><td>Shows the text inside only when the listing&rsquo;s status matches.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_tfl}&hellip;{/if_tfl}</code>, <code class="ec-tk">{if_tafl}&hellip;{/if_tafl}</code></td><td>Shows the text inside only when the listing is registered with that network.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_approved}&hellip;{/if_approved}</code></td><td>Shows the text inside only when the listing is registered with either network.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Section words — only useful inside <code>{each_current}</code> / <code>{each_upcoming}</code> / <code>{each_pending}</code> blocks, placed outside the loops in the same template.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{current_count}</code></td><td>Number of current listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{upcoming_count}</code></td><td>Number of upcoming listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{pending_count}</code></td><td>Number of pending listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{total_count}</code></td><td>Total listings shown.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_current_listings}&hellip;{/if_current_listings}</code></td><td>Shows the text inside only when there are current listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_upcoming_listings}&hellip;{/if_upcoming_listings}</code></td><td>Shows the text inside only when there are upcoming listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_pending_listings}&hellip;{/if_pending_listings}</code></td><td>Shows the text inside only when there are pending listings.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- JOINED LISTINGS TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-joined-tokens">
          <h3 class="pc-head-title embed-ref-heading">Joined listings</h3>
          <p class="text-muted embed-ref-copy">Put these words in your joined listings template. Luster fills them in for each listing. The template is fully self-contained — it controls its own wrapper markup (e.g. <code>&lt;ul&gt;</code>/<code>&lt;li&gt;</code>).</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{each_joined}&hellip;{/each_joined}</code></td><td>Wrap your row markup in this. Luster repeats what&rsquo;s inside once per joined listing (flat list).</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{each_current_joined}&hellip;{/each_current_joined}</code></td><td>Same idea, but renders the active joined-listing pool ordered by category — works whether the visitor is browsing all categories or one selected category; reads best for an all-categories overview.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_title}</code></td><td>Name of the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_url}</code></td><td>Link to the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_category}</code></td><td>Listing category.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_image_url}</code></td><td>Just the image&rsquo;s web address as plain text. Empty when there is no image.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_image_html}</code></td><td>A ready-to-use <code>&lt;img&gt;</code> tag for the listing image. Empty when there is no image.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_status}</code></td><td>Listing status.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{date_joined}</code></td><td>Date you joined the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_listing_image}&hellip;{/if_listing_image}</code></td><td>Shows the text inside only when the listing has an image.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Section words — only useful inside an <code>{each_current_joined}</code> block, placed outside the loop in the same template.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{current_count}</code></td><td>Number of active joined listings shown.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{total_count}</code></td><td>Total number of active joined listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_current_listings}&hellip;{/if_current_listings}</code></td><td>Shows the text inside only when there are active joined listings.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- AFFILIATES TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-aff-tokens">
          <h3 class="pc-head-title embed-ref-heading">Affiliates</h3>
          <p class="text-muted embed-ref-copy">Put these words in your affiliates template. Luster fills them in for each affiliate.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{aff_name}</code></td><td>Affiliate site&rsquo;s name.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{aff_url}</code></td><td>Affiliate site&rsquo;s link.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{aff_image}</code></td><td>Affiliate image filename. Most users should use <code>{aff_image_tag}</code> instead.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{aff_image_url}</code></td><td>Direct link to the affiliate image. Use this only if you are making your own image code.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{aff_image_tag}</code></td><td>The affiliate image. If there is no image, nothing is shown.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted embed-ref-copy" style="margin-top:.875rem">Section words for affiliate section layouts.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{affiliates}</code></td><td>All affiliate rows.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{affiliates_count}</code></td><td>Number of active affiliates.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{affiliates_list}</code></td><td>A simple linked list of affiliate names.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_affiliates}&hellip;{/if_affiliates}</code></td><td>Shows the text inside only when there are affiliates.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_no_affiliates}&hellip;{/if_no_affiliates}</code></td><td>Shows the text inside only when there are no affiliates.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- COLLECTIVE STATS TEMPLATE TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-cstat-tokens">
          <h3 class="pc-head-title embed-ref-heading">Collective stats template</h3>
          <p class="text-muted embed-ref-copy">Put these words in your collective stats template. Luster replaces them with your site-wide numbers.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{owned_active}</code></td><td>Number of current owned listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_upcoming}</code></td><td>Number of upcoming owned listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_pending}</code></td><td>Number of pending or inactive owned listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{joined_active}</code></td><td>Number of active joined listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{affiliates}</code></td><td>Number of active collective affiliates.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{members_total}</code></td><td>Total members across all your listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{members_approved}</code></td><td>Approved members across all your listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{members_pending}</code></td><td>Members waiting for approval across all your listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_newest_name}</code></td><td>Name of your newest current owned listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_newest_url}</code></td><td>Link to your newest current owned listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_random_name}</code></td><td>Name of one random current owned listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_random_url}</code></td><td>Link to one random current owned listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{countries_count}</code></td><td>Number of different countries represented by approved members across all your listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_countries}&hellip;{/if_countries}</code></td><td>Shows the text inside only when at least one country is counted.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- COLLECTIVE STATS OUTPUT VARIABLES -->
        <div class="ec-ref-panel" id="ec-ref-cstat-vars">
          <h3 class="pc-head-title embed-ref-heading">Stats for custom PHP</h3>
          <p class="text-muted embed-ref-copy">Advanced. These are meant for people who are comfortable editing PHP. Most users should use the collective stats template words instead.</p>
          <details class="ec-ref-card ec-ref-advanced">
            <summary>Show advanced PHP stats</summary>
            <div class="ec-ref-advanced-body">
              <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">PHP variable</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_owned</code></td><td>Total number of owned listings.</td></tr>
                <tr><td class="act-col"><code>$luster_owned_active</code></td><td>Number of current owned listings.</td></tr>
                <tr><td class="act-col"><code>$luster_owned_upcoming</code></td><td>Number of upcoming owned listings.</td></tr>
                <tr><td class="act-col"><code>$luster_owned_pending</code></td><td>Number of pending or inactive owned listings.</td></tr>
                <tr><td class="act-col"><code>$luster_joined</code></td><td>Total number of joined listings.</td></tr>
                <tr><td class="act-col"><code>$luster_joined_active</code></td><td>Number of active joined listings.</td></tr>
                <tr><td class="act-col"><code>$luster_joined_upcoming</code></td><td>Number of upcoming joined listings.</td></tr>
                <tr><td class="act-col"><code>$luster_joined_pending</code></td><td>Number of pending joined listings.</td></tr>
                <tr><td class="act-col"><code>$luster_affiliates</code></td><td>Number of active collective affiliates.</td></tr>
                <tr><td class="act-col"><code>$luster_members_approved</code></td><td>Approved members across all your listings.</td></tr>
                <tr><td class="act-col"><code>$luster_members_pending</code></td><td>Members waiting for approval across all your listings.</td></tr>
                <tr><td class="act-col"><code>$luster_members_total</code></td><td>Total members across all your listings, including pending members.</td></tr>
                <tr><td class="act-col"><code>$luster_owned_growth_rate</code></td><td>How many current owned listings you add per year, on average.</td></tr>
                <tr><td class="act-col"><code>$luster_joined_growth_rate</code></td><td>How many active joined listings you add per year, on average.</td></tr>
                <tr><td class="act-col"><code>$luster_members_growth_rate</code></td><td>How many approved members join per day, on average.</td></tr>
                <tr><td class="act-col"><code>$luster_owned_newest</code></td><td>A group of your newest current owned listings.</td></tr>
                <tr><td class="act-col"><code>$luster_joined_newest</code></td><td>A group of your newest active joined listings.</td></tr>
                <tr><td class="act-col"><code>$luster_owned_random</code></td><td>One random current owned listing.</td></tr>
                <tr><td class="act-col"><code>$luster_joined_random</code></td><td>One random active joined listing.</td></tr>
                <tr><td class="act-col"><code>$luster_categories</code></td><td>A group of your owned-listing categories, with a count for each one.</td></tr>
                <tr><td class="act-col"><code>$luster_total_cats</code></td><td>Number of owned-listing categories.</td></tr>
                <tr><td class="act-col"><code>$luster_owned_current</code></td><td>Same number as <code>$luster_owned_active</code>.</td></tr>
                <tr><td class="act-col"><code>$luster_collective_fans</code></td><td>Same number as <code>$luster_members_total</code>.</td></tr>
              </tbody>
            </table>
              </div>
            </div>
          </details>
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
