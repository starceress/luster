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
use Luster\Repositories\SiteSettingsRepo;

$b        = rtrim(View::e($base_url), '/');
$ct       = $collective_templates ?? [];
$defaults = SiteSettingsRepo::collectiveDefaults();

$ownedVal  = $ct['owned_template'] ?? $defaults['owned_template'];
$joinedVal = $ct['joined_template'] ?? $defaults['joined_template'];
$statsVal  = $ct['collective_stats_template'] ?? $defaults['collective_stats_template'];
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/listings" class="link">&larr; Back to Owned Listings</a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/settings/templates" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="composer-grid">
    <div class="gradient-card">
      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('embed') ?></div>
        <div class="pc-head-meta">
          <div class="pc-head-title">Collective HTML Templates</div>
          <div class="pc-head-sub">Controls how collective-related embeds render across your owned listings, joined listings, and statistics snippets.</div>
        </div>
      </div>

      <div class="pc-section">
        <div class="pc-section-label">Owned Listings</div>
        <div class="form-group">
          <textarea name="owned_template" id="owned_template" class="form-textarea tpl-textarea" spellcheck="false"><?= View::e($ownedVal) ?></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost btn-sm" data-textarea="owned_template">Reset to Default</button>
        </div>
      </div>

      <div class="pc-section">
        <div class="pc-section-label">Joined Listings</div>
        <div class="form-group">
          <textarea name="joined_template" id="joined_template" class="form-textarea tpl-textarea" spellcheck="false"><?= View::e($joinedVal) ?></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost btn-sm" data-textarea="joined_template">Reset to Default</button>
        </div>
      </div>

      <div class="pc-section">
        <div class="pc-section-label">Statistics</div>
        <div class="form-group">
          <textarea name="collective_stats_template" id="collective_stats_template" class="form-textarea tpl-textarea tpl-textarea-lg" spellcheck="false"><?= View::e($statsVal) ?></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost btn-sm" data-textarea="collective_stats_template">Reset to Default</button>
        </div>
      </div>

      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Save Templates</button>
        <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
      </div>
    </div>

    <div class="composer-side">
      <details class="card chevron" open>
        <summary class="chevron-head">Owned Listings Tokens</summary>
        <div class="chevron-body">
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_name}">
                <td><code>{listing_name}</code></td><td>Listing name</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_url}">
                <td><code>{listing_url}</code></td><td>Listing URL</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_subject}">
                <td><code>{listing_subject}</code></td><td>Subject</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_status}">
                <td><code>{listing_status}</code></td><td>Listing status</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{date_opened}">
                <td><code>{date_opened}</code></td><td>Date opened</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{date_updated}">
                <td><code>{date_updated}</code></td><td>Date last updated</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_category}">
                <td><code>{listing_category}</code></td><td>Category name</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_image}">
                <td><code>{listing_image}</code></td><td>Listing image tag</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_email}">
                <td><code>{listing_email}</code></td><td>Contact email</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_type}">
                <td><code>{listing_type}</code></td><td>Listing type</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{member_count}">
                <td><code>{member_count}</code></td><td>Approved member count</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{country_count}">
                <td><code>{country_count}</code></td><td>Distinct countries</td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>

      <details class="card chevron" open>
        <summary class="chevron-head">Joined Listings Tokens</summary>
        <div class="chevron-body">
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_name}">
                <td><code>{listing_name}</code></td><td>Listing name</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_url}">
                <td><code>{listing_url}</code></td><td>Listing URL</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_category}">
                <td><code>{listing_category}</code></td><td>Category</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{image}">
                <td><code>{image}</code></td><td>Listing image</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_image}">
                <td><code>{listing_image}</code></td><td>Listing image (alias)</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_status}">
                <td><code>{listing_status}</code></td><td>Status</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{date_joined}">
                <td><code>{date_joined}</code></td><td>Date joined</td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>

      <details class="card chevron" open>
        <summary class="chevron-head">Statistics Tokens</summary>
        <div class="chevron-body">
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_active}">
                <td><code>{owned_active}</code></td><td>Active owned count</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_upcoming}">
                <td><code>{owned_upcoming}</code></td><td>Upcoming owned count</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_pending}">
                <td><code>{owned_pending}</code></td><td>Pending owned count</td>
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
    </div>
  </div>
</form>

<script type="application/json" id="luster-coltpl-defaults">
<?= json_encode($defaults, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>
