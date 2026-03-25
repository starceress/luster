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

<div class="page-topbar" style="margin-bottom:1.25rem">
  <a href="<?= $b ?>/admin/listings" class="back-link neb-link">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
    Back to Listings
  </a>
</div>

<div class="tpl-callout">
  These templates control how each item looks in the <strong class="tpl-hl">Owned Listings</strong>,
  <strong class="tpl-hl">Joined Listings</strong>, and <strong class="tpl-hl">Site Statistics</strong>
  embeds on your collective page. Write plain HTML and drop in the tokens shown &mdash; Luster replaces them with real data automatically.
</div>

<form method="POST" action="<?= $b ?>/admin/settings/templates">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="tpl-layout">

    <!-- ── Left: editor card ── -->
    <div class="main-card">
      <div class="tpl-card-head">
        <div class="tpl-card-head-title">Collective HTML Templates</div>
        <div class="tpl-card-head-sub">Controls how listings and stats render in your collective embeds.</div>
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
            <button type="button" class="btn-reset" data-textarea="owned_template">Reset to default</button>
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
            <button type="button" class="btn-reset" data-textarea="joined_template">Reset to default</button>
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
            <button type="button" class="btn-reset" data-textarea="collective_stats_template">Reset to default</button>
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

    <!-- ── Right: tokens sidebar ── -->
    <div class="tpl-tokens-col">
      <div class="tokens-card">
        <div class="tokens-card-head">
          <span class="tokens-card-title">Available Tokens</span>
        </div>
        <div class="tokens-tip">
          <span class="tokens-tip-star">✦</span>
          <span>Click any token to insert it at your cursor. Changes take effect after saving.</span>
        </div>

        <!-- Owned Listings group -->
        <div class="tok-group">
          <button type="button" class="tok-group-btn" aria-expanded="true">
            <svg class="tok-group-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            Owned Listings
            <svg class="tok-group-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div class="tok-body open">
            <div class="tok-row" data-textarea="owned_template" data-token="{listing_name}">
              <span class="tok-name">{listing_name}</span>
              <span class="tok-desc">Fanlisting name</span>
            </div>
            <div class="tok-row" data-textarea="owned_template" data-token="{listing_url}">
              <span class="tok-name">{listing_url}</span>
              <span class="tok-desc">Fanlisting URL</span>
            </div>
            <div class="tok-row" data-textarea="owned_template" data-token="{listing_subject}">
              <span class="tok-name">{listing_subject}</span>
              <span class="tok-desc">Subject / fandom</span>
            </div>
            <div class="tok-row" data-textarea="owned_template" data-token="{listing_status}">
              <span class="tok-name">{listing_status}</span>
              <span class="tok-desc">Current, upcoming, etc.</span>
            </div>
          </div>
        </div>

        <!-- Joined Listings group -->
        <div class="tok-group">
          <button type="button" class="tok-group-btn" aria-expanded="true">
            <svg class="tok-group-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
            Joined Listings
            <svg class="tok-group-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div class="tok-body open">
            <div class="tok-row" data-textarea="joined_template" data-token="{listing_name}">
              <span class="tok-name">{listing_name}</span>
              <span class="tok-desc">Fanlisting name</span>
            </div>
            <div class="tok-row" data-textarea="joined_template" data-token="{listing_url}">
              <span class="tok-name">{listing_url}</span>
              <span class="tok-desc">Fanlisting URL</span>
            </div>
            <div class="tok-row" data-textarea="joined_template" data-token="{listing_category}">
              <span class="tok-name">{listing_category}</span>
              <span class="tok-desc">TAFL category</span>
            </div>
            <div class="tok-row" data-textarea="joined_template" data-token="{listing_image}">
              <span class="tok-name">{listing_image}</span>
              <span class="tok-desc">Listing image tag</span>
            </div>
          </div>
        </div>

        <!-- Statistics group -->
        <div class="tok-group">
          <button type="button" class="tok-group-btn" aria-expanded="true">
            <svg class="tok-group-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            Statistics
            <svg class="tok-group-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div class="tok-body open">
            <div class="tok-row" data-textarea="collective_stats_template" data-token="{owned_active}">
              <span class="tok-name">{owned_active}</span>
              <span class="tok-desc">Active owned count</span>
            </div>
            <div class="tok-row" data-textarea="collective_stats_template" data-token="{owned_upcoming}">
              <span class="tok-name">{owned_upcoming}</span>
              <span class="tok-desc">Upcoming owned count</span>
            </div>
            <div class="tok-row" data-textarea="collective_stats_template" data-token="{owned_trouble}">
              <span class="tok-name">{owned_trouble}</span>
              <span class="tok-desc">On-hiatus count</span>
            </div>
            <div class="tok-row" data-textarea="collective_stats_template" data-token="{joined_active}">
              <span class="tok-name">{joined_active}</span>
              <span class="tok-desc">Active joined count</span>
            </div>
            <div class="tok-row" data-textarea="collective_stats_template" data-token="{affiliates}">
              <span class="tok-name">{affiliates}</span>
              <span class="tok-desc">Affiliate count</span>
            </div>
            <div class="tok-row" data-textarea="collective_stats_template" data-token="{members_total}">
              <span class="tok-name">{members_total}</span>
              <span class="tok-desc">Total members (all)</span>
            </div>
            <div class="tok-row" data-textarea="collective_stats_template" data-token="{members_approved}">
              <span class="tok-name">{members_approved}</span>
              <span class="tok-desc">Approved members</span>
            </div>
            <div class="tok-row" data-textarea="collective_stats_template" data-token="{members_pending}">
              <span class="tok-name">{members_pending}</span>
              <span class="tok-desc">Pending members</span>
            </div>
            <div class="tok-row" data-textarea="collective_stats_template" data-token="{owned_newest_name}">
              <span class="tok-name">{owned_newest_name}</span>
              <span class="tok-desc">Newest listing name</span>
            </div>
            <div class="tok-row" data-textarea="collective_stats_template" data-token="{owned_newest_url}">
              <span class="tok-name">{owned_newest_url}</span>
              <span class="tok-desc">Newest listing URL</span>
            </div>
            <div class="tok-row" data-textarea="collective_stats_template" data-token="{owned_random_name}">
              <span class="tok-name">{owned_random_name}</span>
              <span class="tok-desc">Random listing name</span>
            </div>
            <div class="tok-row" data-textarea="collective_stats_template" data-token="{owned_random_url}">
              <span class="tok-name">{owned_random_url}</span>
              <span class="tok-desc">Random listing URL</span>
            </div>
          </div>
        </div>

      </div><!-- /.tokens-card -->
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
