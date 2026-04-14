<?php

declare(strict_types=1);

/**
 * Collective Templates admin page.
 *
 * @var array  $collective_templates
 * @var string $success
 * @var string $error
 * @var string $base_url
 * @var string $csrf_token
 */

use Luster\Core\View;

$b  = rtrim(View::e($base_url), '/');
$ct = $collective_templates ?? [];

$defaultOwned  = '<li><a href="{listing_url}" target="_blank" rel="noopener noreferrer">{listing_name}</a> — {listing_subject}</li>';
$defaultJoined = '<li><a href="{listing_url}" target="_blank" rel="noopener noreferrer">{listing_name}</a> — {listing_category}</li>';
$defaultStats  = '<dl>' . "\n"
               . '  <dt>Owned listings</dt>' . "\n"
               . '  <dd>{owned_active} open, {owned_upcoming} upcoming</dd>' . "\n"
               . '  <dt>Joined listings</dt>' . "\n"
               . '  <dd>{joined_active}</dd>' . "\n"
               . '  <dt>Total members</dt>' . "\n"
               . '  <dd>{members_approved} approved, {members_pending} pending</dd>' . "\n"
               . '</dl>' . "\n"
               . '<p>Newest: <a href="{owned_newest_url}">{owned_newest_name}</a></p>' . "\n"
               . '<p>Featured: <a href="{owned_random_url}">{owned_random_name}</a></p>';

$ownedVal  = $ct['owned_template']            ?? $defaultOwned;
$joinedVal = $ct['joined_template']           ?? $defaultJoined;
$statsVal  = $ct['collective_stats_template'] ?? $defaultStats;
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/listings" class="link">&larr; Back to OwnedListings</a>
</div>

<div class="callout">
  These templates control how each item looks in the Owned Listings, Joined Listings, and Site Statistics embeds on your collective page. Write plain HTML and drop in the tokens shown; Luster replaces them with real data automatically.
</div>

<form method="POST" action="<?= $b ?>/admin/settings/templates">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="composer-grid">

    <!-- ── Left: editor card ── -->
    <div class="gradient-card">
      <div class="pc-head">
        <div class="pc-head-meta">
          <div class="pc-head-title">Collective HTML Templates</div>
          <div class="pc-head-sub">Controls how listings and stats render in your collective embeds.</div>
        </div>
      </div>

      <!-- Owned Listings -->
      <div class="tpl-section">
        <div class="tpl-section-head">
          <span class="tpl-section-label">Owned Listings</span>
          <div class="tpl-section-actions">
            <button type="button" class="btn-tpl-preview"
                    data-textarea="owned_template"
                    data-kind="listing"
                    data-title="Owned Listings — Preview">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              Preview
            </button>
            <button type="button" class="btn-ghost btn-sm" data-textarea="owned_template">Reset to default</button>
          </div>
        </div>
        <textarea name="owned_template" id="owned_template"
                  class="tpl-textarea" spellcheck="false"><?= View::e($ownedVal) ?></textarea>
      </div>

      <!-- Joined Listings -->
      <div class="tpl-section">
        <div class="tpl-section-head">
          <span class="tpl-section-label">Joined Listings</span>
          <div class="tpl-section-actions">
            <button type="button" class="btn-tpl-preview"
                    data-textarea="joined_template"
                    data-kind="joined"
                    data-title="Joined Listings — Preview">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              Preview
            </button>
            <button type="button" class="btn-ghost btn-sm" data-textarea="joined_template">Reset to default</button>
          </div>
        </div>
        <textarea name="joined_template" id="joined_template"
                  class="tpl-textarea" spellcheck="false"><?= View::e($joinedVal) ?></textarea>
      </div>

      <!-- Site Statistics -->
      <div class="tpl-section">
        <div class="tpl-section-head">
          <span class="tpl-section-label">Site Statistics Template</span>
          <div class="tpl-section-actions">
            <button type="button" class="btn-tpl-preview"
                    data-textarea="collective_stats_template"
                    data-kind="stats"
                    data-title="Site Statistics — Preview">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              Preview
            </button>
            <button type="button" class="btn-ghost btn-sm" data-textarea="collective_stats_template">Reset to default</button>
          </div>
        </div>
        <textarea name="collective_stats_template" id="collective_stats_template"
                  class="tpl-textarea tpl-textarea-lg" spellcheck="false"><?= View::e($statsVal) ?></textarea>
      </div>

      <div class="card-footer">
        <button type="submit" class="btn btn-primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Save Templates
        </button>
      </div>
    </div><!-- /.main-card -->

    <div class="composer-side">

      <!-- Owned Listings -->
      <details class="card chevron" open>
        <summary class="card-head">Owned Listings <?= View::icon('star') ?></summary>
        <div class="card-body">
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_name}">
                <td><code>{listing_name}</code></td><td>Fanlisting name</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_url}">
                <td><code>{listing_url}</code></td><td>Fanlisting URL</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_subject}">
                <td><code>{listing_subject}</code></td><td>Subject / fandom</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_status}">
                <td><code>{listing_status}</code></td><td>Current, upcoming, etc.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>

      <!-- Joined Listings -->
      <details class="card chevron" open>
        <summary class="chevron-head">
          <svg class="chevron-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
          <span class="chevron-title">Joined Listings</span>
          <svg class="chevron-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="chevron-body">
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_name}">
                <td><code>{listing_name}</code></td><td>Fanlisting name</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_url}">
                <td><code>{listing_url}</code></td><td>Fanlisting URL</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_category}">
                <td><code>{listing_category}</code></td><td>TAFL category</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_image}">
                <td><code>{listing_image}</code></td><td>Listing image tag</td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>

      <!-- Statistics -->
      <details class="card chevron" open>
        <summary class="chevron-head">
          <svg class="chevron-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
          <span class="chevron-title">Statistics</span>
          <svg class="chevron-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="chevron-body">
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_active}">
                <td><code>{owned_active}</code></td><td>Active owned count</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_upcoming}">
                <td><code>{owned_upcoming}</code></td><td>Upcoming owned count</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_trouble}">
                <td><code>{owned_trouble}</code></td><td>On-hiatus count</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{joined_active}">
                <td><code>{joined_active}</code></td><td>Active joined count</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{affiliates}">
                <td><code>{affiliates}</code></td><td>Affiliate count</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{members_total}">
                <td><code>{members_total}</code></td><td>Total members (all)</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{members_approved}">
                <td><code>{members_approved}</code></td><td>Approved members</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{members_pending}">
                <td><code>{members_pending}</code></td><td>Pending members</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_newest_name}">
                <td><code>{owned_newest_name}</code></td><td>Newest listing name</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_newest_url}">
                <td><code>{owned_newest_url}</code></td><td>Newest listing URL</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_random_name}">
                <td><code>{owned_random_name}</code></td><td>Random listing name</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_random_url}">
                <td><code>{owned_random_url}</code></td><td>Random listing URL</td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>

    </div><!-- /.tpl-tokens-col -->

  </div><!-- /.tpl-layout -->
