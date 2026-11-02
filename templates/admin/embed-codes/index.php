<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin embed codes wizard page. Generates ready-to-copy PHP embed code snippets
 for all Luster features, with per-listing selection, code previews, and
 customisation options for each embed type.
*/

declare(strict_types=1);

use Luster\Core\View;

$b  = rtrim(View::e($base_url ?? ''), '/');
$ep = rtrim($embed_path ?? '/path/to/luster/public/embed', '/');
$escapedEp     = str_replace(["\\", "'"], ["\\\\", "\\'"], $ep);
$setupLine1 = "<?php defined('LUSTER_EMBED') || define('LUSTER_EMBED', '" . $escapedEp . "'); require_once dirname(LUSTER_EMBED) . '/../vendor/autoload.php'; Luster\\Core\\View::serveEmbedAsset(); ?>";
$setupLine5 = '<link rel="stylesheet" href="?luster_css">';

// --- icon + badge maps
$iconMap = [
    'join'         => 'add',
    'members'      => 'members',
    'stats'        => 'sparkles',
    'affiliates'   => 'link',
    'aff-apply'    => 'link',
    'update'       => 'notes',
    'reset'        => 'gear',
    'codes'        => 'embed',
    'codes-submit' => 'image',
    'contact'      => 'email',
    'newsfeed'     => 'updates',
    'adopt'        => 'adoptions',
    'owned'        => 'star',
    'joined'       => 'heart',
    'cstats'       => 'sparkles',
    'aff-col'      => 'link',
    'col-aff-apply'=> 'link',
    'random'       => 'wand',
    'latest'       => 'updates',
    'random-joined'=> 'wand',
    'latest-joined'=> 'heart',
    'kim'          => 'kim',
    'kim-list'     => 'kim',
    'kim-update'   => 'kim',
    'kim-reset'    => 'kim',
    'col-codes'    => 'embed',
    'col-codes-submit' => 'image',
    'col-newsfeed' => 'updates',
    'col-contact'  => 'email',
    'col-update'   => 'notes',
    'wishlist'     => 'wishlist',
    'rules-gate'   => 'rules',
];
$badgeMeta = [
    'form' => ['cls' => 'badge-info2', 'label' => 'Form'],
    'data' => ['cls' => 'badge-info',      'label' => 'Data'],
    'stat' => ['cls' => 'badge-info3', 'label' => 'Stats'],
    'ext'  => ['cls' => 'badge-upcoming',  'label' => 'Add-on'],
];

// --- item metadata
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
        'desc'  => 'Paginated list of approved members, sorted by name, country, or newest joined. Edit the template in Edit Listing → Templates.',
    ],
    'stats' => [
        'label' => 'Statistics',
        'badge' => 'Stats',
        'file'  => 'stats.php',
        'desc'  => 'Listing statistics with live data — member count, dates, newest member, and more. Edit the template in Edit Listing → Templates.',
    ],
    'affiliates' => [
        'label' => 'Affiliates',
        'badge' => 'Data',
        'file'  => 'affiliates.php',
        'desc'  => 'Displays the listing\'s affiliates. Edit the template in Edit Listing → Templates.',
    ],
    'aff-apply' => [
        'label' => 'Affiliate Application',
        'badge' => 'Form',
        'file'  => 'affiliate-apply.php',
        'desc'  => 'Must be enabled for the listing under Edit Listing → Affiliates. A form where fans can submit affiliate applications. Applications are queued as pending and appear on the site after admin approval.',
    ],
    'update' => [
        'label' => 'Update Form',
        'badge' => 'Form',
        'file'  => 'update.php',
        'desc'  => 'Lets existing members update their name, URL, and other details. Fields are the same as the join form, so extra fields appear here too. Members can also check a box to permanently delete their membership instead — this takes effect immediately and emails the member a farewell message.',
    ],
    'reset' => [
        'label' => 'Password Reset Form',
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
        'label' => 'Code Donation Form',
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
    'newsfeed' => [
        'label' => 'News Feed',
        'badge' => 'Addon',
        'file'  => 'newsfeed.php',
        'desc'  => 'Displays the news feed for this listing. Requires the News Feed add-on to be installed.',
    ],
    'adopt' => [
        'label' => 'Adoption Form',
        'badge' => 'Addon',
        'file'  => 'adopt.php',
        'desc'  => 'An adoption application form. Visitors pick which fanlisting to apply for from a dropdown. Requires the Adoption add-on.',
    ],
    'rules-gate' => [
        'label' => 'Join Rules Gate',
        'badge' => 'Addon',
        'file'  => 'rules-gate.php',
        'desc'  => 'Shows the joining rules and an agreement checkbox. Place it before the join embed — if the fan has not agreed yet, render this and stop; if the return is empty, show the join form. Requires the Join Rules add-on to be installed and rules to be enabled for this listing.',
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
        'desc'  => 'Displays your site-wide collective affiliates, not tied to any single listing. Edit the template in Admin → Collective.',
    ],
    'col-aff-apply' => [
        'label' => 'Affiliate Application',
        'badge' => 'Form',
        'file'  => 'affiliate-apply.php',
        'desc'  => 'A site-wide affiliate application form. Submitted applications are queued as collective affiliate applications and reviewed in Admin → Affiliates → Pending.',
    ],
    'random' => [
        'label' => 'Random Listing',
        'badge' => 'Data',
        'file'  => 'random-listing.php',
        'desc'  => 'Shows one current listing at random using the Random Listing template from Admin &rarr; Collective &rarr; Templates.',
    ],
    'latest' => [
        'label' => 'Latest Listing',
        'badge' => 'Data',
        'file'  => 'latest-listing.php',
        'desc'  => 'Renders the most recently opened active listing using the Latest Listing template from Admin &rarr; Collective &rarr; Templates.',
    ],
    'random-joined' => [
        'label' => 'Random Joined Listing',
        'badge' => 'Data',
        'file'  => 'random-joined-listing.php',
        'desc'  => 'Shows one active, approved joined listing at random using the Random Joined Listing template from Admin &rarr; Collective &rarr; Templates.',
    ],
    'latest-joined' => [
        'label' => 'Latest Joined Listing',
        'badge' => 'Data',
        'file'  => 'latest-joined-listing.php',
        'desc'  => 'Renders the most recently joined active, approved listing using the Latest Joined Listing template from Admin &rarr; Collective &rarr; Templates.',
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
        'label' => 'Code Donation Form',
        'badge' => 'Form',
        'file'  => 'collective-codes-submit.php',
        'desc'  => 'Form for fans to submit collective codes. Submissions are reviewed in Admin → Codes.',
    ],
    'col-newsfeed' => [
        'label' => 'News Feed',
        'badge' => 'Addon',
        'file'  => 'collective-newsfeed.php',
        'desc'  => 'Displays the site-wide collective news feed. Requires the News Feed add-on to be installed.',
    ],
    'col-contact' => [
        'label' => 'Contact Form',
        'badge' => 'Form',
        'file'  => 'collective-contact.php',
        'desc'  => 'A contact form for the collective. Messages are sent to the site owner\'s email address.',
    ],
    'col-update' => [
        'label' => 'Collective Update Form',
        'badge' => 'Form',
        'file'  => 'collective-update.php',
        'desc'  => 'Lets a visitor enter their email to receive a verification link, then update their name, URL, and country across every fanlisting they belong to in one go. Does not require a listing ID — operates at the collective/site-wide level. Edit the email-request step\'s template in Admin → Collective → Templates.',
    ],
    'wishlist' => [
        'label' => 'Wishlist',
        'badge' => 'Addon',
        'file'  => 'wishlist.php',
        'desc'  => 'Displays the wishlist. Filter by type to show only wanted, top, or granted entries. Requires the Wishlist add-on.',
    ],
];

