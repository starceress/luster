<?php

declare(strict_types=1);

/**
 * Collective admin page.
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

$ownedVal                = $ct['owned_template']                           ?? $defaults['owned_template'];
$joinedVal               = $ct['joined_template']                          ?? $defaults['joined_template'];
$statsVal                = $ct['collective_stats_template']                 ?? $defaults['collective_stats_template'];
$ownedSectionsVal        = $ct['owned_sections_template']                  ?? $defaults['owned_sections_template'];
$joinedSectionsVal       = $ct['joined_sections_template']                 ?? $defaults['joined_sections_template'];
$collAffSectionVal       = $ct['collective_affiliates_section_template']   ?? $defaults['collective_affiliates_section_template'];
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
          <textarea name="owned_template" id="owned_template" class="form tpl-textarea" spellcheck="false"><?= View::e($ownedVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-ghost btn-sm btn-tpl-reset" data-textarea="owned_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Owned Listings — Sections Layout</div>
        <p class="form-hint mb-sm">Used when <code>$luster_status = 'all'</code>. Wraps the row template output in sections by listing status. Omit a conditional block to hide that status. <strong>Clear this field to disable sections mode</strong> and fall back to flat output. Note: pending/trouble listings are excluded by default — set <code>$luster_exclude_pending = false</code> to include them in <code>{pending_content}</code>.</p>
        <div class="form-group">
          <textarea name="owned_sections_template" id="owned_sections_template" class="form tpl-textarea tpl-textarea-lg" spellcheck="false"><?= View::e($ownedSectionsVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-ghost btn-sm btn-tpl-reset" data-textarea="owned_sections_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Joined Listings</div>
        <div class="form-group">
          <textarea name="joined_template" id="joined_template" class="form tpl-textarea" spellcheck="false"><?= View::e($joinedVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-ghost btn-sm btn-tpl-reset" data-textarea="joined_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Joined Listings — Sections Layout</div>
        <p class="form-hint mb-sm">Used when <code>$luster_category = 'all'</code>. Wraps the row template output in a structured header block. Only active listings are shown. <strong>Clear this field to disable sections mode</strong> and fall back to the standard grouped-by-category output.</p>
        <div class="form-group">
          <textarea name="joined_sections_template" id="joined_sections_template" class="form tpl-textarea tpl-textarea-lg" spellcheck="false"><?= View::e($joinedSectionsVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-ghost btn-sm btn-tpl-reset" data-textarea="joined_sections_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Collective Affiliates — Section Layout</div>
        <p class="form-hint mb-sm">Controls the full affiliates section on <code>/affiliates</code> and the collective affiliates embed. Use <code>{affiliates}</code> to place the rendered rows. <strong>Leave blank to use the built-in layout.</strong></p>
        <div class="form-group">
          <textarea name="collective_affiliates_section_template" id="collective_affiliates_section_template" class="form tpl-textarea tpl-textarea-lg" spellcheck="false"><?= View::e($collAffSectionVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-ghost btn-sm btn-tpl-reset" data-textarea="collective_affiliates_section_template">Clear</button>
        </div>

        <div class="pc-section-label">Statistics</div>
        <div class="form-group">
          <textarea name="collective_stats_template" id="collective_stats_template" class="form tpl-textarea tpl-textarea-lg" spellcheck="false"><?= View::e($statsVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-ghost btn-sm btn-tpl-reset" data-textarea="collective_stats_template">Reset to Default</button>
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

      <details class="card chevron">
        <summary class="chevron-head">Owned Sections Tokens</summary>
        <div class="chevron-body">
          <table class="et-token-table">
            <tbody>
              <tr><td><code>{current_content}</code></td><td>Rendered current rows</td></tr>
              <tr><td><code>{upcoming_content}</code></td><td>Rendered upcoming rows</td></tr>
              <tr><td><code>{pending_content}</code></td><td>Rendered pending rows</td></tr>
              <tr><td><code>{current_count}</code></td><td>Number of current listings</td></tr>
              <tr><td><code>{upcoming_count}</code></td><td>Number of upcoming listings</td></tr>
              <tr><td><code>{pending_count}</code></td><td>Number of pending listings</td></tr>
              <tr><td><code>{total_count}</code></td><td>Total listings shown</td></tr>
              <tr><td><code>{if_current_listings}&hellip;{/if_current_listings}</code></td><td>Show block only when current listings exist</td></tr>
              <tr><td><code>{if_upcoming_listings}&hellip;{/if_upcoming_listings}</code></td><td>Show block only when upcoming listings exist</td></tr>
              <tr><td><code>{if_pending_listings}&hellip;{/if_pending_listings}</code></td><td>Show block only when pending listings exist</td></tr>
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

      <details class="card chevron">
        <summary class="chevron-head">Joined Sections Tokens</summary>
        <div class="chevron-body">
          <table class="et-token-table">
            <tbody>
              <tr><td><code>{current_content}</code></td><td>Rendered active listing rows</td></tr>
              <tr><td><code>{current_count}</code></td><td>Number of active listings</td></tr>
              <tr><td><code>{total_count}</code></td><td>Total active listings</td></tr>
              <tr><td><code>{if_current_listings}&hellip;{/if_current_listings}</code></td><td>Show block only when active listings exist</td></tr>
            </tbody>
          </table>
          <p class="form-hint mt-sm">Only active listings are included. Inactive joined listings are never exposed publicly.</p>
        </div>
      </details>

      <details class="card chevron">
        <summary class="chevron-head">Affiliates Section Tokens</summary>
        <div class="chevron-body">
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="collective_affiliates_section_template" data-token="{affiliates}"><td><code>{affiliates}</code></td><td>Rendered affiliate rows</td></tr>
              <tr class="tok-row" data-textarea="collective_affiliates_section_template" data-token="{affiliates_count}"><td><code>{affiliates_count}</code></td><td>Total affiliate count</td></tr>
              <tr class="tok-row" data-textarea="collective_affiliates_section_template" data-token="{affiliates_list}"><td><code>{affiliates_list}</code></td><td>Comma-separated link list</td></tr>
              <tr><td><code>{if_affiliates}&hellip;{/if_affiliates}</code></td><td>Shown when count &gt; 0</td></tr>
              <tr><td><code>{if_no_affiliates}&hellip;{/if_no_affiliates}</code></td><td>Shown when count = 0</td></tr>
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
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{countries_count}">
                <td><code>{countries_count}</code></td><td>Distinct countries across all members</td>
              </tr>
              <tr>
                <td><code>{if_countries}&hellip;{/if_countries}</code></td><td>Show block only when countries count &gt; 0</td>
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