</form>

<!-- Preview modal -->
<div class="tpl-preview-modal-backdrop" id="tpl-preview-modal-backdrop">
  <div class="tpl-preview-modal">
    <div class="tpl-preview-modal-head">
      <span class="tpl-preview-modal-title" id="tpl-preview-modal-title">Preview</span>
      <button type="button" class="tpl-preview-modal-close" id="tpl-preview-modal-close">&times;</button>
    </div>
    <div class="tpl-preview-modal-body">
      <div class="tpl-preview-modal-label">Rendered output (with sample data)</div>
      <div class="tpl-preview-rendered" id="tpl-preview-modal-content"></div>
      <p class="tpl-preview-note"><strong>Sample data only.</strong> Tokens are replaced with real values when Luster renders the embed on your collective page.</p>
    </div>
  </div>
</div>

<!-- Default values for reset buttons (read by admin.js) -->
<script type="application/json" id="luster-coltpl-defaults">
<?= json_encode([
    'owned_template'            => $defaultOwned,
    'joined_template'           => $defaultJoined,
    'collective_stats_template' => $defaultStats,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>

<!-- Sample data for preview modal (read by admin.js) -->
<script type="application/json" id="luster-tpl-samples">
{
  "listing": {
    "{listing_name}":    "My Fanlisting",
    "{listing_url}":     "#",
    "{listing_subject}": "Sample Subject",
    "{listing_status}":  "open",
    "{listing_image}":   ""
  },
  "joined": {
    "{listing_name}":     "A Joined Fanlisting",
    "{listing_url}":      "#",
    "{listing_subject}":  "Sample Subject",
    "{listing_category}": "Anime \u2192 General",
    "{listing_image}":    ""
  },
  "stats": {
    "{owned_active}":      "12",
    "{owned_upcoming}":    "2",
    "{owned_trouble}":     "0",
    "{joined_active}":     "5",
    "{affiliates}":        "8",
    "{members_total}":     "145",
    "{members_approved}":  "143",
    "{members_pending}":   "2",
    "{owned_newest_name}": "My Newest Listing",
    "{owned_newest_url}":  "#",
    "{owned_random_name}": "Featured Listing",
    "{owned_random_url}":  "#"
  }
}
</script>