// Augment each item with icon + badge data
foreach ($items as $k => &$item) {
    $iconName              = $iconMap[$k] ?? 'embed';
    $item['icon_html']     = View::icon($iconName, ['width' => 13, 'height' => 13]);
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

<!-- ──── INTEGRATED WIZARD CARD ────────────────────────────────────────── -->
    <div class="card ec-wizard-card">

      <?php $setupAsWizardStep = true; include LUSTER_BASE . '/templates/admin/_embed-setup-snippet.php'; ?>

      <!-- ② + ③ Pickers -->
      <div class="ec-sel-pickers">
        <div class="ec-sel-row">

          <div class="ec-sel-step">
            <div class="wizard-step-node wizard-step-node--active" id="ec-step2-node">
              <div class="wizard-step-bubble">2</div>
              <span class="wizard-step-label"
                    data-step-sub="Collective or for your listings">Where It Goes</span>
            </div>
            <div class="select-wrap" style="margin-top:.5rem;">
              <select id="ec-sec-sel">
                <option value="">&mdash; Choose a Location &mdash;</option>
                <option value="c">Collective</option>
                <option value="l">Listings</option>
              </select>
            </div>
          </div>

          <span class="ec-sel-arrow" aria-hidden="true">&rarr;</span>

          <div class="ec-sel-step">
            <div class="wizard-step-node wizard-step-node--active" id="ec-step3-node">
              <div class="wizard-step-bubble">3</div>
              <span class="wizard-step-label"
                    data-step-sub="Choose the code snippet you want to copy.">What to Embed</span>
            </div>
            <div class="select-wrap" style="margin-top:.5rem;">
              <select id="ec-emb-sel" disabled>
                <option value="">&mdash; Choose a Snippet &mdash;</option>
              </select>
            </div>
          </div>

          <button type="button" class="btn btn-quaternary" id="ec-reset-btn">Reset</button>

        </div>
      </div>

    </div>

<!-- ──── CONTENT PANE ────────────────────────────────────────────────────── -->
    <div class="card card--gradient" id="ec-pane" style="margin-top:1.5rem;">

      <div id="ec-empty" class="empty">
        <div class="empty-icon"><?= View::icon('embed', ['width' => 32, 'height' => 32]) ?></div>
        <div><strong>Use the dropdowns above to get started.</strong></div>
        <div>Choose a Location (Collective or Listings) (Step 2), and then the code snippet you want to embed (Step 3).</div>
      </div>

      <!-- Header: icon + title + badge + actions -->
      <div class="panel-header" id="ec-pane-head" style="display:none">
        <div class="panel-header-icon"><?= View::icon('embed') ?></div>
        <div class="panel-header-meta" style="flex:1;min-width:0">
          <div class="panel-header-title" id="ec-head-title"></div>
          <div class="panel-header-sub" id="ec-head-desc"></div>
        </div>
        <span id="ec-head-badge" class="panel-header-badge"></span>
        <div class="panel-header-actions">
          <span class="badge badge-info" id="ec-opts-badge" style="display:none"></span>
          <button id="ec-prev-btn" class="btn btn-preview btn-xs" style="display:none"><?= View::icon('eye-on') ?> Preview</button>
          <button class="btn btn-primary btn-xs" id="ec-code-cp">Copy</button>
        </div>
      </div>

      <!-- Body -->
      <div class="card-body" id="ec-pane-body" style="display:none;flex-direction:column;gap:1rem">

        <!-- Code block -->
        <pre class="ec-pre" id="ec-code-pre"></pre>

        <!-- Options card -->
          <div class="section-label">Customize</div>
          <div class="ec-opts-body" id="ec-opts-body"></div>

      </div>
    </div>
  </div>

<!-- ──── VARIABLE REFERENCE ──────────────────────────────────────────────── -->
  <div class="tabs-panel" id="ec-panel-ref" role="tabpanel">

    <p class="text-muted">
      These settings let you change what the copied code shows on your site.
      Add them above the <code>require</code> line only when you need them.
      Words in <code>{braces}</code> go inside your templates; Luster swaps them for real listing,
      member, or stats information.
    </p>

    <div class="ref-jump-row">
      <span class="ref-jump-label">Jump to:</span>
      <a href="#ec-ref-general" class="ref-jump-pill" data-ref-jump="ec-ref-general">Basics</a>
      <a href="#ec-ref-members" class="ref-jump-pill" data-ref-jump="ec-ref-members">Members and passwords</a>
      <a href="#ec-ref-listings" class="ref-jump-pill" data-ref-jump="ec-ref-listings">Listing pages</a>
      <a href="#ec-ref-stat-tokens" class="ref-jump-pill" data-ref-jump="ec-ref-stat-tokens">Listing stats</a>
      <a href="#ec-ref-row-tokens" class="ref-jump-pill" data-ref-jump="ec-ref-row-tokens">Member list</a>
      <a href="#ec-ref-owned-tokens" class="ref-jump-pill" data-ref-jump="ec-ref-owned-tokens">Owned listings</a>
      <a href="#ec-ref-joined-tokens" class="ref-jump-pill" data-ref-jump="ec-ref-joined-tokens">Joined listings</a>
      <a href="#ec-ref-aff-tokens" class="ref-jump-pill" data-ref-jump="ec-ref-aff-tokens">Affiliates</a>
      <a href="#ec-ref-cstat-tokens" class="ref-jump-pill" data-ref-jump="ec-ref-cstat-tokens">Collective stats template</a>
      <a href="#ec-ref-pagination-tokens" class="ref-jump-pill" data-ref-jump="ec-ref-pagination-tokens">Pagination</a>
      <a href="#ec-ref-form-tokens" class="ref-jump-pill" data-ref-jump="ec-ref-form-tokens">Form fields</a>
      <a href="#ec-ref-success-tokens" class="ref-jump-pill" data-ref-jump="ec-ref-success-tokens">Success messages</a>
      <a href="#ec-ref-codes-tokens" class="ref-jump-pill" data-ref-jump="ec-ref-codes-tokens">Codes</a>
      <a href="#ec-ref-updates-tokens" class="ref-jump-pill" data-ref-jump="ec-ref-updates-tokens">Updates</a>
      <a href="#ec-ref-wishlist-tokens" class="ref-jump-pill" data-ref-jump="ec-ref-wishlist-tokens">Wishlist</a>
      <a href="#ec-ref-kim-tokens" class="ref-jump-pill" data-ref-jump="ec-ref-kim-tokens">KIM members</a>
      <a href="#ec-ref-cstat-vars" class="ref-jump-pill" data-ref-jump="ec-ref-cstat-vars">Stats for custom PHP</a>
    </div>

    <h2 class="ref-zone-head">Embed Settings</h2>
    <div class="ref-topic-list">
        <details class="card chevron topic-card" id="ec-ref-general">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('gear', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Basics<span class="chevron-head-sub">These are the basic settings used by most copied snippets.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                          <tbody>
                            <tr>
                              <td class="actions-col"><code>$luster_fanlisting_id</code></td>
                              <td>Tells Luster which listing this form or list is for. You can find the number in the listing edit page URL. For example, <samp>/admin/listings/3/edit</samp> means the listing ID is <samp>3</samp>.</td>
                            </tr>
                          </tbody>
                        </table>
                      </div>
                      <details class="form-details">
                        <summary>Advanced settings</summary>
                        <div class="form-details-body table-wrap">
                          <table class="data-table">
                            <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                            <tbody>
                              <tr>
                                <td class="actions-col"><code>$luster_return</code></td>
                                <td>Most users should leave this out. It is only for custom PHP pages where you want Luster to prepare the form or list first, then let your own code decide where to show it.</td>
                              </tr>
                            </tbody>
                          </table>
                          </div>
                      </details>
          </div>
        </details>
        <details class="card chevron topic-card" id="ec-ref-members">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('members', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Members and passwords<span class="chevron-head-sub">Settings for the members list, password reset, update, stats, and related forms.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="section-label">Member List</div>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>$luster_per_page</code></td><td>How many members to show before Luster adds a next-page link. The default is 25, or your per-listing/global Members Per Page setting if you've set one.</td></tr>
                            <tr><td class="actions-col"><code>$luster_sort_by</code></td><td>Choose how the member list starts: <code>'name'</code>, <code>'country'</code>, or <code>'added'</code> for newest joined first. Leave this out if you want visitors to choose the order themselves.</td></tr>
                            <tr><td class="actions-col"><code>$luster_show_search</code></td><td>Set this to <code>false</code> if you want to hide the member sort and country filter tools.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <div class="section-label" style="margin-top:1.5rem">Password Reset</div>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col" colspan="2">The embed auto-detects the reset step from the URL. If the URL has <code>?token=...</code>, it shows the new-password form.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <div class="section-label" style="margin-top:1.5rem">Update Form</div>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>$luster_lost_password_url</code></td><td>Changes where the &ldquo;Forgot your password?&rdquo; link goes. Use this if your password reset form is on a custom page.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <div class="section-label" style="margin-top:1.5rem">Listing Stats</div>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>$luster_stats_template</code></td><td>A custom stats layout for this one snippet. Leave this out to use the stats layout saved in Luster.</td></tr>
                          </tbody>
                        </table>
                        <p class="text-muted" style="margin-top:.5rem">Output variable available after the stats embed runs:</p>
                        <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Variable</th><th>What it contains</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>$luster_description</code></td><td>The listing description (pre-escaped, safe to echo directly with <code>&lt;?= $luster_description ?&gt;</code>). Set on the Edit Listing page.</td></tr>
                          </tbody>
                        </table>
                        </div>
            </div>
                      <div class="section-label" style="margin-top:1.5rem">Affiliate List</div>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>$luster_per_page</code></td><td>How many affiliates to show before Luster adds a next-page link. The default is 50.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <details class="form-details">
                        <summary>Advanced affiliate setting</summary>
                        <div class="form-details-body table-wrap">
                          <table class="data-table">
                            <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                            <tbody>
                              <tr><td class="actions-col"><code>$luster_page</code></td><td>Chooses which page number to show first. Most users should leave this alone.</td></tr>
                            </tbody>
                          </table>
                          </div>
                      </details>
                      <div class="section-label" style="margin-top:1.5rem">News Feed</div>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>$luster_per_page</code></td><td>How many updates to show before Luster adds a next-page link. The default is 20.</td></tr>
                            <tr><td class="actions-col"><code>$luster_show_cats</code></td><td>Set this to <code>false</code> if you do not want category labels shown on updates.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <div class="section-label" style="margin-top:1.5rem">Contact Form</div>
                      <details class="form-details">
                        <summary>Advanced contact form setting</summary>
                        <div class="form-details-body table-wrap">
                          <table class="data-table">
                            <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                            <tbody>
                              <tr><td class="actions-col"><code>$luster_contact_action</code></td><td>Changes where the contact form sends the message after someone clicks submit. Most users should leave this out.</td></tr>
                            </tbody>
                          </table>
                          </div>
                      </details>
          </div>
        </details>
        <details class="card chevron topic-card" id="ec-ref-listings">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('star', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Listing pages<span class="chevron-head-sub">Settings for owned listings, joined listings, random listing, wishlist, KIM List, and collective stats pages.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="section-label">Owned Listings</div>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>$luster_status</code></td><td>Choose which owned listings to show: <code>current</code>, <code>upcoming</code>, <code>pending</code>, <code>closed</code>, <code>adopted_out</code>, <code>past</code> (closed and adopted-out together), or <code>all</code>. Leave this out to show current listings only.</td></tr>
                            <tr><td class="actions-col"><code>$luster_category_id</code></td><td>Show only one category of owned listings. Most users can leave this out and let visitors choose a category on the page.</td></tr>
                            <tr><td class="actions-col"><code>$luster_hide_dropdown</code></td><td>Set this to <code>true</code> if you do not want the category dropdown to appear.</td></tr>
                            <tr><td class="actions-col"><code>$luster_browse_by_category</code></td><td>Set this to <code>true</code> if you want visitors to choose a category first, then see the listings in that category.</td></tr>
                            <tr><td class="actions-col"><code>$luster_exclude_pending</code></td><td>When showing all statuses, this keeps pending listings hidden. Leave it alone unless you want pending listings to show too.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <div class="section-label" style="margin-top:1.5rem">Joined Listings</div>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>$luster_category</code></td><td>Choose what joined listings to show. Leave it blank to let visitors choose a category. Use <code>'all'</code> to show everything grouped by category. Or type one category name to show only that category.</td></tr>
                            <tr><td class="actions-col"><code>$luster_show_all_by_default</code></td><td>Set this to <code>true</code> if you want all joined listings to show right away, while still letting visitors choose a category.</td></tr>
                            <tr><td class="actions-col"><code>$luster_browse_by_category</code></td><td>Set this to <code>true</code> if you want visitors to choose a category first, then see the joined listings in that category.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <div class="section-label" style="margin-top:1.5rem">Random Listing</div>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>$luster_status</code></td><td>Choose which listings the random pick can come from: current, upcoming, or all.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <div class="section-label" style="margin-top:1.5rem">Wishlist</div>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>$luster_wishlist_type</code></td><td>Choose which wishlist entries to show: all, wanted, top, or granted.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <div class="section-label" style="margin-top:1.5rem">KIM List</div>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>$luster_kim_signup_url</code></td><td>Link to your KIM sign-up page. Use this if it is different from the one saved in Luster.</td></tr>
                            <tr><td class="actions-col"><code>$luster_kim_update_url</code></td><td>Link to your KIM update page. Use this if it is different from the one saved in Luster.</td></tr>
                            <tr><td class="actions-col"><code>$luster_kim_reset_url</code></td><td>Link to your KIM password reset page. Use this if it is different from the one saved in Luster.</td></tr>
                            <tr><td class="actions-col"><code>$luster_kim_list_url</code></td><td>Link to your public KIM list page. Use this if it is different from the one saved in Luster.</td></tr>
                            <tr><td class="actions-col"><code>$luster_kim_list_base</code></td><td><strong>kim-list.php only.</strong> URL of your KIM list host page. In overview mode, each fanlisting link becomes this URL + <code>?id=N</code> instead of routing through Luster. In subject mode, used as the &ldquo;Back to KIM List&rdquo; link.</td></tr>
                            <tr><td class="actions-col"><code>$luster_kim_subject_id</code></td><td><strong>kim-list.php only.</strong> When set to a fanlisting ID greater than 0, the embed renders the approved KIM members for that fanlisting (subject view) instead of the overview. Typically set from <code>$_GET[&apos;id&apos;]</code> on the host page.</td></tr>
                            <tr><td class="actions-col"><code>$luster_kim_only_with_signups</code></td><td><strong>kim-list.php overview only.</strong> Defaults to <code>true</code>. Set it to <code>false</code> if the overview should show every eligible listing, even listings with no approved KIM sign-ups yet.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <div class="section-label" style="margin-top:1.5rem">Collective Stats</div>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>$luster_count</code></td><td>How many newest listings Luster should make available. The default is 3.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <details class="form-details">
                        <summary>Advanced stats setting</summary>
                        <div class="form-details-body table-wrap">
                          <table class="data-table">
                            <thead><tr><th class="actions-col">Setting</th><th>What it does</th></tr></thead>
                            <tbody>
                              <tr><td class="actions-col"><code>$luster_cache_ttl</code></td><td>How long Luster should reuse the same counted stats before counting again. Use <code>0</code> if you always want fresh numbers.</td></tr>
                            </tbody>
                          </table>
                          </div>
                      </details>
          </div>
        </details>
    </div>

    <h2 class="ref-zone-head">Embed Tokens</h2>
    <div class="ref-topic-list">
        <details class="card chevron topic-card" id="ec-ref-stat-tokens">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('sparkles', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Listing stats<span class="chevron-head-sub">Put these words in your stats template. Luster replaces each one with real listing information.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{stat_approved}</code></td><td>Number of approved members. <span class="text-dim ec-ex">e.g. <em>33</em></span></td></tr>
                            <tr><td class="actions-col"><code>{stat_pending}</code></td><td>Number of members waiting for approval.</td></tr>
                            <tr><td class="actions-col"><code>{stat_countries}</code></td><td>Number of different countries represented by approved members.</td></tr>
                            <tr><td class="actions-col"><code>{stat_opened}</code></td><td>Date you opened the listing.</td></tr>
                            <tr><td class="actions-col"><code>{stat_updated}</code></td><td>The most recent activity date: whichever is more recent of the newest approved member's join date and the &ldquo;last updated&rdquo; date you set for the listing. Shows <em>N/A</em> if neither is set.</td></tr>
                            <tr><td class="actions-col"><code>{stat_newmembers}</code></td><td>Newest approved members, linked to their websites when available. Count set in the Statistics template section (or global default). Shows <em>&hellip;and more</em> when there are more total.</td></tr>
                            <tr><td class="actions-col"><code>{stat_growth}</code></td><td>Average members per day, with unit (e.g. &ldquo;0.15 members/day&rdquo;).</td></tr>
                            <tr><td class="actions-col"><code>{stat_growth_rate}</code></td><td>Numeric growth rate only (e.g. &ldquo;0.15&rdquo;). Use to build your own formatting.</td></tr>
                            <tr><td class="actions-col"><code>{listing_title}</code></td><td>Name of the listing.</td></tr>
                            <tr><td class="actions-col"><code>{listing_url}</code></td><td>Link to the listing.</td></tr>
                            <tr><td class="actions-col"><code>{approved_by}</code></td><td>Network acronym from the &ldquo;Approved By&rdquo; dropdown only: &ldquo;TFL&rdquo;, &ldquo;TAFL&rdquo;, or empty. Plain text.</td></tr>
                            <tr><td class="actions-col"><code>{network_name}</code></td><td>Full name of every network the listing has a category assigned under &mdash; not the &ldquo;Approved By&rdquo; dropdown. &ldquo;The Fanlistings Network&rdquo;, &ldquo;The Anime Fanlistings Network&rdquo;, both joined with &ldquo;&amp;&rdquo; when categorized under TFL and TAFL, or empty if neither.</td></tr>
                            <tr><td class="actions-col"><code>{tfl_url}</code></td><td>TFL approval page URL when &ldquo;Approved By&rdquo; is TFL and one is saved; otherwise <code>https://thefanlistings.org/</code></td></tr>
                            <tr><td class="actions-col"><code>{tafl_url}</code></td><td>TAFL approval page URL when &ldquo;Approved By&rdquo; is TAFL and one is saved; otherwise <code>https://www.animefanlistings.com/</code></td></tr>
                            <tr><td class="actions-col"><code>{listing_subject}</code></td><td>What the listing is about &mdash; the character, show, game, or topic.</td></tr>
                            <tr><td class="actions-col"><code>{approval_url}</code></td><td>The raw Approval Page URL exactly as saved, or empty when none is set. Does not fall back to the network homepage &mdash; use <code>{tfl_url}</code> / <code>{tafl_url}</code> if you want that fallback behavior.</td></tr>
                            <tr><td class="actions-col"><code>{if_stat_pending}&hellip;{/if_stat_pending}</code></td><td>Shows the text inside only when there are pending members.</td></tr>
                            <tr><td class="actions-col"><code>{if_approved_by}&hellip;{/if_approved_by}</code></td><td>Shows the text inside only when the &ldquo;Approved By&rdquo; dropdown is set to a network.</td></tr>
                            <tr><td class="actions-col"><code>{if_tfl}&hellip;{/if_tfl}</code>, <code>{if_tafl}&hellip;{/if_tafl}</code></td><td>Shows the text inside only when the listing has a category assigned from that network &mdash; independent of &ldquo;Approved By&rdquo;.</td></tr>
                            <tr><td class="actions-col"><code>{previous_owners}</code></td><td>Comma-separated list of previous owners, linked when a URL was given, plain text otherwise.</td></tr>
                            <tr><td class="actions-col"><code>{previous_owners_count}</code></td><td>How many previous owners are credited.</td></tr>
                            <tr><td class="actions-col"><code>{if_previous_owners}&hellip;{/if_previous_owners}</code></td><td>Shows the text inside only when at least one previous owner is credited.</td></tr>
                            <tr><td class="actions-col"><code>{each_previous_owner}&hellip;{/each_previous_owner}</code></td><td>Repeats the HTML inside it once per previous owner, for a custom layout instead of the flat {previous_owners} list. Contains its own tokens: <code>{previous_owner_name}</code>, <code>{previous_owner_url}</code>, <code>{if_previous_owner_url}&hellip;{/if_previous_owner_url}</code>.</td></tr>
                          </tbody>
                        </table>
                        </div>
          </div>
        </details>
        <details class="card chevron topic-card" id="ec-ref-row-tokens">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('members', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Member list<span class="chevron-head-sub">Put these words in your member list template. Use <code>{each_member}</code> for one member row, then add your own wrapper, empty state, sort links, or summary text around it.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{each_member}&hellip;{/each_member}</code></td><td>Repeat box for one member row. Luster repeats the text inside once for each approved member.</td></tr>
                            <tr><td class="actions-col"><code>{fan_name}</code></td><td>Member&rsquo;s name. <span class="text-dim ec-ex">e.g. <em>Alexia</em></span></td></tr>
                            <tr><td class="actions-col"><code>{fan_url}</code></td><td>Member&rsquo;s website link.</td></tr>
                            <tr><td class="actions-col"><code>{fan_email}</code></td><td>Member&rsquo;s email address. Only use this if you really want emails visible on your site.</td></tr>
                            <tr><td class="actions-col"><code>{fan_country}</code></td><td>Member&rsquo;s country.</td></tr>
                            <tr><td class="actions-col"><code>{fan_<em>fieldname</em>}</code></td><td>A custom field answer from the member. Replace <em>fieldname</em> with your field name. <span class="text-dim ec-ex">e.g. <code>{fan_favorite}</code></span></td></tr>
                            <tr><td class="actions-col"><code>{if_fan_url}</code>, <code>{if_no_fan_url}</code></td><td>Shows link or no-link markup depending on whether the member has a URL.</td></tr>
                            <tr><td class="actions-col"><code>{if_fan_country}</code>, <code>{if_no_fan_country}</code></td><td>Shows country or no-country markup depending on whether the member has a country saved.</td></tr>
                            <tr><td class="actions-col"><code>{if_members}&hellip;{/if_members}</code>, <code>{if_no_members}&hellip;{/if_no_members}</code></td><td>Shows wrapper or empty-state markup depending on whether the member list has approved members.</td></tr>
                            <tr><td class="actions-col"><code>{join_url}</code>, <code>{member_count}</code>, <code>{stats_line}</code></td><td>Page-level details for links, totals, and summary text outside the repeated row.</td></tr>
                            <tr><td class="actions-col"><code>{sort_link_date}</code>, <code>{sort_link_name}</code>, <code>{sort_link_country}</code></td><td>Ready-made member-list sort links that keep visitors on the current member page.</td></tr>
                          </tbody>
                        </table>
                        </div>
          </div>
        </details>
        <details class="card chevron topic-card" id="ec-ref-owned-tokens">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('star', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Owned listings<span class="chevron-head-sub">Put these words in your owned listings template. Think of a loop as a repeat box: you design one row, and Luster repeats that row for every owned listing it finds. Add your own <code>&lt;ul&gt;</code>, <code>&lt;li&gt;</code>, cards, or headings in the template if you want them.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{each_listing}&hellip;{/each_listing}</code></td><td>The normal repeat box. Put one listing row inside it, and Luster repeats that row once for each listing. This is what the built-in default uses.</td></tr>
                            <tr><td class="actions-col"><code>{each_current}&hellip;{/each_current}</code>, <code>{each_upcoming}&hellip;{/each_upcoming}</code>, <code>{each_pending}&hellip;{/each_pending}</code></td><td>Optional separate repeat boxes for Current, Upcoming, and Pending sections. Use these instead of <code>{each_listing}</code> when you want headings like &ldquo;Current&rdquo; and &ldquo;Upcoming&rdquo;. This same owned-listings template is reused by owned, latest, and random listing embeds, so a sectioned layout will show everywhere that template is used.</td></tr>
                            <tr><td class="actions-col"><code>{each_closed}&hellip;{/each_closed}</code>, <code>{each_adopted_out}&hellip;{/each_adopted_out}</code></td><td>Optional repeat boxes for Closed and Adopted Out sections. These populate whenever closed/adopted-out listings are part of the current result set (for example <code>$luster_status</code> set to <code>closed</code>, <code>adopted_out</code>, <code>past</code>, or <code>all</code>). <code>$luster_show_closed</code>/<code>$luster_show_adopted_out</code> control something different &mdash; whether the &ldquo;Closed&rdquo;/&ldquo;Adopted Out&rdquo; links appear in the category nav while browsing current listings (both default to <code>false</code>; the nav shows only the combined &ldquo;Past Fanlistings&rdquo; entry unless you turn these on via the &ldquo;Closed / Adopted Out categories&rdquo; option above), not whether these loops render.</td></tr>
                            <tr><td class="actions-col"><code>{each_past}&hellip;{/each_past}</code></td><td>A single repeat box combining Closed and Adopted Out listings together, sorted as one merged list &mdash; use this instead of separate <code>{each_closed}</code>/<code>{each_adopted_out}</code> boxes when you want one &ldquo;Past Fanlistings&rdquo; section. Rows still know their own status, so <code>{if_status_closed}</code>/<code>{if_status_adopted_out}</code> work inside it.</td></tr>
                            <tr><td class="actions-col"><code>{listing_title}</code></td><td>The listing&rsquo;s display name.</td></tr>
                            <tr><td class="actions-col"><code>{listing_url}</code></td><td>Link to the listing. Empty when no URL is saved.</td></tr>
                            <tr><td class="actions-col"><code>{listing_subject}</code></td><td>What the listing is about. For example, the character, show, game, ship, or topic.</td></tr>
                            <tr><td class="actions-col"><code>{listing_status}</code></td><td>The listing&rsquo;s status word: <code>current</code>, <code>upcoming</code>, <code>pending</code>, <code>closed</code>, or <code>adopted_out</code>.</td></tr>
                            <tr><td class="actions-col"><code>{listing_description}</code></td><td>The description you saved for the listing in Luster. Empty when none is saved.</td></tr>
                            <tr><td class="actions-col"><code>{approval_url}</code></td><td>The raw Approval Page URL exactly as saved, or empty when none is set. Does not fall back to the network homepage &mdash; use <code>{tfl_url}</code> / <code>{tafl_url}</code> if you want that fallback behavior.</td></tr>
                            <tr><td class="actions-col"><code>{adopted_to_name}</code>, <code>{adopted_to_url}</code></td><td>Name and link of the person or site the listing was adopted out to. Empty for non-adopted listings.</td></tr>
                            <tr><td class="actions-col"><code>{archive_note}</code>, <code>{archived_at}</code></td><td>Optional admin note and date recorded when the listing was closed or adopted out. Both empty for active listings.</td></tr>
                            <tr><td class="actions-col"><code>{listing_email}</code></td><td>Contact email for the listing.</td></tr>
                            <tr><td class="actions-col"><code>{listing_type}</code></td><td>The optional type you saved for the listing, such as fanlisting or shrine.</td></tr>
                            <tr><td class="actions-col"><code>{listing_category}</code></td><td>The category or categories this listing belongs to.</td></tr>
                            <tr><td class="actions-col"><code>{listing_image_url}</code></td><td>Just the image&rsquo;s web address as plain text. Empty when there is no image.</td></tr>
                            <tr><td class="actions-col"><code>{date_opened}</code></td><td>Date you opened the listing.</td></tr>
                            <tr><td class="actions-col"><code>{date_updated}</code></td><td>The &ldquo;last updated&rdquo; date you set for the listing in Luster.</td></tr>
                            <tr><td class="actions-col"><code>{member_count}</code></td><td>Number of approved members.</td></tr>
                            <tr><td class="actions-col"><code>{member_pending_count}</code></td><td>Number of members waiting for approval on this listing.</td></tr>
                            <tr><td class="actions-col"><code>{country_count}</code></td><td>Number of different countries represented by approved members.</td></tr>
                            <tr><td class="actions-col"><code>{approved_by}</code></td><td>Network acronym from the &ldquo;Approved By&rdquo; dropdown only: &ldquo;TFL&rdquo;, &ldquo;TAFL&rdquo;, or empty. Plain text.</td></tr>
                            <tr><td class="actions-col"><code>{network_name}</code></td><td>Full name of every network the listing has a category assigned under &mdash; not the &ldquo;Approved By&rdquo; dropdown. Both joined with &ldquo;&amp;&rdquo; when categorized under TFL and TAFL, or empty if neither.</td></tr>
                            <tr><td class="actions-col"><code>{tfl_url}</code></td><td>TFL approval page URL when &ldquo;Approved By&rdquo; is TFL and one is saved; otherwise the TFL homepage.</td></tr>
                            <tr><td class="actions-col"><code>{tafl_url}</code></td><td>TAFL approval page URL when &ldquo;Approved By&rdquo; is TAFL and one is saved; otherwise the TAFL homepage.</td></tr>
                            <tr><td class="actions-col"><code>{if_listing_image}&hellip;{/if_listing_image}</code>, <code>{if_no_listing_image}&hellip;{/if_no_listing_image}</code></td><td>Shows the text inside only when the listing has (or does not have) an image.</td></tr>
                            <tr><td class="actions-col"><code>{if_listing_url}&hellip;{/if_listing_url}</code>, <code>{if_no_listing_url}&hellip;{/if_no_listing_url}</code></td><td>Shows link or no-link markup depending on whether the listing has a URL. Closed listings may have no URL set.</td></tr>
                            <tr><td class="actions-col"><code>{if_description}&hellip;{/if_description}</code></td><td>Shows the text inside only when a listing description is saved.</td></tr>
                            <tr><td class="actions-col"><code>{if_countries}&hellip;{/if_countries}</code></td><td>Shows the text inside only when at least one country is counted.</td></tr>
                            <tr><td class="actions-col"><code>{if_pending}&hellip;{/if_pending}</code></td><td>Shows the text inside only when this listing has members waiting for approval.</td></tr>
                            <tr><td class="actions-col"><code>{if_status_current}&hellip;{/if_status_current}</code>, <code>{if_status_upcoming}&hellip;{/if_status_upcoming}</code>, <code>{if_status_pending}&hellip;{/if_status_pending}</code></td><td>Shows the text inside only when the listing&rsquo;s status matches.</td></tr>
                            <tr><td class="actions-col"><code>{if_status_closed}&hellip;{/if_status_closed}</code>, <code>{if_status_adopted_out}&hellip;{/if_status_adopted_out}</code></td><td>Shows the text inside only when the listing is closed or adopted out. Use inside the row to show special messaging for archived listings.</td></tr>
                            <tr><td class="actions-col"><code>{if_tfl}&hellip;{/if_tfl}</code>, <code>{if_tafl}&hellip;{/if_tafl}</code></td><td>Shows the text inside only when the listing has a category assigned from that network &mdash; independent of &ldquo;Approved By&rdquo;.</td></tr>
                            <tr><td class="actions-col"><code>{if_approved_by}&hellip;{/if_approved_by}</code></td><td>Shows the text inside only when the &ldquo;Approved By&rdquo; dropdown is set to a network.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <div class="section-label" style="margin-top:1.5rem">Section Totals</div>
                      <p class="text-muted" style="margin-top:.875rem">Section words for sectioned owned templates. Put these near your section headings, outside the repeated row itself. Example: <code>Current ({current_owned_count})</code>. These count listings, not members.</p>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{current_owned_count}</code></td><td>Number of current listings.</td></tr>
                            <tr><td class="actions-col"><code>{upcoming_owned_count}</code></td><td>Number of upcoming listings.</td></tr>
                            <tr><td class="actions-col"><code>{pending_owned_count}</code></td><td>Number of pending listings.</td></tr>
                            <tr><td class="actions-col"><code>{closed_owned_count}</code></td><td>Number of closed listings.</td></tr>
                            <tr><td class="actions-col"><code>{adopted_out_owned_count}</code></td><td>Number of adopted out listings.</td></tr>
                            <tr><td class="actions-col"><code>{past_owned_count}</code></td><td>Number of closed + adopted-out listings combined.</td></tr>
                            <tr><td class="actions-col"><code>{total_count}</code></td><td>Total listings shown.</td></tr>
                            <tr><td class="actions-col"><code>{if_current_listings}&hellip;{/if_current_listings}</code></td><td>Shows the text inside only when there are current listings.</td></tr>
                            <tr><td class="actions-col"><code>{if_upcoming_listings}&hellip;{/if_upcoming_listings}</code></td><td>Shows the text inside only when there are upcoming listings.</td></tr>
                            <tr><td class="actions-col"><code>{if_pending_listings}&hellip;{/if_pending_listings}</code></td><td>Shows the text inside only when there are pending listings.</td></tr>
                            <tr><td class="actions-col"><code>{if_closed_listings}&hellip;{/if_closed_listings}</code></td><td>Shows the text inside only when there are closed listings.</td></tr>
                            <tr><td class="actions-col"><code>{if_adopted_out_listings}&hellip;{/if_adopted_out_listings}</code></td><td>Shows the text inside only when there are adopted out listings.</td></tr>
                            <tr><td class="actions-col"><code>{if_past_listings}&hellip;{/if_past_listings}</code></td><td>Shows the text inside only when there are any closed or adopted-out listings.</td></tr>
                          </tbody>
                        </table>
                        </div>
          </div>
        </details>
        <details class="card chevron topic-card" id="ec-ref-joined-tokens">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('heart', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Joined listings<span class="chevron-head-sub">Put these words in your joined listings template. Use a repeat box for the row, then add your own list, card, or paragraph markup around it.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{each_joined}&hellip;{/each_joined}</code></td><td>Wrap your row markup in this. Luster repeats what&rsquo;s inside once per joined listing (flat list).</td></tr>
                            <tr><td class="actions-col"><code>{each_current_joined}&hellip;{/each_current_joined}</code></td><td>A repeat box for approved joined listings, ordered by category. It works for one selected category or all categories, but it reads best on a page that shows all joined listings.</td></tr>
                            <tr><td class="actions-col"><code>{listing_title}</code></td><td>Name of the listing.</td></tr>
                            <tr><td class="actions-col"><code>{listing_url}</code></td><td>Link to the listing.</td></tr>
                            <tr><td class="actions-col"><code>{listing_category}</code></td><td>Listing category.</td></tr>
                            <tr><td class="actions-col"><code>{listing_image_url}</code></td><td>Just the image&rsquo;s web address as plain text. Empty when there is no image.</td></tr>
                            <tr><td class="actions-col"><code>{listing_type}</code></td><td>The type label for the listing, such as fanlisting or shrine. Usually empty for joined listings.</td></tr>
                            <tr><td class="actions-col"><code>{listing_status}</code></td><td>Listing status.</td></tr>
                            <tr><td class="actions-col"><code>{date_joined}</code></td><td>Date you joined the listing.</td></tr>
                            <tr><td class="actions-col"><code>{if_listing_image}&hellip;{/if_listing_image}</code>, <code>{if_no_listing_image}&hellip;{/if_no_listing_image}</code></td><td>Shows the text inside only when the listing has (or does not have) an image.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <div class="section-label" style="margin-top:1.5rem">Section Totals</div>
                      <p class="text-muted" style="margin-top:.875rem">Section words for joined templates. Put these near headings or summary text, outside the repeated row itself.</p>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{current_count}</code></td><td>Number of active joined listings shown.</td></tr>
                            <tr><td class="actions-col"><code>{total_count}</code></td><td>Total number of active joined listings.</td></tr>
                            <tr><td class="actions-col"><code>{if_current_listings}&hellip;{/if_current_listings}</code></td><td>Shows the text inside only when there are active joined listings.</td></tr>
                          </tbody>
                        </table>
                        </div>
          </div>
        </details>
        <details class="card chevron topic-card" id="ec-ref-aff-tokens">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('link', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Affiliates<span class="chevron-head-sub">Put these words in your affiliates template. Use <code>{each_affiliate}</code> to design each affiliate row yourself.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{each_affiliate}&hellip;{/each_affiliate}</code></td><td>Wrap your affiliate row in this. Luster repeats what&rsquo;s inside once per affiliate.</td></tr>
                            <tr><td class="actions-col"><code>{aff_name}</code></td><td>Affiliate site&rsquo;s name.</td></tr>
                            <tr><td class="actions-col"><code>{aff_url}</code></td><td>Affiliate site&rsquo;s link.</td></tr>
                            <tr><td class="actions-col"><code>{aff_image_url}</code></td><td>Full URL of the affiliate&rsquo;s image, or empty string if none. Use inside <code>{if_aff_image}</code> to avoid broken <code>&lt;img&gt;</code> tags.</td></tr>
                            <tr><td class="actions-col"><code>{if_aff_image}&hellip;{/if_aff_image}</code></td><td>Shows the text inside only when the affiliate has an image.</td></tr>
                            <tr><td class="actions-col"><code>{if_no_aff_image}&hellip;{/if_no_aff_image}</code></td><td>Shows the text inside only when the affiliate has no image. Use to show a text link fallback.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <div class="section-label" style="margin-top:1.5rem">Section Totals</div>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{affiliates_count}</code></td><td>Number of active affiliates.</td></tr>
                            <tr><td class="actions-col"><code>{affiliate_apply_url}</code></td><td>Link to the matching affiliate application form. Listing affiliates point to that listing; collective affiliates point to the collective application form.</td></tr>
                            <tr><td class="actions-col"><code>{affiliate_apply_toggle}</code></td><td>Clickable trigger (link or button) that shows/hides the affiliate application form. Empty when applications are disabled.</td></tr>
                            <tr><td class="actions-col"><code>{affiliate_apply_form}</code></td><td>The affiliate application form itself. Place anywhere - it starts hidden and is revealed by clicking {affiliate_apply_toggle}.</td></tr>
                            <tr><td class="actions-col"><code>{if_affiliates}&hellip;{/if_affiliates}</code></td><td>Shows the text inside only when there are affiliates.</td></tr>
                            <tr><td class="actions-col"><code>{if_no_affiliates}&hellip;{/if_no_affiliates}</code></td><td>Shows the text inside only when there are no affiliates.</td></tr>
                          </tbody>
                        </table>
                        </div>
          </div>
        </details>
        <details class="card chevron topic-card" id="ec-ref-cstat-tokens">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('sparkles', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Collective stats template<span class="chevron-head-sub">Put these words in your collective stats template. Luster replaces them with your site-wide numbers.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{owned_active}</code></td><td>Number of current owned listings.</td></tr>
                            <tr><td class="actions-col"><code>{owned_upcoming}</code></td><td>Number of upcoming owned listings.</td></tr>
                            <tr><td class="actions-col"><code>{owned_pending}</code></td><td>Number of pending or inactive owned listings.</td></tr>
                            <tr><td class="actions-col"><code>{owned_tfl_count}</code></td><td>Number of current owned listings registered with TFL.</td></tr>
                            <tr><td class="actions-col"><code>{owned_tafl_count}</code></td><td>Number of current owned listings registered with TAFL.</td></tr>
                            <tr><td class="actions-col"><code>{joined_active}</code></td><td>Number of active joined listings.</td></tr>
                            <tr><td class="actions-col"><code>{joined_pending}</code></td><td>Number of joined listings waiting for approval.</td></tr>
                            <tr><td class="actions-col"><code>{joined_tfl_count}</code></td><td>Number of active joined listings registered with TFL.</td></tr>
                            <tr><td class="actions-col"><code>{joined_tafl_count}</code></td><td>Number of active joined listings registered with TAFL.</td></tr>
                            <tr><td class="actions-col"><code>{members_total}</code></td><td>Total members across all your listings.</td></tr>
                            <tr><td class="actions-col"><code>{members_approved}</code></td><td>Approved members across all your listings.</td></tr>
                            <tr><td class="actions-col"><code>{members_pending}</code></td><td>Members waiting for approval across all your listings.</td></tr>
                            <tr><td class="actions-col"><code>{affiliates_sitewide}</code></td><td>Number of active site-wide affiliates (not tied to a specific listing).</td></tr>
                            <tr><td class="actions-col"><code>{affiliates_total}</code></td><td>Total number of active affiliates across all listings and collective.</td></tr>
                            <tr><td class="actions-col"><code>{tfl_url}</code></td><td>TFL website URL: <code>https://thefanlistings.org/</code></td></tr>
                            <tr><td class="actions-col"><code>{tafl_url}</code></td><td>TAFL website URL: <code>https://www.animefanlistings.com/</code></td></tr>
                            <tr><td class="actions-col"><code>{owned_newest_title}</code></td><td>Title of your newest current owned listing.</td></tr>
                            <tr><td class="actions-col"><code>{owned_newest_subject}</code></td><td>What your newest owned listing is about.</td></tr>
                            <tr><td class="actions-col"><code>{owned_newest_url}</code></td><td>Link to your newest current owned listing.</td></tr>
                            <tr><td class="actions-col"><code>{owned_random_title}</code></td><td>Title of one random current owned listing.</td></tr>
                            <tr><td class="actions-col"><code>{owned_random_subject}</code></td><td>What a random owned listing is about.</td></tr>
                            <tr><td class="actions-col"><code>{owned_random_url}</code></td><td>Link to one random current owned listing.</td></tr>
                            <tr><td class="actions-col"><code>{site_name}</code></td><td>Your site name from general settings.</td></tr>
                            <tr><td class="actions-col"><code>{countries_count}</code></td><td>Number of different countries represented by approved members across all your listings.</td></tr>
                            <tr><td class="actions-col"><code>{collective_last_updated}</code></td><td>Date your newest owned listing was opened &mdash; the last time the collective grew.</td></tr>
                            <tr><td class="actions-col"><code>{joined_total_cats}</code></td><td>Number of distinct categories across your active joined listings.</td></tr>
                            <tr><td class="actions-col"><code>{if_countries}&hellip;{/if_countries}</code></td><td>Shows the text inside only when at least one country is counted.</td></tr>
                            <tr><td class="actions-col"><code>{if_pending}&hellip;{/if_pending}</code></td><td>Shows the text inside only when at least one member is waiting for approval.</td></tr>
                            <tr><td class="actions-col"><code>{if_affiliates_sitewide}&hellip;{/if_affiliates_sitewide}</code></td><td>Shows the text inside only when there is at least one site-wide affiliate.</td></tr>
                            <tr><td class="actions-col"><code>{if_affiliates_total}&hellip;{/if_affiliates_total}</code></td><td>Shows the text inside only when there is at least one affiliate anywhere.</td></tr>
                            <tr><td class="actions-col"><code>{if_owned_upcoming}&hellip;{/if_owned_upcoming}</code></td><td>Shows the text inside only when at least one owned listing is marked upcoming.</td></tr>
                            <tr><td class="actions-col"><code>{if_owned_pending}&hellip;{/if_owned_pending}</code></td><td>Shows the text inside only when at least one owned listing is marked pending.</td></tr>
                          </tbody>
                        </table>
                        </div>
          </div>
        </details>
        <details class="card chevron topic-card" id="ec-ref-pagination-tokens">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('notes', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Pagination<span class="chevron-head-sub">Pagination is the little page picker under long lists. Put the page-row words inside <code>{each_page}</code>, and Luster repeats them for every page number it needs to show.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{if_pages}&hellip;{/if_pages}</code></td><td>Shows the pagination block only when there is more than one page.</td></tr>
                            <tr><td class="actions-col"><code>{current_page}</code></td><td>The page the visitor is on right now.</td></tr>
                            <tr><td class="actions-col"><code>{total_pages}</code></td><td>How many pages exist in total.</td></tr>
                            <tr><td class="actions-col"><code>{prev_url}</code></td><td>The link for the previous page. Empty on page 1.</td></tr>
                            <tr><td class="actions-col"><code>{next_url}</code></td><td>The link for the next page. Empty on the last page.</td></tr>
                            <tr><td class="actions-col"><code>{if_prev}&hellip;{/if_prev}</code>, <code>{if_next}&hellip;{/if_next}</code></td><td>Shows previous or next links only when those pages exist.</td></tr>
                            <tr><td class="actions-col"><code>{each_page}&hellip;{/each_page}</code></td><td>Repeat box for the numbered page links.</td></tr>
                            <tr><td class="actions-col"><code>{page_label}</code></td><td>The page number, or the gap marker when Luster is shortening a long list of pages.</td></tr>
                            <tr><td class="actions-col"><code>{page_url}</code></td><td>The link for this page number. Empty for the current page and gap markers.</td></tr>
                            <tr><td class="actions-col"><code>{if_current}&hellip;{/if_current}</code></td><td>Shows the text inside only for the current page number.</td></tr>
                            <tr><td class="actions-col"><code>{if_linked}&hellip;{/if_linked}</code></td><td>Shows the text inside only for page numbers visitors can click.</td></tr>
                            <tr><td class="actions-col"><code>{if_gap}&hellip;{/if_gap}</code></td><td>Shows the text inside only for a skipped-page marker.</td></tr>
                          </tbody>
                        </table>
                        </div>
          </div>
        </details>
        <details class="card chevron topic-card" id="ec-ref-form-tokens">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('general', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Form fields<span class="chevron-head-sub">These control the wrapper around one form field: label, input, hint, and error. Luster repeats this wrapper for every field in a public form.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{input}</code></td><td>The actual form control, such as a text box, dropdown, checkbox group, textarea, or file upload.</td></tr>
                            <tr><td class="actions-col"><code>{label}</code></td><td>The field label, such as Name, Email, or Country.</td></tr>
                            <tr><td class="actions-col"><code>{field_id}</code></td><td>The HTML ID for fields that have one. Useful for <code>for="{field_id}"</code> on labels.</td></tr>
                            <tr><td class="actions-col"><code>{required_html}</code></td><td>A red asterisk (<code>&lt;span class="fl-required"&gt;*&lt;/span&gt;</code>) when required; empty when optional.</td></tr>
                            <tr><td class="actions-col"><code>{hint}</code></td><td>Small help text for the field, when Luster has one.</td></tr>
                            <tr><td class="actions-col"><code>{error}</code></td><td>The field error message, when there is one.</td></tr>
                            <tr><td class="actions-col"><code>{lost_password_url}</code></td><td>The password-reset link for password fields.</td></tr>
                            <tr><td class="actions-col"><code>{if_hint}&hellip;{/if_hint}</code>, <code>{if_error}&hellip;{/if_error}</code></td><td>Shows hint or error markup only when that text exists.</td></tr>
                            <tr><td class="actions-col"><code>{if_field_id}&hellip;{/if_field_id}</code>, <code>{if_no_field_id}&hellip;{/if_no_field_id}</code></td><td>Use these when your markup needs to behave differently for checkbox or radio groups, which do not have one single input ID.</td></tr>
                            <tr><td class="actions-col"><code>{if_lost_password_url}&hellip;{/if_lost_password_url}</code></td><td>Shows the text inside only when a password-reset link is available.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <div class="section-label" style="margin-top:1.5rem">Named Form Fields</div>
                      <p class="text-muted" style="margin-top:.875rem">These go in whole form layout templates, such as join, code donation, affiliate application, adoption, and KIM sign-up templates. The normal <code>{field_*}</code> tokens include the saved field wrapper. Add <code>_input</code> before the closing brace, such as <code>{field_name_input}</code>, when you want the raw form control only.</p>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{listing_title}</code>, <code>{listing_url}</code></td><td>The listing or collective name and URL for the form.</td></tr>
                            <tr><td class="actions-col"><code>{site_name}</code></td><td>The site name in KIM sign-up templates.</td></tr>
                            <tr><td class="actions-col"><code>{field_name}</code>, <code>{field_email}</code>, <code>{field_url}</code></td><td>Common person, email, and website fields.</td></tr>
                            <tr><td class="actions-col"><code>{field_country}</code>, <code>{additional_fields}</code></td><td>Join-form country and custom fields. Empty when the listing does not use them.</td></tr>
                            <tr><td class="actions-col"><code>{field_password}</code>, <code>{field_password_confirm}</code></td><td>Password fields for join and KIM sign-up forms.</td></tr>
                            <tr><td class="actions-col"><code>{field_code_image}</code>, <code>{field_submitter_name}</code>, <code>{field_submitter_email}</code>, <code>{field_submitter_url}</code></td><td>Code donation upload and donor-credit fields.</td></tr>
                            <tr><td class="actions-col"><code>{field_image}</code>, <code>{field_message}</code></td><td>Affiliate image and application/adoption message fields.</td></tr>
                            <tr><td class="actions-col"><code>{field_fanlisting_id}</code></td><td>KIM sign-up listing selector.</td></tr>
                            <tr><td class="actions-col"><code>{captcha_block}</code>, <code>{captcha_block_input}</code>, <code>{captcha_question}</code></td><td>Captcha field wrapper, raw captcha input, and the captcha question text. Used by forms that still include captcha (join, code donation, affiliate application, contact, adoption). Empty in update, reset, and KIM forms where captcha was removed.</td></tr>
                            <tr><td class="actions-col"><code>{submit_button}</code>, <code>{clear_button}</code></td><td>Ready-made action buttons. Some forms only provide a submit button.</td></tr>
                          </tbody>
                        </table>
                        </div>
          </div>
        </details>
        <details class="card chevron topic-card" id="ec-ref-success-tokens">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('check', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Success messages<span class="chevron-head-sub">These words are for the message shown after someone submits a public form. The action words let one saved message behave differently for join, update, reset, contact, affiliate, KIM, code, and adoption forms.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{action}</code></td><td>The kind of completed action, such as <code>joined</code>, <code>updated</code>, or <code>contact-sent</code>.</td></tr>
                            <tr><td class="actions-col"><code>{fan_name}</code></td><td>The member or applicant name, when that form has one.</td></tr>
                            <tr><td class="actions-col"><code>{listing_title}</code></td><td>The listing name for listing-specific forms.</td></tr>
                            <tr><td class="actions-col"><code>{listing_url}</code></td><td>The listing link for listing-specific forms.</td></tr>
                            <tr><td class="actions-col"><code>{sender_name}</code></td><td>Name entered on a contact form.</td></tr>
                            <tr><td class="actions-col"><code>{sender_email}</code></td><td>Email entered on a contact form.</td></tr>
                            <tr><td class="actions-col"><code>{site_name}</code>, <code>{owner_name}</code>, <code>{owner_email}</code></td><td>Site and owner details for contact confirmations.</td></tr>
                            <tr><td class="actions-col"><code>{aff_name}</code>, <code>{aff_url}</code>, <code>{aff_email}</code></td><td>Affiliate application details.</td></tr>
                            <tr><td class="actions-col"><code>{if_joined_pending}</code>, <code>{if_updated}</code>, <code>{if_updated_pending}</code>, <code>{if_removed}</code></td><td>Shows the text inside only for that member action.</td></tr>
                            <tr><td class="actions-col"><code>{if_reset_sent}</code>, <code>{if_reset_done}</code>, <code>{if_code_submitted}</code></td><td>Shows the text inside only for password reset or code donation success.</td></tr>
                            <tr><td class="actions-col"><code>{if_kim_signed_up}</code>, <code>{if_kim_updated}</code>, <code>{if_kim_reset_done}</code>, <code>{if_kim_reset_sent}</code></td><td>Shows the text inside only for that KIM List success: sign-up, update saved, reset link emailed, or new password set.</td></tr>
                            <tr><td class="actions-col"><code>{if_adopt_submitted}</code>, <code>{if_contact_sent}</code>, <code>{if_affiliate_apply_sent}</code></td><td>Shows the text inside only for adoption, contact, or affiliate application success.</td></tr>
                            <tr><td class="actions-col"><code>{if_aff_email}</code>, <code>{if_no_aff_email}</code></td><td>Shows the text inside based on whether an affiliate email was supplied.</td></tr>
                          </tbody>
                        </table>
                        </div>
          </div>
        </details>
        <details class="card chevron topic-card" id="ec-ref-codes-tokens">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('embed', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Codes<span class="chevron-head-sub">These words are for code gallery templates. Use <code>{each_code}</code> for one flat list, or <code>{each_size_group}</code> when you want Luster to make a section for each button size.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{each_code}&hellip;{/each_code}</code></td><td>Repeat box for one code image.</td></tr>
                            <tr><td class="actions-col"><code>{each_size_group}&hellip;{/each_size_group}</code></td><td>Repeat box for one size group, such as 50x50 or 100x35. Put another <code>{each_code}</code> inside it for the codes in that group.</td></tr>
                            <tr><td class="actions-col"><code>{group_size}</code></td><td>The size label for the group.</td></tr>
                            <tr><td class="actions-col"><code>{group_code_count}</code></td><td>How many codes are in this size group.</td></tr>
                            <tr><td class="actions-col"><code>{code_url}</code></td><td>Direct link to the code image file.</td></tr>
                            <tr><td class="actions-col"><code>{code_width}</code>, <code>{code_height}</code></td><td>Image width and height in pixels. Empty when Luster does not know the size.</td></tr>
                            <tr><td class="actions-col"><code>{code_size}</code></td><td>The size label, such as 75x50.</td></tr>
                            <tr><td class="actions-col"><code>{code_name}</code></td><td>The code name, if one is saved.</td></tr>
                            <tr><td class="actions-col"><code>{code_donor_name}</code>, <code>{code_donor_url}</code></td><td>Donor name and donor link, when the code has donor credit.</td></tr>
                            <tr><td class="actions-col"><code>{if_size}&hellip;{/if_size}</code></td><td>Shows only when width and height are known.</td></tr>
                            <tr><td class="actions-col"><code>{if_size_changed}&hellip;{/if_size_changed}</code></td><td>Flat mode only. Shows when the code size differs from the previous code &mdash; useful for size headings.</td></tr>
                            <tr><td class="actions-col"><code>{if_donor}</code>, <code>{if_no_donor}</code>, <code>{if_donor_url}</code>, <code>{if_no_donor_url}</code></td><td>Shows donor markup only when donor details exist, or alternate markup when they do not.</td></tr>
                            <tr><td class="actions-col"><code>{if_code_name}</code></td><td>Shows the text inside only when the code has a saved name.</td></tr>
                            <tr><td class="actions-col"><code>{if_codes}&hellip;{/if_codes}</code>, <code>{if_no_codes}&hellip;{/if_no_codes}</code></td><td>Shows the text inside only when codes exist (or do not). Useful for showing an empty-state message in your template.</td></tr>
                            <tr><td class="actions-col"><code>{codes_submit_toggle}</code></td><td>Clickable trigger (link or button) that shows/hides the code donation form. Empty when code donations are disabled.</td></tr>
                            <tr><td class="actions-col"><code>{codes_submit_form}</code></td><td>The code donation form itself. Place anywhere - it starts hidden and is revealed by clicking {codes_submit_toggle}.</td></tr>
                          </tbody>
                        </table>
                        </div>
          </div>
        </details>
        <details class="card chevron topic-card" id="ec-ref-updates-tokens">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('updates', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Updates<span class="chevron-head-sub">These words are for news feed row templates. Design one update inside <code>{each_newsfeed_entry}</code>, and Luster repeats it for each update post.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{each_newsfeed_entry}&hellip;{/each_newsfeed_entry}</code></td><td>Repeat box for one update post.</td></tr>
                            <tr><td class="actions-col"><code>{newsfeed_title}</code></td><td>Update title.</td></tr>
                            <tr><td class="actions-col"><code>{newsfeed_date}</code></td><td>Friendly date, such as January 15, 2026.</td></tr>
                            <tr><td class="actions-col"><code>{newsfeed_body_html}</code></td><td>The update body with line breaks converted to <code>&lt;br&gt;</code>. A small set of formatting tags (bold, links, images, etc.) is allowed through &mdash; anything else is shown as plain text.</td></tr>
                            <tr><td class="actions-col"><code>{newsfeed_author}</code></td><td>Update author, when saved.</td></tr>
                            <tr><td class="actions-col"><code>{newsfeed_categories}</code></td><td>Update category names, separated by commas.</td></tr>
                            <tr><td class="actions-col"><code>{if_author}&hellip;{/if_author}</code>, <code>{if_categories}&hellip;{/if_categories}</code></td><td>Shows author or category markup only when that information exists.</td></tr>
                            <tr><td class="actions-col"><code>{if_newsfeed}&hellip;{/if_newsfeed}</code>, <code>{if_no_newsfeed}&hellip;{/if_no_newsfeed}</code></td><td>Shows the text inside only when there are updates (or none). Useful for empty-state messaging in your template.</td></tr>
                          </tbody>
                        </table>
                        </div>
          </div>
        </details>
        <details class="card chevron topic-card" id="ec-ref-wishlist-tokens">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('wishlist', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Wishlist<span class="chevron-head-sub">These words are for wishlist row templates. Luster repeats the row once for each active wishlist item being shown.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{each_wishlist_item}&hellip;{/each_wishlist_item}</code></td><td>Repeat box for one wishlist item.</td></tr>
                            <tr><td class="actions-col"><code>{item_subject}</code></td><td>The thing you want, love, or have been granted.</td></tr>
                            <tr><td class="actions-col"><code>{item_url}</code></td><td>Link for the item, when saved.</td></tr>
                            <tr><td class="actions-col"><code>{item_type}</code></td><td>The type label: Wanted, Top Wanted, or Granted.</td></tr>
                            <tr><td class="actions-col"><code>{item_category}</code></td><td>Wishlist category, when saved.</td></tr>
                            <tr><td class="actions-col"><code>{item_description}</code></td><td>Extra description text, when saved.</td></tr>
                            <tr><td class="actions-col"><code>{item_image_url}</code></td><td>Direct link to the wishlist image, when one is uploaded.</td></tr>
                            <tr><td class="actions-col"><code>{if_item_url}</code>, <code>{if_no_item_url}</code></td><td>Shows link or no-link markup depending on whether the item has a URL.</td></tr>
                            <tr><td class="actions-col"><code>{if_item_category}</code>, <code>{if_item_description}</code>, <code>{if_item_image}</code></td><td>Shows category, description, or image markup only when that detail exists.</td></tr>
                            <tr><td class="actions-col"><code>{if_type_top}&hellip;{/if_type_top}</code>, <code>{if_type_wanted}&hellip;{/if_type_wanted}</code>, <code>{if_type_granted}&hellip;{/if_type_granted}</code></td><td>Shows the text inside only for that wishlist type.</td></tr>
                          </tbody>
                        </table>
                        </div>
                      <div class="section-label" style="margin-top:1.5rem">Section Totals</div>
                      <p class="text-muted" style="margin-top:.875rem">Section words for grouping your wishlist by type. Use these to add a heading like "Top Wanted" that only shows up when there's something to put under it.</p>
                      <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{each_wishlist_item_top}&hellip;{/each_wishlist_item_top}</code></td><td>Repeat box for one Top Wanted item. Same words as the main repeat box above, but only for Top Wanted entries on this page.</td></tr>
                            <tr><td class="actions-col"><code>{each_wishlist_item_wanted}&hellip;{/each_wishlist_item_wanted}</code></td><td>Repeat box for one Wanted item, only for Wanted entries on this page.</td></tr>
                            <tr><td class="actions-col"><code>{each_wishlist_item_granted}&hellip;{/each_wishlist_item_granted}</code></td><td>Repeat box for one Granted item, only for Granted entries on this page.</td></tr>
                            <tr><td class="actions-col"><code>{if_has_top}&hellip;{/if_has_top}</code></td><td>Shows the text inside only when this page has at least one Top Wanted item. Put your "Top Wanted" heading and its repeat box inside this.</td></tr>
                            <tr><td class="actions-col"><code>{if_has_wanted}&hellip;{/if_has_wanted}</code></td><td>Shows the text inside only when this page has at least one Wanted item.</td></tr>
                            <tr><td class="actions-col"><code>{if_has_granted}&hellip;{/if_has_granted}</code></td><td>Shows the text inside only when this page has at least one Granted item.</td></tr>
                          </tbody>
                        </table>
                        </div>
          </div>
        </details>
        <details class="card chevron topic-card" id="ec-ref-kim-tokens">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('kim', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">KIM members<span class="chevron-head-sub">These words are for the KIM member row template. Luster repeats the row once for each approved KIM member being shown.</span></span>
          </summary>
          <div class="chevron-body">
            <div class="table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">Embed Token</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>{each_kim_member}&hellip;{/each_kim_member}</code></td><td>Repeat box for one approved KIM member.</td></tr>
                            <tr><td class="actions-col"><code>{kim_name}</code></td><td>KIM member&rsquo;s name.</td></tr>
                            <tr><td class="actions-col"><code>{kim_url}</code></td><td>KIM member&rsquo;s website link, when saved.</td></tr>
                            <tr><td class="actions-col"><code>{listing_title}</code></td><td>The listing name attached to this KIM member.</td></tr>
                            <tr><td class="actions-col"><code>{listing_subject}</code></td><td>What that listing is about.</td></tr>
                            <tr><td class="actions-col"><code>{if_kim_url}</code>, <code>{if_no_kim_url}</code></td><td>Shows link or no-link markup depending on whether the KIM member has a URL.</td></tr>
                          </tbody>
                        </table>
                        </div>
          </div>
        </details>
    </div>

    <h2 class="ref-zone-head">Advanced</h2>
    <div class="ref-topic-list">
        <details class="card chevron topic-card" id="ec-ref-cstat-vars">
          <summary class="chevron-head">
            <span class="chevron-head-icon"><?= View::icon('sparkles', ['width' => 15, 'height' => 15]) ?></span>
            <span class="chevron-head-text">Stats for custom PHP<span class="chevron-head-sub">Advanced. These are plain PHP variables created after you require <code>collective-stats.php</code>. Number and text variables can be echoed directly; object or array variables need PHP code that picks a key or property first.</span></span>
          </summary>
          <div class="chevron-body">
            <details class="form-details">
                        <summary>Show advanced PHP stats</summary>
                        <div class="form-details-body table-wrap">
                        <table class="data-table">
                          <thead><tr><th class="actions-col">PHP variable</th><th>What it shows</th></tr></thead>
                          <tbody>
                            <tr><td class="actions-col"><code>$luster_owned</code></td><td>Total number of owned listings.</td></tr>
                            <tr><td class="actions-col"><code>$luster_owned_active</code></td><td>Number of current owned listings.</td></tr>
                            <tr><td class="actions-col"><code>$luster_owned_upcoming</code></td><td>Number of upcoming owned listings.</td></tr>
                            <tr><td class="actions-col"><code>$luster_owned_pending</code></td><td>Number of pending or inactive owned listings.</td></tr>
                            <tr><td class="actions-col"><code>$luster_owned_tfl_count</code></td><td>Number of current owned listings registered with TFL.</td></tr>
                            <tr><td class="actions-col"><code>$luster_owned_tafl_count</code></td><td>Number of current owned listings registered with TAFL.</td></tr>
                            <tr><td class="actions-col"><code>$luster_joined</code></td><td>Total number of joined listings.</td></tr>
                            <tr><td class="actions-col"><code>$luster_joined_active</code></td><td>Number of active joined listings.</td></tr>
                            <tr><td class="actions-col"><code>$luster_joined_approved</code></td><td>Number of active joined listings that are approved, not pending or upcoming.</td></tr>
                            <tr><td class="actions-col"><code>$luster_joined_upcoming</code></td><td>Number of upcoming joined listings.</td></tr>
                            <tr><td class="actions-col"><code>$luster_joined_pending</code></td><td>Number of pending joined listings.</td></tr>
                            <tr><td class="actions-col"><code>$luster_joined_tfl_count</code></td><td>Number of active joined listings registered with TFL.</td></tr>
                            <tr><td class="actions-col"><code>$luster_joined_tafl_count</code></td><td>Number of active joined listings registered with TAFL.</td></tr>
                            <tr><td class="actions-col"><code>$luster_affiliates</code></td><td>Number of active site-wide affiliates (same as <code>$luster_affiliates_sitewide</code>).</td></tr>
                            <tr><td class="actions-col"><code>$luster_affiliates_sitewide</code></td><td>Number of active site-wide affiliates (those not attached to a specific listing).</td></tr>
                            <tr><td class="actions-col"><code>$luster_affiliates_total</code></td><td>Number of all active affiliates, including those attached to specific listings.</td></tr>
                            <tr><td class="actions-col"><code>$luster_members_approved</code></td><td>Approved members across all your listings.</td></tr>
                            <tr><td class="actions-col"><code>$luster_members_pending</code></td><td>Members waiting for approval across all your listings.</td></tr>
                            <tr><td class="actions-col"><code>$luster_members_total</code></td><td>Total members across all your listings, including pending members.</td></tr>
                            <tr><td class="actions-col"><code>$luster_owned_growth_rate</code></td><td>How many current owned listings you add per year, on average.</td></tr>
                            <tr><td class="actions-col"><code>$luster_joined_growth_rate</code></td><td>How many active joined listings you add per year, on average.</td></tr>
                            <tr><td class="actions-col"><code>$luster_members_growth_rate</code></td><td>How many approved members join per day, on average.</td></tr>
                            <tr><td class="actions-col"><code>$luster_owned_newest</code></td><td>A group of your newest current owned listings.</td></tr>
                            <tr><td class="actions-col"><code>$luster_joined_newest</code></td><td>A group of your newest active joined listings.</td></tr>
                            <tr><td class="actions-col"><code>$luster_owned_random</code></td><td>One random current owned listing.</td></tr>
                            <tr><td class="actions-col"><code>$luster_joined_random</code></td><td>One random active joined listing.</td></tr>
                            <tr><td class="actions-col"><code>$luster_categories</code></td><td>A group of your owned-listing categories, with a count for each one.</td></tr>
                            <tr><td class="actions-col"><code>$luster_countries_count</code></td><td>Number of different countries represented by approved members across all listings.</td></tr>
                            <tr><td class="actions-col"><code>$luster_collective_last_updated</code></td><td>Date your newest owned listing was opened, formatted "Month Dth Year" (or "Never" if no owned listing has an opened date).</td></tr>
                            <tr><td class="actions-col"><code>$luster_total_cats</code></td><td>Number of owned-listing categories.</td></tr>
                            <tr><td class="actions-col"><code>$luster_joined_categories</code></td><td>Array of distinct category names across your active joined listings.</td></tr>
                            <tr><td class="actions-col"><code>$luster_joined_total_cats</code></td><td>Number of distinct categories across your active joined listings.</td></tr>
                          </tbody>
                        </table>
                          </div>
                      </details>
          </div>
        </details>
    </div>

  </div>

<!-- ──── EMBED PREVIEW MODAL ─────────────────────────────────────────────── -->
<div class="modal-backdrop is-hidden" id="ec-prev-modal" style="display:none">
  <div class="modal modal--preview">
    <div class="modal-header">
      <span class="modal-title" id="ec-prev-modal-title">Preview</span>
      <div style="display:flex;align-items:center;gap:.5rem">
        <a id="ec-prev-newtab" href="#" target="_blank" rel="noopener"
           class="btn btn-quaternary" style="text-decoration:none">&#x2197;&ensp;Open in New Tab</a>
        <button type="button" id="ec-prev-close" class="btn btn-quaternary">&#x2715;&ensp;Close</button>
      </div>
    </div>
    <div class="modal-body--iframe">
      <iframe id="ec-prev-iframe" src="" title="Embed preview"></iframe>
    </div>
  </div>
</div>
