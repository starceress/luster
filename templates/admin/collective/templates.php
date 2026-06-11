<?php declare(strict_types=1);
use Luster\Core\View;
use Luster\Repositories\SiteSettingsRepo;

$b  = rtrim(View::e($base_url ?? ''), '/');
$ct = $collective_templates ?? [];
$d  = SiteSettingsRepo::collectiveDefaults();

$ownedVal      = $ct['owned_template']                         ?: $d['owned_template'];
$randomVal     = $ct['random_listing_template']                ?: $d['random_listing_template'];
$latestVal     = $ct['latest_listing_template']                ?: $d['latest_listing_template'];
$joinedVal     = $ct['joined_template']                        ?: $d['joined_template'];
$affSectionVal = $ct['collective_affiliates_section_template'] ?: $d['collective_affiliates_section_template'];
$statsVal      = $ct['collective_stats_template']              ?: $d['collective_stats_template'];

// Renders one clickable token row for the sidebar reference panel.
$tok = static fn(string $ta, string $token, string $label, string $desc): string =>
    '<tr class="tok-row" tabindex="0" role="button"'
    . ' title="'          . View::e($desc)  . '"'
    . ' aria-label="Insert ' . View::e($label) . '. ' . View::e($desc) . '"'
    . ' data-textarea="'  . View::e($ta)    . '"'
    . ' data-token="'     . View::e($token) . '">'
    . '<td class="token-cell" data-desc="' . View::e($desc) . '"><code>' . View::e($label) . '</code></td>'
    . '</tr>' . "\n";
?>

