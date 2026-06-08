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
          <div class="pc-head-sub">Choose the HTML Luster prints for collective pages, embeds, owned listings, joined listings, affiliates, and stats.</div>
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

        <div class="pc-section-label">Collective Affiliates &mdash; Section Layout</div>
        <p class="form-hint mb-sm">Controls the full affiliates section on <code>/affiliates</code> and the collective affiliates embed. Use <code>{each_affiliate}&hellip;{/each_affiliate}</code> when you want to design each row yourself, or <code>{affiliates}</code> when you want Luster to drop in the ready-made rows. <strong>Leave blank to use the built-in layout.</strong></p>
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
          <p class="form-hint mb-sm">A token is a placeholder. Luster replaces it with the real listing information when the page or embed loads.</p>
          <table class="et-token-table">
            <tbody>
              <tr><td><code>{each_listing}&hellip;{/each_listing}</code></td><td>Repeats the HTML inside it once for each owned listing being shown. This makes one simple list and is what the default template uses.</td></tr>
              <tr><td><code>{each_current}&hellip;{/each_current}</code></td><td>Repeats the HTML inside it once for each current owned listing. Use this with the upcoming and pending blocks when you want separate sections.</td></tr>
              <tr><td><code>{each_upcoming}&hellip;{/each_upcoming}</code></td><td>Repeats the HTML inside it once for each upcoming owned listing.</td></tr>
              <tr><td><code>{each_pending}&hellip;{/each_pending}</code></td><td>Repeats the HTML inside it once for each pending owned listing.</td></tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_title}">
                <td><code>{listing_title}</code></td><td>The fanlisting's display name.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_url}">
                <td><code>{listing_url}</code></td><td>The link to the fanlisting.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_subject}">
                <td><code>{listing_subject}</code></td><td>What the fanlisting is for.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_status}">
                <td><code>{listing_status}</code></td><td>The listing's status word: <code>current</code>, <code>upcoming</code>, or <code>pending</code>.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{date_opened}">
                <td><code>{date_opened}</code></td><td>The date this listing opened.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{date_updated}">
                <td><code>{date_updated}</code></td><td>The date this listing was last updated.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_category}">
                <td><code>{listing_category}</code></td><td>The listing's category names, separated with commas when there is more than one.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_image_url}">
                <td><code>{listing_image_url}</code></td><td>Just the image link. Use this when you want to write your own <code>&lt;img&gt;</code> tag. Empty when there is no image.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_image_html}">
                <td><code>{listing_image_html}</code></td><td>A ready-made <code>&lt;img&gt;</code> tag for the listing image. Empty when there is no image.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_email}">
                <td><code>{listing_email}</code></td><td>The contact email saved for this listing.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{listing_type}">
                <td><code>{listing_type}</code></td><td>The listing type saved for this listing.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{member_count}">
                <td><code>{member_count}</code></td><td>How many approved members this listing has.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{member_pending_count}">
                <td><code>{member_pending_count}</code></td><td>How many members are waiting for approval on this one listing.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{country_count}">
                <td><code>{country_count}</code></td><td>How many different countries are represented by this listing's approved members.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{countries_count}">
                <td><code>{countries_count}</code></td><td>Same number as <code>{country_count}</code>. Use whichever name is easier to remember.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{approved_by}">
                <td><code>{approved_by}</code></td><td>Which fanlisting network this listing is registered with: <code>tfl</code>, <code>tafl</code>, or blank.</td>
              </tr>
              <tr><td><code>{if_listing_image}&hellip;{/if_listing_image}</code></td><td>Shows the HTML inside only when the listing has an image.</td></tr>
              <tr><td><code>{if_countries}&hellip;{/if_countries}</code></td><td>Shows the HTML inside only when this listing has at least one country counted.</td></tr>
              <tr><td><code>{if_pending}&hellip;{/if_pending}</code></td><td>Shows the HTML inside only when this listing has members waiting for approval.</td></tr>
              <tr><td><code>{if_status_current}&hellip;{/if_status_current}</code></td><td>Shows the HTML inside only when the listing is current.</td></tr>
              <tr><td><code>{if_status_upcoming}&hellip;{/if_status_upcoming}</code></td><td>Shows the HTML inside only when the listing is upcoming.</td></tr>
              <tr><td><code>{if_status_pending}&hellip;{/if_status_pending}</code></td><td>Shows the HTML inside only when the listing is pending.</td></tr>
              <tr><td><code>{if_tfl}&hellip;{/if_tfl}</code></td><td>Shows the HTML inside only when this listing is marked as TFL-approved.</td></tr>
              <tr><td><code>{if_tafl}&hellip;{/if_tafl}</code></td><td>Shows the HTML inside only when this listing is marked as TAFL-approved.</td></tr>
              <tr><td><code>{if_approved}&hellip;{/if_approved}</code></td><td>Shows the HTML inside only when this listing is marked as approved by either network.</td></tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{current_count}">
                <td><code>{current_count}</code></td><td>How many current listings are in this grouped template.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{upcoming_count}">
                <td><code>{upcoming_count}</code></td><td>How many upcoming listings are in this grouped template.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{pending_count}">
                <td><code>{pending_count}</code></td><td>How many pending listings are in this grouped template. For pending members on one listing, use <code>{member_pending_count}</code>.</td>
              </tr>
              <tr class="tok-row" data-textarea="owned_template" data-token="{total_count}">
                <td><code>{total_count}</code></td><td>How many owned listings are being shown in all groups combined.</td>
              </tr>
              <tr><td><code>{if_current_listings}&hellip;{/if_current_listings}</code></td><td>Shows the HTML inside only when there is at least one current listing.</td></tr>
              <tr><td><code>{if_upcoming_listings}&hellip;{/if_upcoming_listings}</code></td><td>Shows the HTML inside only when there is at least one upcoming listing.</td></tr>
              <tr><td><code>{if_pending_listings}&hellip;{/if_pending_listings}</code></td><td>Shows the HTML inside only when there is at least one pending listing.</td></tr>
            </tbody>
          </table>
          <p class="form-hint mt-sm">
            Use <code>{each_listing}&hellip;{/each_listing}</code> for one normal list. Use
            <code>{each_current}</code>, <code>{each_upcoming}</code>, and <code>{each_pending}</code>
            instead when you want headings like Current, Upcoming, and Pending. This same owned
            template is also used by <code>latest-listing</code> and <code>random-listing</code>, so
            a grouped template will group those widgets too. That is predictable, but usually best
            for an "all owned listings" overview page.
          </p>
        </div>
      </details>

      <details class="card chevron" open>
        <summary class="chevron-head">Joined Listings Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm">These work like owned listing tokens, but they describe fanlistings you have joined.</p>
          <table class="et-token-table">
            <tbody>
              <tr><td><code>{each_joined}&hellip;{/each_joined}</code></td><td>Repeats the HTML inside it once for each joined listing being shown. This makes one simple list.</td></tr>
              <tr><td><code>{each_current_joined}&hellip;{/each_current_joined}</code></td><td>Repeats the HTML inside it once for each joined listing, in category order. Works for one category or all categories.</td></tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_title}">
                <td><code>{listing_title}</code></td><td>The joined fanlisting's display name.</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_url}">
                <td><code>{listing_url}</code></td><td>The link to the joined fanlisting.</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_category}">
                <td><code>{listing_category}</code></td><td>The category saved for this joined listing.</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_image_url}">
                <td><code>{listing_image_url}</code></td><td>Just the image link. Use this when you want to write your own <code>&lt;img&gt;</code> tag. Empty when there is no image.</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_image_html}">
                <td><code>{listing_image_html}</code></td><td>A ready-made <code>&lt;img&gt;</code> tag for the listing image. Empty when there is no image.</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{listing_status}">
                <td><code>{listing_status}</code></td><td>The joined listing's status word.</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{date_joined}">
                <td><code>{date_joined}</code></td><td>The date you added this joined listing.</td>
              </tr>
              <tr><td><code>{if_listing_image}&hellip;{/if_listing_image}</code></td><td>Shows the HTML inside only when the joined listing has an image.</td></tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{current_count}">
                <td><code>{current_count}</code></td><td>How many joined listings are being shown.</td>
              </tr>
              <tr class="tok-row" data-textarea="joined_template" data-token="{total_count}">
                <td><code>{total_count}</code></td><td>The total number of joined listings in this view.</td>
              </tr>
              <tr><td><code>{if_current_listings}&hellip;{/if_current_listings}</code></td><td>Shows the HTML inside only when there is at least one joined listing to show.</td></tr>
            </tbody>
          </table>
          <p class="form-hint mt-sm">
            Use <code>{each_joined}&hellip;{/each_joined}</code> for one normal list. Use
            <code>{each_current_joined}&hellip;{/each_current_joined}</code> when you want the
            list to follow category order. It works whether the visitor is looking at all joined
            listings or one selected category.
          </p>
        </div>
      </details>

      <details class="card chevron">
        <summary class="chevron-head">Affiliates Section Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm">These control the collective-wide affiliates page and embed. Use the row tokens inside <code>{each_affiliate}&hellip;{/each_affiliate}</code>.</p>
          <table class="et-token-table">
            <tbody>
              <tr><td><code>{each_affiliate}&hellip;{/each_affiliate}</code></td><td>Repeats the HTML inside it once for each affiliate being shown.</td></tr>
              <tr class="tok-row" data-textarea="collective_affiliates_section_template" data-token="{aff_name}"><td><code>{aff_name}</code></td><td>The affiliate site's name. Use inside <code>{each_affiliate}</code>.</td></tr>
              <tr class="tok-row" data-textarea="collective_affiliates_section_template" data-token="{aff_url}"><td><code>{aff_url}</code></td><td>The affiliate site's link. Use inside <code>{each_affiliate}</code>.</td></tr>
              <tr class="tok-row" data-textarea="collective_affiliates_section_template" data-token="{aff_image}"><td><code>{aff_image}</code></td><td>The saved image filename only. Use inside <code>{each_affiliate}</code>.</td></tr>
              <tr class="tok-row" data-textarea="collective_affiliates_section_template" data-token="{aff_image_url}"><td><code>{aff_image_url}</code></td><td>The full image link. Empty when there is no image. Use inside <code>{each_affiliate}</code>.</td></tr>
              <tr class="tok-row" data-textarea="collective_affiliates_section_template" data-token="{aff_image_tag}"><td><code>{aff_image_tag}</code></td><td>A ready-made <code>&lt;img&gt;</code> tag for the affiliate image. Empty when there is no image. Use inside <code>{each_affiliate}</code>.</td></tr>
              <tr class="tok-row" data-textarea="collective_affiliates_section_template" data-token="{affiliates}"><td><code>{affiliates}</code></td><td>Ready-made affiliate rows. Handy when you do not want to design each row yourself.</td></tr>
              <tr class="tok-row" data-textarea="collective_affiliates_section_template" data-token="{affiliates_count}"><td><code>{affiliates_count}</code></td><td>How many active affiliates there are.</td></tr>
              <tr class="tok-row" data-textarea="collective_affiliates_section_template" data-token="{affiliates_list}"><td><code>{affiliates_list}</code></td><td>A simple comma-separated list of linked affiliate names.</td></tr>
              <tr><td><code>{if_affiliates}&hellip;{/if_affiliates}</code></td><td>Shows the HTML inside only when there is at least one affiliate.</td></tr>
              <tr><td><code>{if_no_affiliates}&hellip;{/if_no_affiliates}</code></td><td>Shows the HTML inside only when there are no affiliates.</td></tr>
            </tbody>
          </table>
        </div>
      </details>

      <details class="card chevron" open>
        <summary class="chevron-head">Statistics Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm">These are summary numbers for the whole collective. They are template tokens for this stats box, not PHP variables for sample pages.</p>
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_active}">
                <td><code>{owned_active}</code></td><td>How many owned listings are currently open.</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_upcoming}">
                <td><code>{owned_upcoming}</code></td><td>How many owned listings are marked upcoming.</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_pending}">
                <td><code>{owned_pending}</code></td><td>How many owned listings are marked pending.</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{joined_active}">
                <td><code>{joined_active}</code></td><td>How many joined listings are active.</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{affiliates}">
                <td><code>{affiliates}</code></td><td>How many collective affiliates are active.</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{members_total}">
                <td><code>{members_total}</code></td><td>Total members across your owned listings, approved and pending together.</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{members_approved}">
                <td><code>{members_approved}</code></td><td>Approved members across your owned listings.</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{members_pending}">
                <td><code>{members_pending}</code></td><td>Members waiting for approval across your owned listings.</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_newest_name}">
                <td><code>{owned_newest_name}</code></td><td>The display name of your newest owned listing.</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_newest_url}">
                <td><code>{owned_newest_url}</code></td><td>The link to your newest owned listing.</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_random_name}">
                <td><code>{owned_random_name}</code></td><td>The display name of a randomly chosen owned listing.</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{owned_random_url}">
                <td><code>{owned_random_url}</code></td><td>The link to a randomly chosen owned listing.</td>
              </tr>
              <tr class="tok-row" data-textarea="collective_stats_template" data-token="{countries_count}">
                <td><code>{countries_count}</code></td><td>How many different countries are represented by approved members across your owned listings.</td>
              </tr>
              <tr>
                <td><code>{if_countries}&hellip;{/if_countries}</code></td><td>Shows the HTML inside only when the collective country count is greater than zero.</td>
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
