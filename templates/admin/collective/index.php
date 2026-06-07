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
$collAffSectionVal       = $ct['collective_affiliates_section_template']   ?? $defaults['collective_affiliates_section_template'];
?>

<div class="toolbar">
  <div class="actions-row actions-row--right">
    <a href="<?= $b ?>/admin/collective/settings" class="btn btn-quaternary btn-sm">Collective Settings</a>
  </div>
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
          <textarea name="owned_template" id="owned_template" class="form form-mono" spellcheck="false"><?= View::e($ownedVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="owned_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Joined Listings</div>
        <div class="form-group">
          <textarea name="joined_template" id="joined_template" class="form form-mono" spellcheck="false"><?= View::e($joinedVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="joined_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Collective Affiliates — Section Layout</div>
        <p class="form-hint mb-sm">Controls the full affiliates section on <code>/affiliates</code> and the collective affiliates embed. Use <code>{affiliates}</code> to place the rendered rows. <strong>Leave blank to use the built-in layout.</strong></p>
        <div class="form-group">
          <textarea name="collective_affiliates_section_template" id="collective_affiliates_section_template" class="form form-mono" spellcheck="false"><?= View::e($collAffSectionVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_affiliates_section_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Statistics</div>
        <div class="form-group">
          <textarea name="collective_stats_template" id="collective_stats_template" class="form form-mono" spellcheck="false"><?= View::e($statsVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_stats_template">Reset to Default</button>
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
              <tr><td><code>{each_listing}&hellip;{/each_listing}</code></td><td>Loop — every listing in the active pool, flat (the built-in default)</td></tr>
              <tr><td><code>{each_current}&hellip;{/each_current}</code></td><td>Loop — current listings only, within a sectioned layout (alternative to <code>{each_listing}</code> — reads best on an &ldquo;all&rdquo; overview, but applies to every owned-listing embed using this template)</td></tr>
              <tr><td><code>{each_upcoming}&hellip;{/each_upcoming}</code></td><td>Loop — upcoming listings only, within a sectioned layout</td></tr>
              <tr><td><code>{each_pending}&hellip;{/each_pending}</code></td><td>Loop — pending listings only, within a sectioned layout</td></tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_title}">
                <td><code>{listing_title}</code></td><td>Listing name</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_url}">
                <td><code>{listing_url}</code></td><td>Listing URL</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_subject}">
                <td><code>{listing_subject}</code></td><td>Subject</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_status}">
                <td><code>{listing_status}</code></td><td>Raw status string: current, upcoming, or pending</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{date_opened}">
                <td><code>{date_opened}</code></td><td>Date opened</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{date_updated}">
                <td><code>{date_updated}</code></td><td>Date last updated</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_category}">
                <td><code>{listing_category}</code></td><td>Category name(s), comma-separated</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_image_url}">
                <td><code>{listing_image_url}</code></td><td>Bare image URL (escaped); empty when no image</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_image_html}">
                <td><code>{listing_image_html}</code></td><td>Rendered <code>&lt;img&gt;</code> tag; empty when no image</td>
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
              <tr class="tok-row" data-textarea="owned_template" data-token="{member_pending_count}">
                <td><code>{member_pending_count}</code></td><td>Pending member count for this listing</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{country_count}">
                <td><code>{country_count}</code></td><td>Distinct countries (alias: <code>{countries_count}</code>)</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{approved_by}">
                <td><code>{approved_by}</code></td><td>Network registration: <code>tfl</code>, <code>tafl</code>, or empty</td>
              </tr>
              <tr><td><code>{if_listing_image}&hellip;{/if_listing_image}</code></td><td>Shown when the listing has an image</td></tr>
              <tr><td><code>{if_countries}&hellip;{/if_countries}</code></td><td>Shown when country count &gt; 0</td></tr>
              <tr><td><code>{if_pending}&hellip;{/if_pending}</code></td><td>Shown when this listing's pending member count &gt; 0</td></tr>
              <tr><td><code>{if_status_current}&hellip;{/if_status_current}</code></td><td>Shown when status = current</td></tr>
              <tr><td><code>{if_status_upcoming}&hellip;{/if_status_upcoming}</code></td><td>Shown when status = upcoming</td></tr>
              <tr><td><code>{if_status_pending}&hellip;{/if_status_pending}</code></td><td>Shown when status = pending</td></tr>
              <tr><td><code>{if_tfl}&hellip;{/if_tfl}</code></td><td>Shown when registered with TFL</td></tr>
              <tr><td><code>{if_tafl}&hellip;{/if_tafl}</code></td><td>Shown when registered with TAFL</td></tr>
              <tr><td><code>{if_approved}&hellip;{/if_approved}</code></td><td>Shown when registered with either network</td></tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{current_count}">
                <td><code>{current_count}</code></td><td>Section count — current listings (sectioned mode)</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{upcoming_count}">
                <td><code>{upcoming_count}</code></td><td>Section count — upcoming listings (sectioned mode)</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{pending_count}">
                <td><code>{pending_count}</code></td><td>Section count — pending listings (sectioned mode)</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{total_count}">
                <td><code>{total_count}</code></td><td>Section count — all listings (sectioned mode)</td>
              </tr>
              <tr><td><code>{if_current_listings}&hellip;{/if_current_listings}</code></td><td>Shown when the current group is non-empty</td></tr>
              <tr><td><code>{if_upcoming_listings}&hellip;{/if_upcoming_listings}</code></td><td>Shown when the upcoming group is non-empty</td></tr>
              <tr><td><code>{if_pending_listings}&hellip;{/if_pending_listings}</code></td><td>Shown when the pending group is non-empty</td></tr>
            </tbody>
          </table>
          <p class="form-hint mt-sm">
            Use <code>{each_listing}&hellip;{/each_listing}</code> for a single flat list (the default), or
            <code>{each_current}</code> / <code>{each_upcoming}</code> / <code>{each_pending}</code>
            to build separate sections. This is the same template for every owned-listing
            embed — <code>owned</code> (any status), <code>latest-listing</code>, and
            <code>random-listing</code> — so a sectioned template renders its grouped layout
            everywhere it's used, including single-listing widgets (current/upcoming/pending
            groups simply stay empty and hidden via <code>{if_*_listings}</code> when a pool
            excludes them). Sectioned layouts read best on an &ldquo;all&rdquo; overview page.
            The template controls its own wrapper markup (e.g. <code>&lt;ul&gt;</code>/<code>&lt;li&gt;</code>
            or grouped <code>&lt;h3&gt;</code>/<code>&lt;ul&gt;</code> sections).
          </p>
        </div>
      </details>

      <details class="card chevron" open>
        <summary class="chevron-head">Joined Listings Tokens</summary>
        <div class="chevron-body">
          <table class="et-token-table">
            <tbody>
              <tr><td><code>{each_joined}&hellip;{/each_joined}</code></td><td>Loop — all joined listings, flat</td></tr>
              <tr><td><code>{each_current_joined}&hellip;{/each_current_joined}</code></td><td>Loop — the active joined-listing pool, ordered by category (works for a single selected category as well as &ldquo;all&rdquo;; reads best on an all-categories overview)</td></tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_title}">
                <td><code>{listing_title}</code></td><td>Listing name</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_url}">
                <td><code>{listing_url}</code></td><td>Listing URL</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_category}">
                <td><code>{listing_category}</code></td><td>Category</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_image_url}">
                <td><code>{listing_image_url}</code></td><td>Bare image URL (escaped); empty when no image</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_image_html}">
                <td><code>{listing_image_html}</code></td><td>Rendered <code>&lt;img&gt;</code> tag; empty when no image</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_status}">
                <td><code>{listing_status}</code></td><td>Status</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{date_joined}">
                <td><code>{date_joined}</code></td><td>Date joined</td>
              </tr>
              <tr><td><code>{if_listing_image}&hellip;{/if_listing_image}</code></td><td>Shown when the listing has an image</td></tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{current_count}">
                <td><code>{current_count}</code></td><td>Section count — listings shown (grouped mode)</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{total_count}">
                <td><code>{total_count}</code></td><td>Section count — total listings (grouped mode)</td>
              </tr>
              <tr><td><code>{if_current_listings}&hellip;{/if_current_listings}</code></td><td>Shown when the group is non-empty</td></tr>
            </tbody>
          </table>
          <p class="form-hint mt-sm">
            Use <code>{each_joined}&hellip;{/each_joined}</code> for a flat list, or
            <code>{each_current_joined}&hellip;{/each_current_joined}</code> to render the active
            joined-listing pool ordered by category. It works for a single selected category as
            well as an all-categories overview. The template controls its own wrapper markup.
          </p>
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