<div class="toolbar">
  <div class="actions-row">
    <a href="<?= $b ?>/admin/collective" class="link">&larr; Back to Collective</a>
  </div>
  <div class="actions-row actions-row--right">
    <a href="<?= $b ?>/admin/collective/snippets" class="btn btn-quaternary btn-sm">Embed Snippets</a>
    <a href="<?= $b ?>/admin/collective/settings" class="btn btn-quaternary btn-sm">Collective Settings</a>
  </div>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/settings/templates" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="composer-grid">

    <!-- ── Left column: template editors ─────────────────────────────────── -->
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
          <button type="button" class="btn btn-secondary btn-xs coltpl-preview-btn" data-tpltype="owned" data-textarea="owned_template">Preview</button>
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="owned_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Joined Listings</div>
        <div class="form-group">
          <textarea name="joined_template" id="joined_template" class="form form-mono" spellcheck="false"><?= View::e($joinedVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-secondary btn-xs coltpl-preview-btn" data-tpltype="joined" data-textarea="joined_template">Preview</button>
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="joined_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Random Listing</div>
        <p class="form-hint mb-sm">Used by the Random Listing embed. Styles one listing chosen at random from your active pool.</p>
        <div class="form-group">
          <textarea name="random_listing_template" id="random_listing_template" class="form form-mono" spellcheck="false"><?= View::e($randomVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-secondary btn-xs coltpl-preview-btn" data-tpltype="random" data-textarea="random_listing_template">Preview</button>
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="random_listing_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Latest Listing</div>
        <p class="form-hint mb-sm">Used by the Latest Listing embed. Styles the most recently opened listing.</p>
        <div class="form-group">
          <textarea name="latest_listing_template" id="latest_listing_template" class="form form-mono" spellcheck="false"><?= View::e($latestVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-secondary btn-xs coltpl-preview-btn" data-tpltype="latest" data-textarea="latest_listing_template">Preview</button>
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="latest_listing_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Affiliates</div>
        <p class="form-hint mb-sm">Controls the full affiliates section on <code>/affiliates</code> and the collective affiliates embed. Use <code>{each_affiliate}&hellip;{/each_affiliate}</code> to design each row yourself, or replace the loop with <code>{affiliates}</code> to drop in ready-made rows.</p>
        <div class="form-group">
          <textarea name="collective_affiliates_section_template" id="collective_affiliates_section_template" class="form form-mono" spellcheck="false"><?= View::e($affSectionVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-secondary btn-xs coltpl-preview-btn" data-tpltype="collective_affiliates_section" data-textarea="collective_affiliates_section_template">Preview</button>
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_affiliates_section_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Statistics</div>
        <div class="form-group">
          <textarea name="collective_stats_template" id="collective_stats_template" class="form form-mono" spellcheck="false"><?= View::e($statsVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-secondary btn-xs coltpl-preview-btn" data-tpltype="collective_stats" data-textarea="collective_stats_template">Preview</button>
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_stats_template">Reset to Default</button>
        </div>

      </div>

      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Save Templates</button>
        <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
      </div>
    </div>

    <!-- ── Right column: token reference ─────────────────────────────────── -->
    <div class="composer-side">

      <div class="search-field-wrap token-search-wrap">
        <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
        <input type="search"
               id="collective-token-search"
               data-token-search
               class="form"
               placeholder="Search embed tokens"
               autocomplete="off"
               aria-label="Search embed tokens">
        <button type="button"
                id="collective-token-search-clear"
                data-token-search-clear
                class="token-search-clear"
                aria-label="Clear embed token search"
                title="Clear search"
                hidden><?= View::icon('x', ['aria-hidden' => 'true']) ?></button>
      </div>

      <p id="collective-token-search-empty" data-token-search-empty class="form-hint token-search-empty" hidden>No tokens found.</p>

      <!-- ── Owned Listings ─────────────────────────── -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Owned Listings Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Use {each_listing}...{/each_listing} for a flat list. Switch to {each_current}, {each_upcoming}, and {each_pending} for separate sections.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('owned_template', '{each_listing}{/each_listing}',   '{each_listing}...{/each_listing}',   'Repeats the HTML inside it once for each owned listing shown.') ?>
            <?= $tok('owned_template', '{each_current}{/each_current}',   '{each_current}...{/each_current}',   'Repeats the HTML inside it once for each current owned listing. Use with upcoming and pending blocks for separate sections.') ?>
            <?= $tok('owned_template', '{each_upcoming}{/each_upcoming}', '{each_upcoming}...{/each_upcoming}', 'Repeats the HTML inside it once for each upcoming owned listing.') ?>
            <?= $tok('owned_template', '{each_pending}{/each_pending}',   '{each_pending}...{/each_pending}',   'Repeats the HTML inside it once for each pending owned listing.') ?>
            <?= $tok('owned_template', '{listing_title}',      '{listing_title}',      'The fanlisting display name.') ?>
            <?= $tok('owned_template', '{listing_url}',        '{listing_url}',        'The link to the fanlisting.') ?>
            <?= $tok('owned_template', '{listing_subject}',    '{listing_subject}',    'What the fanlisting is for.') ?>
            <?= $tok('owned_template', '{listing_status}',     '{listing_status}',     'The listing status: current, upcoming, or pending.') ?>
            <?= $tok('owned_template', '{date_opened}',        '{date_opened}',        'The date this listing opened.') ?>
            <?= $tok('owned_template', '{date_updated}',       '{date_updated}',       'The date this listing was last updated.') ?>
            <?= $tok('owned_template', '{listing_category}',   '{listing_category}',   'The listing category names, comma-separated.') ?>
            <?= $tok('owned_template', '{listing_image_url}',  '{listing_image_url}',  'The image URL. Empty when there is no image.') ?>
            <?= $tok('owned_template', '{listing_image_html}', '{listing_image_html}', 'A ready-made img tag for the listing image. Empty when there is no image.') ?>
            <?= $tok('owned_template', '{listing_email}',      '{listing_email}',      'The contact email for this listing.') ?>
            <?= $tok('owned_template', '{listing_type}',       '{listing_type}',       'The listing type value.') ?>
            <?= $tok('owned_template', '{member_count}',          '{member_count}',          'How many approved members this listing has.') ?>
            <?= $tok('owned_template', '{member_pending_count}',  '{member_pending_count}',  'How many members are waiting for approval on this listing.') ?>
            <?= $tok('owned_template', '{country_count}',      '{country_count}',      'How many different countries are represented by approved members.') ?>
            <?= $tok('owned_template', '{countries_count}',    '{countries_count}',    'Same as {country_count}. Use whichever name you prefer.') ?>
            <?= $tok('owned_template', '{approved_by}',        '{approved_by}',        'Which network this listing is registered with: tfl, tafl, or blank.') ?>
            <?= $tok('owned_template', '{if_listing_image}{/if_listing_image}',       '{if_listing_image}...{/if_listing_image}',       'Shows only when the listing has an image.') ?>
            <?= $tok('owned_template', '{if_countries}{/if_countries}',               '{if_countries}...{/if_countries}',               'Shows only when this listing has at least one country.') ?>
            <?= $tok('owned_template', '{if_pending}{/if_pending}',                   '{if_pending}...{/if_pending}',                   'Shows only when this listing has members waiting for approval.') ?>
            <?= $tok('owned_template', '{if_status_current}{/if_status_current}',     '{if_status_current}...{/if_status_current}',     'Shows only when the listing is current.') ?>
            <?= $tok('owned_template', '{if_status_upcoming}{/if_status_upcoming}',   '{if_status_upcoming}...{/if_status_upcoming}',   'Shows only when the listing is upcoming.') ?>
            <?= $tok('owned_template', '{if_status_pending}{/if_status_pending}',     '{if_status_pending}...{/if_status_pending}',     'Shows only when the listing is pending.') ?>
            <?= $tok('owned_template', '{if_tfl}{/if_tfl}',                           '{if_tfl}...{/if_tfl}',                           'Shows only when this listing is TFL-approved.') ?>
            <?= $tok('owned_template', '{if_tafl}{/if_tafl}',                         '{if_tafl}...{/if_tafl}',                         'Shows only when this listing is TAFL-approved.') ?>
            <?= $tok('owned_template', '{if_approved}{/if_approved}',                 '{if_approved}...{/if_approved}',                 'Shows only when this listing is approved by either network.') ?>
            <?= $tok('owned_template', '{current_count}',   '{current_count}',   'How many current listings are in this grouped template.') ?>
            <?= $tok('owned_template', '{upcoming_count}',  '{upcoming_count}',  'How many upcoming listings are in this grouped template.') ?>
            <?= $tok('owned_template', '{pending_count}',   '{pending_count}',   'How many pending listings are in this grouped template. For pending members, use {member_pending_count}.') ?>
            <?= $tok('owned_template', '{total_count}',     '{total_count}',     'Total number of owned listings shown.') ?>
            <?= $tok('owned_template', '{if_current_listings}{/if_current_listings}',   '{if_current_listings}...{/if_current_listings}',   'Shows only when there is at least one current listing.') ?>
            <?= $tok('owned_template', '{if_upcoming_listings}{/if_upcoming_listings}', '{if_upcoming_listings}...{/if_upcoming_listings}', 'Shows only when there is at least one upcoming listing.') ?>
            <?= $tok('owned_template', '{if_pending_listings}{/if_pending_listings}',   '{if_pending_listings}...{/if_pending_listings}',   'Shows only when there is at least one pending listing.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Joined Listings ────────────────────────── -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Joined Listings Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Use {each_joined}...{/each_joined} for a flat list. Use {each_current_joined}...{/each_current_joined} when you want the list to follow category order.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('joined_template', '{each_joined}{/each_joined}',                 '{each_joined}...{/each_joined}',                 'Repeats the HTML inside it once for each joined listing. Use this for a simple list.') ?>
            <?= $tok('joined_template', '{each_current_joined}{/each_current_joined}', '{each_current_joined}...{/each_current_joined}', 'Repeats the HTML inside it once for each joined listing, in category order.') ?>
            <?= $tok('joined_template', '{listing_title}',      '{listing_title}',      'The joined fanlisting display name.') ?>
            <?= $tok('joined_template', '{listing_url}',        '{listing_url}',        'The link to the joined fanlisting.') ?>
            <?= $tok('joined_template', '{listing_category}',   '{listing_category}',   'The category saved for this joined listing.') ?>
            <?= $tok('joined_template', '{listing_image_url}',  '{listing_image_url}',  'The image URL. Empty when there is no image.') ?>
            <?= $tok('joined_template', '{listing_image_html}', '{listing_image_html}', 'A ready-made img tag. Empty when there is no image.') ?>
            <?= $tok('joined_template', '{listing_status}',     '{listing_status}',     'The joined listing status.') ?>
            <?= $tok('joined_template', '{date_joined}',        '{date_joined}',        'The date you added this joined listing.') ?>
            <?= $tok('joined_template', '{if_listing_image}{/if_listing_image}', '{if_listing_image}...{/if_listing_image}', 'Shows only when the joined listing has an image.') ?>
            <?= $tok('joined_template', '{current_count}',  '{current_count}',  'How many joined listings are being shown.') ?>
            <?= $tok('joined_template', '{total_count}',    '{total_count}',    'The total number of joined listings in this view.') ?>
            <?= $tok('joined_template', '{if_current_listings}{/if_current_listings}', '{if_current_listings}...{/if_current_listings}', 'Shows only when there is at least one joined listing.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Random Listing ─────────────────────────── -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Random Listing Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Always renders one listing. Wrap your row design in {each_listing}...{/each_listing} — it is required for tokens to be replaced.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('random_listing_template', '{each_listing}{/each_listing}',   '{each_listing}...{/each_listing}',   'Wrap your row design inside this. Required for token replacement.') ?>
            <?= $tok('random_listing_template', '{listing_title}',      '{listing_title}',      'The fanlisting display name.') ?>
            <?= $tok('random_listing_template', '{listing_url}',        '{listing_url}',        'The link to the fanlisting.') ?>
            <?= $tok('random_listing_template', '{listing_subject}',    '{listing_subject}',    'What the fanlisting is for.') ?>
            <?= $tok('random_listing_template', '{listing_status}',     '{listing_status}',     'The listing status: current, upcoming, or pending.') ?>
            <?= $tok('random_listing_template', '{date_opened}',        '{date_opened}',        'The date this listing opened.') ?>
            <?= $tok('random_listing_template', '{date_updated}',       '{date_updated}',       'The date this listing was last updated.') ?>
            <?= $tok('random_listing_template', '{listing_category}',   '{listing_category}',   'The listing category names, comma-separated.') ?>
            <?= $tok('random_listing_template', '{listing_image_url}',  '{listing_image_url}',  'The image URL. Empty when there is no image.') ?>
            <?= $tok('random_listing_template', '{listing_image_html}', '{listing_image_html}', 'A ready-made img tag. Empty when there is no image.') ?>
            <?= $tok('random_listing_template', '{member_count}',          '{member_count}',          'How many approved members this listing has.') ?>
            <?= $tok('random_listing_template', '{member_pending_count}',  '{member_pending_count}',  'How many members are waiting for approval.') ?>
            <?= $tok('random_listing_template', '{country_count}',      '{country_count}',      'How many different countries are represented by approved members.') ?>
            <?= $tok('random_listing_template', '{approved_by}',        '{approved_by}',        'Which network this listing is registered with: tfl, tafl, or blank.') ?>
            <?= $tok('random_listing_template', '{if_listing_image}{/if_listing_image}',     '{if_listing_image}...{/if_listing_image}',     'Shows only when the listing has an image.') ?>
            <?= $tok('random_listing_template', '{if_countries}{/if_countries}',             '{if_countries}...{/if_countries}',             'Shows only when this listing has at least one country.') ?>
            <?= $tok('random_listing_template', '{if_status_current}{/if_status_current}',   '{if_status_current}...{/if_status_current}',   'Shows only when the listing is current.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Latest Listing ─────────────────────────── -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Latest Listing Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Always renders one listing. Wrap your row design in {each_listing}...{/each_listing} — it is required for tokens to be replaced.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('latest_listing_template', '{each_listing}{/each_listing}',   '{each_listing}...{/each_listing}',   'Wrap your row design inside this. Required for token replacement.') ?>
            <?= $tok('latest_listing_template', '{listing_title}',      '{listing_title}',      'The fanlisting display name.') ?>
            <?= $tok('latest_listing_template', '{listing_url}',        '{listing_url}',        'The link to the fanlisting.') ?>
            <?= $tok('latest_listing_template', '{listing_subject}',    '{listing_subject}',    'What the fanlisting is for.') ?>
            <?= $tok('latest_listing_template', '{listing_status}',     '{listing_status}',     'The listing status: current, upcoming, or pending.') ?>
            <?= $tok('latest_listing_template', '{date_opened}',        '{date_opened}',        'The date this listing opened.') ?>
            <?= $tok('latest_listing_template', '{date_updated}',       '{date_updated}',       'The date this listing was last updated.') ?>
            <?= $tok('latest_listing_template', '{listing_category}',   '{listing_category}',   'The listing category names, comma-separated.') ?>
            <?= $tok('latest_listing_template', '{listing_image_url}',  '{listing_image_url}',  'The image URL. Empty when there is no image.') ?>
            <?= $tok('latest_listing_template', '{listing_image_html}', '{listing_image_html}', 'A ready-made img tag. Empty when there is no image.') ?>
            <?= $tok('latest_listing_template', '{member_count}',          '{member_count}',          'How many approved members this listing has.') ?>
            <?= $tok('latest_listing_template', '{member_pending_count}',  '{member_pending_count}',  'How many members are waiting for approval.') ?>
            <?= $tok('latest_listing_template', '{country_count}',      '{country_count}',      'How many different countries are represented by approved members.') ?>
            <?= $tok('latest_listing_template', '{approved_by}',        '{approved_by}',        'Which network this listing is registered with: tfl, tafl, or blank.') ?>
            <?= $tok('latest_listing_template', '{if_listing_image}{/if_listing_image}',     '{if_listing_image}...{/if_listing_image}',     'Shows only when the listing has an image.') ?>
            <?= $tok('latest_listing_template', '{if_countries}{/if_countries}',             '{if_countries}...{/if_countries}',             'Shows only when this listing has at least one country.') ?>
            <?= $tok('latest_listing_template', '{if_status_current}{/if_status_current}',   '{if_status_current}...{/if_status_current}',   'Shows only when the listing is current.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Affiliates Section ─────────────────────── -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Affiliates Section Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Use {each_affiliate}...{/each_affiliate} to design each row yourself. Use {affiliates} to drop in ready-made rows without a custom loop.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('collective_affiliates_section_template', '{each_affiliate}{/each_affiliate}', '{each_affiliate}...{/each_affiliate}', 'Repeats the HTML inside it once for each affiliate.') ?>
            <?= $tok('collective_affiliates_section_template', '{aff_name}',      '{aff_name}',      'The affiliate site name. Use inside {each_affiliate}.') ?>
            <?= $tok('collective_affiliates_section_template', '{aff_url}',       '{aff_url}',       'The affiliate site link. Use inside {each_affiliate}.') ?>
            <?= $tok('collective_affiliates_section_template', '{aff_image}',     '{aff_image}',     'The saved image filename only. Use inside {each_affiliate}.') ?>
            <?= $tok('collective_affiliates_section_template', '{aff_image_url}', '{aff_image_url}', 'The full image link. Empty when there is no image. Use inside {each_affiliate}.') ?>
            <?= $tok('collective_affiliates_section_template', '{aff_image_tag}', '{aff_image_tag}', 'A ready-made img tag. Empty when there is no image. Use inside {each_affiliate}.') ?>
            <?= $tok('collective_affiliates_section_template', '{affiliates}',       '{affiliates}',       'Ready-made affiliate rows. Use this when you do not want to design each row yourself.') ?>
            <?= $tok('collective_affiliates_section_template', '{affiliates_count}', '{affiliates_count}', 'How many active affiliates there are.') ?>
            <?= $tok('collective_affiliates_section_template', '{affiliates_list}',  '{affiliates_list}',  'A comma-separated list of linked affiliate names.') ?>
            <?= $tok('collective_affiliates_section_template', '{if_affiliates}{/if_affiliates}',       '{if_affiliates}...{/if_affiliates}',       'Shows only when there is at least one affiliate.') ?>
            <?= $tok('collective_affiliates_section_template', '{if_no_affiliates}{/if_no_affiliates}', '{if_no_affiliates}...{/if_no_affiliates}', 'Shows only when there are no affiliates.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Statistics ─────────────────────────────── -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Statistics Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Summary numbers for the whole collective. These are template tokens, not PHP variables for sample pages.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('collective_stats_template', '{owned_active}',     '{owned_active}',     'How many owned listings are currently open.') ?>
            <?= $tok('collective_stats_template', '{owned_upcoming}',   '{owned_upcoming}',   'How many owned listings are marked upcoming.') ?>
            <?= $tok('collective_stats_template', '{owned_pending}',    '{owned_pending}',    'How many owned listings are marked pending.') ?>
            <?= $tok('collective_stats_template', '{joined_active}',    '{joined_active}',    'How many joined listings are active.') ?>
            <?= $tok('collective_stats_template', '{affiliates}',       '{affiliates}',       'How many collective affiliates are active.') ?>
            <?= $tok('collective_stats_template', '{members_total}',    '{members_total}',    'Total members across your owned listings, approved and pending.') ?>
            <?= $tok('collective_stats_template', '{members_approved}', '{members_approved}', 'Approved members across your owned listings.') ?>
            <?= $tok('collective_stats_template', '{members_pending}',  '{members_pending}',  'Members waiting for approval across your owned listings.') ?>
            <?= $tok('collective_stats_template', '{owned_newest_name}', '{owned_newest_name}', 'The display name of your newest owned listing.') ?>
            <?= $tok('collective_stats_template', '{owned_newest_url}',  '{owned_newest_url}',  'The link to your newest owned listing.') ?>
            <?= $tok('collective_stats_template', '{owned_random_name}', '{owned_random_name}', 'The display name of a randomly chosen owned listing.') ?>
            <?= $tok('collective_stats_template', '{owned_random_url}',  '{owned_random_url}',  'The link to a randomly chosen owned listing.') ?>
            <?= $tok('collective_stats_template', '{countries_count}',   '{countries_count}',   'How many different countries are represented across your owned listings.') ?>
            <?= $tok('collective_stats_template', '{if_countries}{/if_countries}', '{if_countries}...{/if_countries}', 'Shows only when the collective country count is greater than zero.') ?>
          </tbody></table>
        </div>
      </details>

    </div><!-- /.composer-side -->
  </div><!-- /.composer-grid -->
</form>

<script type="application/json" id="luster-coltpl-defaults">
<?= json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>

<script type="application/json" id="luster-coltpl-meta"><?= json_encode([
    'previewUrl' => $b . '/admin/collective/templates/preview',
    'csrf'       => $csrf_token ?? '',
], JSON_UNESCAPED_SLASHES) ?></script>

<div id="coltpl-prev-modal" class="modal-backdrop is-hidden" style="display:none" role="dialog" aria-modal="true" aria-label="Template preview">
  <div class="modal modal--preview" style="max-width:680px;height:68vh">
    <div class="modal-header">
      <span id="coltpl-prev-title" class="modal-title">Preview</span>
      <div style="display:flex;align-items:center;gap:.5rem">
        <span id="coltpl-prev-spinner" style="display:none;font-size:.8rem;color:var(--text-muted)">Loading&hellip;</span>
        <button type="button" id="coltpl-prev-close" class="btn btn-tertiary btn-sm" aria-label="Close preview">&times;&ensp;Close</button>
      </div>
    </div>
    <div class="modal-body--iframe">
      <iframe id="coltpl-prev-iframe" title="Template preview" sandbox="allow-same-origin allow-scripts"></iframe>
    </div>
  </div>
</div>
