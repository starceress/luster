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
$setupDefine = "<?php defined('LUSTER_EMBED') || define('LUSTER_EMBED', '" . str_replace(["\\", "'"], ["\\\\", "\\'"], $ep) . "'); ?>";
$setupCss = '<link rel="stylesheet" href="' . rtrim((string) ($base_url ?? ''), '/') . '/assets/css/default/style.css">';

// ── icon + badge maps ─────────────────────────────────────────────────────
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
    'updates'      => 'updates',
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
    'col-updates'  => 'updates',
    'col-contact'  => 'email',
    'wishlist'     => 'wishlist',
    'rules-gate'   => 'rules',
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
        'label' => 'Affiliate Form',
        'badge' => 'Form',
        'file'  => 'affiliate-apply.php',
        'desc'  => 'Must be enabled for the listing under Edit Listing → Affiliates. A form where fans can submit affiliate applications. Applications are queued as pending and appear on the site after admin approval.',
    ],
    'update' => [
        'label' => 'Update Details Form',
        'badge' => 'Form',
        'file'  => 'update.php',
        'desc'  => 'Lets existing members update their name, URL, and other details. Fields are the same as the join form, so extra fields appear here too. Members can also check a box to permanently delete their membership instead — this takes effect immediately and emails the member a farewell message.',
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
        'label' => 'Affiliate Form',
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
        'label' => 'Codes Donation Form',
        'badge' => 'Form',
        'file'  => 'collective-codes-submit.php',
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
        'desc'  => 'Displays the wishlist. Filter by type to show only wanted, top, or granted entries. Requires the Wishlist add-on.',
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

      <div class="ec-setup-top">
        <div class="ec-setup-head">
          <div class="wizard-step-node wizard-step-node--active ec-setup-step" id="ec-step1-node">
            <div class="wizard-step-bubble">1</div>
            <span class="wizard-step-label"
                  data-step-sub="Paste *both* lines into your header, just before </head>"><span class="ec-step-title">Include This <span class="badge badge-error badge-inline">Required!</span></span></span>
          </div>
          <button type="button" class="btn btn-primary btn-sm ec-setup-cp-btn" id="ec-setup-cp"
                  data-setup-text="<?= htmlspecialchars($setupDefine . "\n" . $setupCss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Copy</button>
        </div>
        <div class="ec-setup-snip">
          <div class="ec-setup-snip-line"><code><?= View::e($setupDefine) ?></code></div>
          <div class="ec-setup-snip-line"><code><?= View::e($setupCss) ?></code></div>
        </div>
      </div>

      <!-- ② + ③ Pickers -->
      <div class="ec-sel-pickers">
        <div class="ec-sel-row">

          <div class="ec-sel-step">
            <div class="wizard-step-node wizard-step-node--active" id="ec-step2-node">
              <div class="wizard-step-bubble">2</div>
              <span class="wizard-step-label"
                    data-step-sub="Collective or a single listing.">Where It Goes</span>
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
            <div class="wizard-step-node wizard-step-node--active" id="ec-step3-node">
              <div class="wizard-step-bubble">3</div>
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

    <p class="text-muted">
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
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-pagination-tokens">Pagination</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-form-tokens">Form fields</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-success-tokens">Success messages</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-codes-tokens">Codes</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-updates-tokens">Updates</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-wishlist-tokens">Wishlist</button>
        <button class="ec-ref-nav-item" data-ec-ref="ec-ref-kim-tokens">KIM members</button>
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
          <h3 class="pc-head-title">Members and passwords</h3>
          <p class="text-muted">Settings for the members list.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_per_page</code></td><td>How many members to show before Luster adds a next-page link. Leave this out to use your normal Luster setting.</td></tr>
                <tr><td class="act-col"><code>$luster_sort_by</code></td><td>Choose how the member list starts: <code>'name'</code>, <code>'country'</code>, or <code>'added'</code> for newest joined first. Leave this out if you want visitors to choose the order themselves.</td></tr>
                <tr><td class="act-col"><code>$luster_show_search</code></td><td>Set this to <code>false</code> if you want to hide the member sort and country filter tools.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted" style="margin-top:.875rem">Settings for password reset pages.</p>
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
          <p class="text-muted" style="margin-top:.875rem">Settings for the update form.</p>
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
          <p class="text-muted" style="margin-top:.875rem">Settings for listing stats.</p>
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
          <p class="text-muted" style="margin-top:.875rem">Settings for affiliate lists.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
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
          <p class="text-muted" style="margin-top:.875rem">Settings for updates feeds.</p>
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
          <h3 class="pc-head-title">Listing pages</h3>
          <p class="text-muted">Settings for owned listings pages.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_status</code></td><td>Choose which owned listings to show: current, upcoming, pending, or all. Leave this out to show current listings only.</td></tr>
                <tr><td class="act-col"><code>$luster_category_id</code></td><td>Show only one category of owned listings. Most users can leave this out and let visitors choose a category on the page.</td></tr>
                <tr><td class="act-col"><code>$luster_hide_dropdown</code></td><td>Set this to <code>true</code> if you do not want the category dropdown to appear.</td></tr>
                <tr><td class="act-col"><code>$luster_show_list</code></td><td>Set this to <code>true</code> to show category links as a list instead of a dropdown.</td></tr>
                <tr><td class="act-col"><code>$luster_show_cat_switcher</code></td><td>Set this to <code>false</code> if you do not want the compact category switcher on an individual category page.</td></tr>
                <tr><td class="act-col"><code>$luster_browse_by_category</code></td><td>Set this to <code>true</code> if you want visitors to choose a category first, then see the listings in that category.</td></tr>
                <tr><td class="act-col"><code>$luster_exclude_pending</code></td><td>When showing all statuses, this keeps pending listings hidden. Leave it alone unless you want pending listings to show too.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted" style="margin-top:.875rem">Settings for joined listings pages.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_category</code></td><td>Choose what joined listings to show. Leave it blank to let visitors choose a category. Use <code>'all'</code> to show everything grouped by category. Or type one category name to show only that category.</td></tr>
                <tr><td class="act-col"><code>$luster_show_list</code></td><td>Set this to <code>true</code> to show category links as a list instead of a dropdown. This only matters when visitors are choosing a category.</td></tr>
                <tr><td class="act-col"><code>$luster_show_cat_switcher</code></td><td>Set this to <code>false</code> if you do not want the compact category switcher on an individual category page.</td></tr>
                <tr><td class="act-col"><code>$luster_show_all_by_default</code></td><td>Set this to <code>true</code> if you want all joined listings to show right away, while still letting visitors choose a category.</td></tr>
                <tr><td class="act-col"><code>$luster_browse_by_category</code></td><td>Set this to <code>true</code> if you want visitors to choose a category first, then see the joined listings in that category.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted" style="margin-top:.875rem">Setting for random listing.</p>
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
          <p class="text-muted" style="margin-top:.875rem">Setting for wishlist pages.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Setting</th><th>What it does</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$luster_wishlist_type</code></td><td>Choose which wishlist entries to show: all, wanted, top, or granted.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted" style="margin-top:.875rem">Settings for KIM List pages.</p>
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
                <tr><td class="act-col"><code>$luster_kim_only_with_signups</code></td><td><strong>kim-list.php overview only.</strong> Defaults to <code>true</code>. Set it to <code>false</code> if the overview should show every eligible listing, even listings with no approved KIM sign-ups yet.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted" style="margin-top:.875rem">Setting for collective stats.</p>
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
          <h3 class="pc-head-title">Listing stats</h3>
          <p class="text-muted">Put these words in your stats template. Luster replaces each one with real listing information.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{stat_approved}</code></td><td>Number of approved members. <span class="ec-ex">e.g. <em>33</em></span></td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_pending}</code></td><td>Number of members waiting for approval.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_countries}</code></td><td>Number of different countries represented by approved members.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_opened}</code></td><td>Date you opened the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_updated}</code></td><td>The most recent activity date: whichever is more recent of the newest approved member's join date and the &ldquo;last updated&rdquo; date you set for the listing. Shows <em>N/A</em> if neither is set.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_newmembers}</code></td><td>Names of the 5 newest approved members, formatted as a sentence, such as <em>A, B, and C</em>.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{stat_growth}</code></td><td>Average new members per day since the listing opened.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_title}</code></td><td>Name of the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_url}</code></td><td>Link to the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{approved_by}</code></td><td>Network acronym: &ldquo;TFL&rdquo;, &ldquo;TAFL&rdquo;, or empty. Plain text.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{network}</code></td><td>Same as {approved_by} &mdash; network acronym as raw text. Empty when not set.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{network_name}</code></td><td>Full network name: &ldquo;The Fanlistings Network&rdquo;, &ldquo;The Anime Fanlistings Network&rdquo;, or empty.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{tfl_url}</code></td><td>TFL website URL: <code>https://thefanlistings.org/</code></td></tr>
                <tr><td class="act-col"><code class="ec-tk">{tafl_url}</code></td><td>TAFL website URL: <code>https://www.animefanlistings.com/</code></td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_stat_pending}&hellip;{/if_stat_pending}</code></td><td>Shows the text inside only when there are pending members.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_approved_by}&hellip;{/if_approved_by}</code></td><td>Shows the text inside only when the listing has an approved-by network saved.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_tfl}&hellip;{/if_tfl}</code>, <code class="ec-tk">{if_tafl}&hellip;{/if_tafl}</code></td><td>Shows the text inside only when the listing is registered with that specific network.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- MEMBER ROW TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-row-tokens">
          <h3 class="pc-head-title">Member list</h3>
          <p class="text-muted">Put these words in your member list template. Use <code>{each_member}</code> for one member row, then add your own wrapper, empty state, sort links, or summary text around it.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{each_member}&hellip;{/each_member}</code></td><td>Repeat box for one member row. Luster repeats the text inside once for each approved member.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{fan_name}</code></td><td>Member&rsquo;s name. <span class="ec-ex">e.g. <em>Alexia</em></span></td></tr>
                <tr><td class="act-col"><code class="ec-tk">{fan_url}</code></td><td>Member&rsquo;s website link.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{fan_email}</code></td><td>Member&rsquo;s email address. Only use this if you really want emails visible on your site.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{fan_country}</code></td><td>Member&rsquo;s country.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{fan_<em>fieldname</em>}</code></td><td>A custom field answer from the member. Replace <em>fieldname</em> with your field name. <span class="ec-ex">e.g. <code>{fan_favorite}</code></span></td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_fan_url}</code>, <code class="ec-tk">{if_no_fan_url}</code></td><td>Shows link or no-link markup depending on whether the member has a URL.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_fan_country}</code>, <code class="ec-tk">{if_no_fan_country}</code></td><td>Shows country or no-country markup depending on whether the member has a country saved.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_members}&hellip;{/if_members}</code>, <code class="ec-tk">{if_no_members}&hellip;{/if_no_members}</code></td><td>Shows wrapper or empty-state markup depending on whether the member list has approved members.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{join_url}</code>, <code class="ec-tk">{member_count}</code>, <code class="ec-tk">{stats_line}</code></td><td>Page-level details for links, totals, and summary text outside the repeated row.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{sort_link_all}</code>, <code class="ec-tk">{sort_link_name}</code>, <code class="ec-tk">{sort_link_country}</code></td><td>Ready-made member-list sort links that keep visitors on the current member page.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- OWNED LISTINGS TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-owned-tokens">
          <h3 class="pc-head-title">Owned listings</h3>
          <p class="text-muted">Put these words in your owned listings template. Think of a loop as a repeat box: you design one row, and Luster repeats that row for every owned listing it finds. Add your own <code>&lt;ul&gt;</code>, <code>&lt;li&gt;</code>, cards, or headings in the template if you want them.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{each_listing}&hellip;{/each_listing}</code></td><td>The normal repeat box. Put one listing row inside it, and Luster repeats that row once for each listing. This is what the built-in default uses.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{each_current}&hellip;{/each_current}</code>, <code class="ec-tk">{each_upcoming}&hellip;{/each_upcoming}</code>, <code class="ec-tk">{each_pending}&hellip;{/each_pending}</code></td><td>Optional separate repeat boxes for Current, Upcoming, and Pending sections. Use these instead of <code>{each_listing}</code> when you want headings like &ldquo;Current&rdquo; and &ldquo;Upcoming&rdquo;. This same owned-listings template is reused by owned, latest, and random listing embeds, so a sectioned layout will show everywhere that template is used.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_title}</code></td><td>The listing&rsquo;s display name.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_url}</code></td><td>Link to the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_subject}</code></td><td>What the listing is about. For example, the character, show, game, ship, or topic.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_status}</code></td><td>The listing&rsquo;s status word: <code>current</code>, <code>upcoming</code>, or <code>pending</code>.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_email}</code></td><td>Contact email for the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_type}</code></td><td>The optional type you saved for the listing, such as fanlisting or shrine.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_category}</code></td><td>The category or categories this listing belongs to.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_image_url}</code></td><td>Just the image&rsquo;s web address as plain text. Empty when there is no image.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_image_html}</code></td><td>A ready-to-use <code>&lt;img&gt;</code> tag for the listing image. Empty when there is no image.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{date_opened}</code></td><td>Date you opened the listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{date_updated}</code></td><td>The &ldquo;last updated&rdquo; date you set for the listing in Luster.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{member_count}</code></td><td>Number of approved members.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{member_pending_count}</code></td><td>Number of members waiting for approval on this listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{country_count}</code></td><td>Number of different countries represented by approved members.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{approved_by}</code></td><td>Network acronym: &ldquo;TFL&rdquo;, &ldquo;TAFL&rdquo;, or empty. Plain text.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{network}</code></td><td>Same as {approved_by} &mdash; network acronym as raw text. Empty when not set.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{network_name}</code></td><td>Full network name, or empty.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{tfl_url}</code></td><td>TFL website URL.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{tafl_url}</code></td><td>TAFL website URL.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_listing_image}&hellip;{/if_listing_image}</code></td><td>Shows the text inside only when the listing has an image.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_countries}&hellip;{/if_countries}</code></td><td>Shows the text inside only when at least one country is counted.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_pending}&hellip;{/if_pending}</code></td><td>Shows the text inside only when this listing has members waiting for approval.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_status_current}&hellip;{/if_status_current}</code>, <code class="ec-tk">{if_status_upcoming}&hellip;{/if_status_upcoming}</code>, <code class="ec-tk">{if_status_pending}&hellip;{/if_status_pending}</code></td><td>Shows the text inside only when the listing&rsquo;s status matches.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_tfl}&hellip;{/if_tfl}</code>, <code class="ec-tk">{if_tafl}&hellip;{/if_tafl}</code></td><td>Shows the text inside only when the listing is registered with that network.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_approved_by}&hellip;{/if_approved_by}</code></td><td>Shows the text inside only when the listing is registered with either network.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted" style="margin-top:.875rem">Section words for sectioned owned templates. Put these near your section headings, outside the repeated row itself. Example: <code>Current ({current_count})</code>. These count listings, not members.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{current_count}</code></td><td>Number of current listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{upcoming_count}</code></td><td>Number of upcoming listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{pending_count}</code></td><td>Number of pending listings. For pending members, use <code>{member_pending_count}</code>.</td></tr>
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
          <h3 class="pc-head-title">Joined listings</h3>
          <p class="text-muted">Put these words in your joined listings template. Use a repeat box for the row, then add your own list, card, or paragraph markup around it.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{each_joined}&hellip;{/each_joined}</code></td><td>Wrap your row markup in this. Luster repeats what&rsquo;s inside once per joined listing (flat list).</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{each_current_joined}&hellip;{/each_current_joined}</code></td><td>A repeat box for approved joined listings, ordered by category. It works for one selected category or all categories, but it reads best on a page that shows all joined listings.</td></tr>
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
          <p class="text-muted" style="margin-top:.875rem">Section words for joined templates. Put these near headings or summary text, outside the repeated row itself.</p>
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
          <h3 class="pc-head-title">Affiliates</h3>
          <p class="text-muted">Put these words in your affiliates template. Use <code>{each_affiliate}</code> to design each affiliate row yourself.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{each_affiliate}&hellip;{/each_affiliate}</code></td><td>Wrap your affiliate row in this. Luster repeats what&rsquo;s inside once per affiliate.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{aff_name}</code></td><td>Affiliate site&rsquo;s name.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{aff_url}</code></td><td>Affiliate site&rsquo;s link.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{aff_image_url}</code></td><td>Full URL of the affiliate&rsquo;s image, or empty string if none. Use inside <code>{if_aff_image}</code> to avoid broken <code>&lt;img&gt;</code> tags.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_aff_image}&hellip;{/if_aff_image}</code></td><td>Shows the text inside only when the affiliate has an image.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_no_aff_image}&hellip;{/if_no_aff_image}</code></td><td>Shows the text inside only when the affiliate has no image. Use to show a text link fallback.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted" style="margin-top:.875rem">Section words for affiliate section layouts.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{affiliates_count}</code></td><td>Number of active affiliates.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{affiliate_apply_url}</code></td><td>Link to the matching affiliate application form. Listing affiliates point to that listing; collective affiliates point to the collective application form.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_affiliates}&hellip;{/if_affiliates}</code></td><td>Shows the text inside only when there are affiliates.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_no_affiliates}&hellip;{/if_no_affiliates}</code></td><td>Shows the text inside only when there are no affiliates.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- COLLECTIVE STATS TEMPLATE TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-cstat-tokens">
          <h3 class="pc-head-title">Collective stats template</h3>
          <p class="text-muted">Put these words in your collective stats template. Luster replaces them with your site-wide numbers.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{owned_active}</code></td><td>Number of current owned listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_upcoming}</code></td><td>Number of upcoming owned listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_pending}</code></td><td>Number of pending or inactive owned listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{joined_active}</code></td><td>Number of active joined listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{joined_pending}</code></td><td>Number of joined listings waiting for approval.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{members_total}</code></td><td>Total members across all your listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{members_approved}</code></td><td>Approved members across all your listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{members_pending}</code></td><td>Members waiting for approval across all your listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_newest_name}</code></td><td>Name of your newest current owned listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_newest_url}</code></td><td>Link to your newest current owned listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_random_name}</code></td><td>Name of one random current owned listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{owned_random_url}</code></td><td>Link to one random current owned listing.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{countries_count}</code></td><td>Number of different countries represented by approved members across all your listings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{collective_last_updated}</code></td><td>Date your newest owned listing was opened &mdash; the last time the collective grew.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_countries}&hellip;{/if_countries}</code></td><td>Shows the text inside only when at least one country is counted.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- PAGINATION TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-pagination-tokens">
          <h3 class="pc-head-title">Pagination</h3>
          <p class="text-muted">Pagination is the little page picker under long lists. Put the page-row words inside <code>{each_page}</code>, and Luster repeats them for every page number it needs to show.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{if_pages}&hellip;{/if_pages}</code></td><td>Shows the pagination block only when there is more than one page.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{current_page}</code></td><td>The page the visitor is on right now.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{total_pages}</code></td><td>How many pages exist in total.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{prev_url}</code></td><td>The link for the previous page. Empty on page 1.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{next_url}</code></td><td>The link for the next page. Empty on the last page.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_prev}&hellip;{/if_prev}</code>, <code class="ec-tk">{if_next}&hellip;{/if_next}</code></td><td>Shows previous or next links only when those pages exist.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{each_page}&hellip;{/each_page}</code></td><td>Repeat box for the numbered page links.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{page_label}</code></td><td>The page number, or the gap marker when Luster is shortening a long list of pages.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{page_url}</code></td><td>The link for this page number. Empty for the current page and gap markers.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_current}&hellip;{/if_current}</code></td><td>Shows the text inside only for the current page number.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_linked}&hellip;{/if_linked}</code></td><td>Shows the text inside only for page numbers visitors can click.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_gap}&hellip;{/if_gap}</code></td><td>Shows the text inside only for a skipped-page marker.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- FORM FIELD WRAPPER TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-form-tokens">
          <h3 class="pc-head-title">Form fields</h3>
          <p class="text-muted">These control the wrapper around one form field: label, input, hint, and error. Luster repeats this wrapper for every field in a public form.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{input}</code></td><td>The actual form control, such as a text box, dropdown, checkbox group, textarea, or file upload.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{label}</code></td><td>The field label, such as Name, Email, or Country.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{field_id}</code></td><td>The HTML ID for fields that have one. Useful for <code>for="{field_id}"</code> on labels.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{required_indicator}</code></td><td>An asterisk when the field is required; empty when it is optional.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{hint}</code></td><td>Small help text for the field, when Luster has one.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{error}</code></td><td>The field error message, when there is one.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{lost_password_url}</code></td><td>The password-reset link for password fields.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_hint}&hellip;{/if_hint}</code>, <code class="ec-tk">{if_error}&hellip;{/if_error}</code></td><td>Shows hint or error markup only when that text exists.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_field_id}&hellip;{/if_field_id}</code>, <code class="ec-tk">{if_no_field_id}&hellip;{/if_no_field_id}</code></td><td>Use these when your markup needs to behave differently for checkbox or radio groups, which do not have one single input ID.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_lost_password_url}&hellip;{/if_lost_password_url}</code></td><td>Shows the text inside only when a password-reset link is available.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted" style="margin-top:.875rem">These go in whole form layout templates, such as join, code donation, affiliate application, adoption, and KIM sign-up templates. The normal <code>{field_*}</code> tokens include the saved field wrapper. Add <code>_input</code> before the closing brace, such as <code>{field_name_input}</code>, when you want the raw form control only.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{listing_title}</code>, <code class="ec-tk">{listing_url}</code></td><td>The listing or collective name and URL for the form.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{site_name}</code></td><td>The site name in KIM sign-up templates.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{field_name}</code>, <code class="ec-tk">{field_email}</code>, <code class="ec-tk">{field_url}</code></td><td>Common person, email, and website fields.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{field_country}</code>, <code class="ec-tk">{additional_fields}</code></td><td>Join-form country and custom fields. Empty when the listing does not use them.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{field_password}</code>, <code class="ec-tk">{field_password_confirm}</code></td><td>Password fields for join and KIM sign-up forms.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{field_code_image}</code>, <code class="ec-tk">{field_submitter_name}</code>, <code class="ec-tk">{field_submitter_email}</code>, <code class="ec-tk">{field_submitter_url}</code></td><td>Code donation upload and donor-credit fields.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{field_image}</code>, <code class="ec-tk">{field_message}</code></td><td>Affiliate image and application/adoption message fields.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{field_fanlisting_id}</code>, <code class="ec-tk">{field_send_copy}</code></td><td>KIM sign-up listing selector and confirmation-copy checkbox.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{captcha_block}</code>, <code class="ec-tk">{captcha_block_input}</code>, <code class="ec-tk">{captcha_question}</code></td><td>Captcha field wrapper, raw captcha input, and the captcha question text.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{submit_button}</code>, <code class="ec-tk">{clear_button}</code></td><td>Ready-made action buttons. Some forms only provide a submit button.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- SUCCESS TEMPLATE TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-success-tokens">
          <h3 class="pc-head-title">Success messages</h3>
          <p class="text-muted">These words are for the message shown after someone submits a public form. The action words let one saved message behave differently for join, update, reset, contact, affiliate, KIM, code, and adoption forms.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{action}</code></td><td>The kind of completed action, such as <code>joined</code>, <code>updated</code>, or <code>contact-sent</code>.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{fan_name}</code></td><td>The member or applicant name, when that form has one.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_title}</code></td><td>The listing name for listing-specific forms.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_url}</code></td><td>The listing link for listing-specific forms.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{sender_name}</code></td><td>Name entered on a contact form.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{sender_email}</code></td><td>Email entered on a contact form.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{site_name}</code>, <code class="ec-tk">{owner_name}</code>, <code class="ec-tk">{owner_email}</code></td><td>Site and owner details for contact confirmations.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{aff_name}</code>, <code class="ec-tk">{aff_url}</code>, <code class="ec-tk">{aff_email}</code></td><td>Affiliate application details.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_joined}</code>, <code class="ec-tk">{if_joined_pending}</code>, <code class="ec-tk">{if_updated}</code>, <code class="ec-tk">{if_updated_pending}</code>, <code class="ec-tk">{if_removed}</code></td><td>Shows the text inside only for that member action.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_reset_sent}</code>, <code class="ec-tk">{if_reset_done}</code>, <code class="ec-tk">{if_code_submitted}</code></td><td>Shows the text inside only for password reset or code donation success.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_kim_signed_up}</code>, <code class="ec-tk">{if_kim_updated}</code>, <code class="ec-tk">{if_kim_reset_done}</code></td><td>Shows the text inside only for that KIM List success.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_adopt_submitted}</code>, <code class="ec-tk">{if_contact_sent}</code>, <code class="ec-tk">{if_affiliate_apply_sent}</code></td><td>Shows the text inside only for adoption, contact, or affiliate application success.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_aff_email}</code>, <code class="ec-tk">{if_no_aff_email}</code></td><td>Shows the text inside based on whether an affiliate email was supplied.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- CODES TEMPLATE TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-codes-tokens">
          <h3 class="pc-head-title">Codes</h3>
          <p class="text-muted">These words are for code gallery templates. Use <code>{each_code}</code> for one flat list, or <code>{each_size_group}</code> when you want Luster to make a section for each button size.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{each_code}&hellip;{/each_code}</code></td><td>Repeat box for one code image.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{each_size_group}&hellip;{/each_size_group}</code></td><td>Repeat box for one size group, such as 50x50 or 100x35. Put another <code>{each_code}</code> inside it for the codes in that group.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{group_size}</code></td><td>The size label for the group.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{group_code_count}</code></td><td>How many codes are in this size group.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{code_url}</code></td><td>Direct link to the code image file.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{code_alt}</code></td><td>Suggested alt text (donor name, then size, then listing name). Good for <code>&lt;img alt="{code_alt}"&gt;</code>.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{code_width}</code>, <code class="ec-tk">{code_height}</code></td><td>Image width and height in pixels. Empty when Luster does not know the size.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{code_size}</code></td><td>The size label, such as 75x50.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{code_name}</code></td><td>The code name, if one is saved.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{code_categories}</code></td><td>Comma-separated code category names. Empty when none assigned.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{code_donor_name}</code>, <code class="ec-tk">{code_donor_url}</code></td><td>Donor name and donor link, when the code has donor credit.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_size}&hellip;{/if_size}</code></td><td>Shows only when width and height are known.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_size_changed}&hellip;{/if_size_changed}</code></td><td>Flat mode only. Shows when the code size differs from the previous code &mdash; useful for size headings.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_donor}</code>, <code class="ec-tk">{if_no_donor}</code>, <code class="ec-tk">{if_donor_url}</code>, <code class="ec-tk">{if_no_donor_url}</code></td><td>Shows donor markup only when donor details exist, or alternate markup when they do not.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_categories}&hellip;{/if_categories}</code></td><td>Shows only when the code has categories assigned.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_code_name}</code></td><td>Shows the text inside only when the code has a saved name.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- UPDATES TEMPLATE TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-updates-tokens">
          <h3 class="pc-head-title">Updates</h3>
          <p class="text-muted">These words are for updates feed row templates. Design one update inside <code>{each_update}</code>, and Luster repeats it for each update post.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{each_update}&hellip;{/each_update}</code></td><td>Repeat box for one update post.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{update_title}</code></td><td>Update title.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{update_date}</code></td><td>Friendly date, such as January 15, 2026.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{update_date_raw}</code></td><td>Plain date for machine-readable markup, such as a <code>&lt;time datetime=""&gt;</code> attribute.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{update_body_html}</code></td><td>The update body with line breaks converted to <code>&lt;br&gt;</code>. Luster escapes the text first, so visitor-supplied HTML is not allowed through.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{update_author}</code></td><td>Update author, when saved.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{update_categories}</code></td><td>Update category names, separated by commas.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_author}&hellip;{/if_author}</code>, <code class="ec-tk">{if_categories}&hellip;{/if_categories}</code></td><td>Shows author or category markup only when that information exists.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- WISHLIST TEMPLATE TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-wishlist-tokens">
          <h3 class="pc-head-title">Wishlist</h3>
          <p class="text-muted">These words are for wishlist row templates. Luster repeats the row once for each active wishlist item being shown.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{each_wishlist_item}&hellip;{/each_wishlist_item}</code></td><td>Repeat box for one wishlist item.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{item_subject}</code></td><td>The thing you want, love, or have been granted.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{item_url}</code></td><td>Link for the item, when saved.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{item_type}</code></td><td>Saved type word: <code>top</code>, <code>wanted</code>, or <code>granted</code>.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{item_type_label}</code></td><td>Friendly type label, such as Top, Wanted, or Granted.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{item_category}</code></td><td>Wishlist category, when saved.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{item_description}</code></td><td>Extra description text, when saved.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{item_image_url}</code></td><td>Direct link to the wishlist image, when one is uploaded.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_item_url}</code>, <code class="ec-tk">{if_no_item_url}</code></td><td>Shows link or no-link markup depending on whether the item has a URL.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_item_category}</code>, <code class="ec-tk">{if_item_description}</code>, <code class="ec-tk">{if_item_image}</code></td><td>Shows category, description, or image markup only when that detail exists.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_type_top}&hellip;{/if_type_top}</code>, <code class="ec-tk">{if_type_wanted}&hellip;{/if_type_wanted}</code>, <code class="ec-tk">{if_type_granted}&hellip;{/if_type_granted}</code></td><td>Shows the text inside only for that wishlist type.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <p class="text-muted" style="margin-top:.875rem">Section words for grouping your wishlist by type. Use these to add a heading like "Top Wanted" that only shows up when there's something to put under it.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{each_wishlist_item_top}&hellip;{/each_wishlist_item_top}</code></td><td>Repeat box for one Top Wanted item. Same words as the main repeat box above, but only for Top Wanted entries on this page.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{each_wishlist_item_wanted}&hellip;{/each_wishlist_item_wanted}</code></td><td>Repeat box for one Wanted item, only for Wanted entries on this page.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{each_wishlist_item_granted}&hellip;{/each_wishlist_item_granted}</code></td><td>Repeat box for one Granted item, only for Granted entries on this page.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_has_top}&hellip;{/if_has_top}</code></td><td>Shows the text inside only when this page has at least one Top Wanted item. Put your "Top Wanted" heading and its repeat box inside this.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_has_wanted}&hellip;{/if_has_wanted}</code></td><td>Shows the text inside only when this page has at least one Wanted item.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_has_granted}&hellip;{/if_has_granted}</code></td><td>Shows the text inside only when this page has at least one Granted item.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- KIM MEMBER TEMPLATE TOKENS -->
        <div class="ec-ref-panel" id="ec-ref-kim-tokens">
          <h3 class="pc-head-title">KIM members</h3>
          <p class="text-muted">These words are for the KIM member row template. Luster repeats the row once for each approved KIM member being shown.</p>
          <div class="ec-ref-card">
            <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">Template word</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code class="ec-tk">{each_kim_member}&hellip;{/each_kim_member}</code></td><td>Repeat box for one approved KIM member.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{kim_name}</code></td><td>KIM member&rsquo;s name.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{kim_url}</code></td><td>KIM member&rsquo;s website link, when saved.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_title}</code></td><td>The listing name attached to this KIM member.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{listing_subject}</code></td><td>What that listing is about.</td></tr>
                <tr><td class="act-col"><code class="ec-tk">{if_kim_url}</code>, <code class="ec-tk">{if_no_kim_url}</code></td><td>Shows link or no-link markup depending on whether the KIM member has a URL.</td></tr>
              </tbody>
            </table>
            </div>
          </div>
        </div>

        <!-- COLLECTIVE STATS OUTPUT VARIABLES -->
        <div class="ec-ref-panel" id="ec-ref-cstat-vars">
          <h3 class="pc-head-title">Stats for custom PHP</h3>
          <p class="text-muted">Advanced. These are plain PHP variables created after you require <code>collective-stats.php</code>. Number and text variables can be echoed directly; object or array variables need PHP code that picks a key or property first.</p>
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
                <tr><td class="act-col"><code>$luster_joined_approved</code></td><td>Number of active joined listings that are approved, not pending or upcoming.</td></tr>
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
                <tr><td class="act-col"><code>$luster_countries_count</code></td><td>Number of different countries represented by approved members across all listings.</td></tr>
                <tr><td class="act-col"><code>$luster_collective_last_updated</code></td><td>Date your newest owned listing was opened, formatted "Month Dth Year" (or "Never" if no owned listing has an opened date).</td></tr>
                <tr><td class="act-col"><code>$luster_total_cats</code></td><td>Number of owned-listing categories.</td></tr>
                <tr><td class="act-col"><code>$luster_owned_current</code></td><td>Same number as <code>$luster_owned_active</code>.</td></tr>
                <tr><td class="act-col"><code>$luster_collective_fans</code></td><td>Same number as <code>$luster_members_total</code>.</td></tr>
              </tbody>
            </table>
              </div>
              <p class="text-muted" style="margin-top:.875rem">Enthusiast-compatible aliases. These are helpful when moving an older Enthusiast collective page into Luster.</p>
              <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th class="act-col">PHP variable</th><th>What it shows</th></tr></thead>
              <tbody>
                <tr><td class="act-col"><code>$owned</code>, <code>$joined</code>, <code>$total_cats</code></td><td>Short names for total owned listings, total joined listings, and total owned-listing categories.</td></tr>
                <tr><td class="act-col"><code>$owned_current</code>, <code>$owned_upcoming</code>, <code>$owned_pending</code></td><td>Owned listing counts by status.</td></tr>
                <tr><td class="act-col"><code>$joined_current</code>, <code>$joined_approved</code>, <code>$joined_upcoming</code>, <code>$joined_pending</code></td><td>Joined listing counts by status. Approved excludes pending and upcoming joined listings.</td></tr>
                <tr><td class="act-col"><code>$affiliates_collective</code>, <code>$collective_affiliates</code></td><td>Two names for the active collective affiliate count.</td></tr>
                <tr><td class="act-col"><code>$collective_total_fans_approved</code>, <code>$collective_total_fans_pending</code>, <code>$collective_total_fans</code></td><td>Approved, pending, and total member counts across all listings.</td></tr>
                <tr><td class="act-col"><code>$collective_categories</code>, <code>$countries_count</code></td><td>Owned-listing category count and country count.</td></tr>
                <tr><td class="act-col"><code>$collective_last_updated</code></td><td>Same value as <code>$luster_collective_last_updated</code>.</td></tr>
                <tr><td class="act-col"><code>$owned_growth_rate</code>, <code>$joined_growth_rate</code>, <code>$collective_fans_growth_rate</code></td><td>Growth-rate aliases for old templates.</td></tr>
                <tr><td class="act-col"><code>$owned_newest</code>, <code>$joined_newest</code>, <code>$owned_random</code>, <code>$joined_random</code></td><td>Flat arrays for old templates. Use keys like <code>['url']</code>, <code>['name']</code>, <code>['title']</code>, <code>['subject']</code>, <code>['desc']</code>, <code>['listingtype']</code>, <code>['category']</code>, <code>['opened']</code>, or <code>['added']</code> depending on the array.</td></tr>
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
           class="btn btn-quaternary" style="text-decoration:none">&#x2197;&ensp;Open in New Tab</a>
        <button type="button" id="ec-prev-close" class="btn btn-quaternary">&#x2715;&ensp;Close</button>
      </div>
    </div>
    <div class="modal-body--iframe">
      <iframe id="ec-prev-iframe" src="" title="Embed preview"></iframe>
    </div>
  </div>
</div>
