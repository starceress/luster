<?php declare(strict_types=1);
use Luster\Core\View;
use Luster\Repositories\SiteSettingsRepo;

$b  = rtrim(View::e($base_url ?? ''), '/');
$ct = $collective_templates ?? [];
$d  = SiteSettingsRepo::collectiveDefaults();

$ownedVal           = $ct['owned_template']                              ?: $d['owned_template'];
$randomVal          = $ct['random_listing_template']                     ?: $d['random_listing_template'];
$latestVal          = $ct['latest_listing_template']                     ?: $d['latest_listing_template'];
$randomJoinedVal    = $ct['random_joined_listing_template']              ?: $d['random_joined_listing_template'];
$latestJoinedVal    = $ct['latest_joined_listing_template']              ?: $d['latest_joined_listing_template'];
$joinedVal          = $ct['joined_template']                             ?: $d['joined_template'];
$affSectionVal      = $ct['collective_affiliates_section_template']      ?: $d['collective_affiliates_section_template'];
$statsVal           = $ct['collective_stats_template']                   ?: $d['collective_stats_template'];
$affApplyFormVal    = $ct['collective_affiliate_apply_form_template'] ?: $d['collective_affiliate_apply_form_template'];
$kimSignupFormVal   = $ct['kim_signup_form_template']                   ?: $d['kim_signup_form_template'];
$wishlistVal          = $ct['wishlist_template']                        ?: $d['wishlist_template'];
$collectiveUpdatesVal = $ct['collective_updates_template']              ?: $d['collective_updates_template'];

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
    <a href="<?= $b ?>/admin/collective/snippets" class="btn btn-quaternary btn-sm">Collective Specific Embed Snippets</a>
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
          <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="owned" data-textarea="owned_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp; 
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="owned_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Joined Listings</div>
        <div class="form-group">
          <textarea name="joined_template" id="joined_template" class="form form-mono" spellcheck="false"><?= View::e($joinedVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="joined" data-textarea="joined_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp; 
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="joined_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Random Owned</div>
        <p class="form-hint mb-sm">Used by the Random Owned embed. Styles one listing chosen at random from your active pool.</p>
        <div class="form-group">
          <textarea name="random_listing_template" id="random_listing_template" class="form form-mono" spellcheck="false"><?= View::e($randomVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="random" data-textarea="random_listing_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp; 
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="random_listing_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Latest Owned</div>
        <p class="form-hint mb-sm">Used by the Latest Owned embed. Styles the most recently opened listing.</p>
        <div class="form-group">
          <textarea name="latest_listing_template" id="latest_listing_template" class="form form-mono" spellcheck="false"><?= View::e($latestVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="latest" data-textarea="latest_listing_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="latest_listing_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Random Joined</div>
        <p class="form-hint mb-sm">Used by the Random Joined Listing embed. Styles one joined listing chosen at random from your active, approved pool.</p>
        <div class="form-group">
          <textarea name="random_joined_listing_template" id="random_joined_listing_template" class="form form-mono" spellcheck="false"><?= View::e($randomJoinedVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="random-joined" data-textarea="random_joined_listing_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="random_joined_listing_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Latest Joined</div>
        <p class="form-hint mb-sm">Used by the Latest Joined Listing embed. Styles the most recently joined active, approved listing.</p>
        <div class="form-group">
          <textarea name="latest_joined_listing_template" id="latest_joined_listing_template" class="form form-mono" spellcheck="false"><?= View::e($latestJoinedVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="latest-joined" data-textarea="latest_joined_listing_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="latest_joined_listing_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Affiliates</div>
        <p class="form-hint mb-sm">Controls the full affiliates section on <code>/affiliates</code> and the collective affiliates embed. Use <code>{each_affiliate}&hellip;{/each_affiliate}</code> to design each row yourself, or replace the loop with <code>{affiliates}</code> to drop in ready-made rows.</p>
        <div class="form-group">
          <textarea name="collective_affiliates_section_template" id="collective_affiliates_section_template" class="form form-mono" spellcheck="false"><?= View::e($affSectionVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="collective_affiliates_section" data-textarea="collective_affiliates_section_template"><?= View::icon('eye-on') ?> Preview</button>
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_affiliates_section_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Statistics</div>
        <div class="form-group">
          <textarea name="collective_stats_template" id="collective_stats_template" class="form form-mono" spellcheck="false"><?= View::e($statsVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="collective_stats" data-textarea="collective_stats_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp; 
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_stats_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Collective Affiliate Application Form</div>
        <p class="form-hint mb-sm">Controls the layout of the collective affiliate application form. Use the tokens on the right to arrange fields. This template is used by the affiliate-apply embed in collective mode.</p>
        <div class="form-group">
          <textarea name="collective_affiliate_apply_form_template" id="collective_affiliate_apply_form_template" class="form form-mono" spellcheck="false"><?= View::e($affApplyFormVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="collective_affiliate_apply_form" data-textarea="collective_affiliate_apply_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp; 
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_affiliate_apply_form_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">KIM Sign-Up Form</div>
        <p class="form-hint mb-sm">Controls the layout of the KIM (Keep In Mind) sign-up form. Use the tokens on the right to arrange fields.</p>
        <div class="form-group">
          <textarea name="kim_signup_form_template" id="kim_signup_form_template" class="form form-mono" spellcheck="false"><?= View::e($kimSignupFormVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="kim_signup_form" data-textarea="kim_signup_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp; 
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="kim_signup_form_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Wishlist</div>
        <p class="form-hint mb-sm">Used by the Wishlist embed. This template is self-contained — include your own wrapper element.</p>
        <div class="form-group">
          <textarea name="wishlist_template" id="wishlist_template" class="form form-mono" spellcheck="false"><?= View::e($wishlistVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="wishlist" data-textarea="wishlist_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp; 
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="wishlist_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Updates Feed</div>
        <p class="form-hint mb-sm">Used by the collective Updates Feed embed. Wrap each post in <code>{each_update}&hellip;{/each_update}</code> — the outer wrapper and pagination are added automatically.</p>
        <div class="form-group">
          <textarea name="collective_updates_template" id="collective_updates_template" class="form form-mono" spellcheck="false"><?= View::e($collectiveUpdatesVal) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="collective_updates" data-textarea="collective_updates_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp; 
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_updates_template">Reset to Default</button>
        </div>

      </div>

      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Save Templates</button>
        <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
      </div>
    </div>

    <!-- ── Right column: token reference ─────────────────────────────────── -->
    <div class="composer-side composer-side--sticky">

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
            <?= $tok('owned_template', '{listing_description}', '{listing_description}', 'The listing description, if set on the Edit Listing page.') ?>
            <?= $tok('owned_template', '{if_listing_image}{/if_listing_image}',       '{if_listing_image}...{/if_listing_image}',       'Shows only when the listing has an image.') ?>
            <?= $tok('owned_template', '{if_countries}{/if_countries}',               '{if_countries}...{/if_countries}',               'Shows only when this listing has at least one country.') ?>
            <?= $tok('owned_template', '{if_description}{/if_description}',          '{if_description}...{/if_description}',          'Shows only when this listing has a description set.') ?>
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
            <?= $tok('random_listing_template', '{listing_description}', '{listing_description}', 'The listing description, if set on the Edit Listing page.') ?>
            <?= $tok('random_listing_template', '{if_listing_image}{/if_listing_image}',     '{if_listing_image}...{/if_listing_image}',     'Shows only when the listing has an image.') ?>
            <?= $tok('random_listing_template', '{if_countries}{/if_countries}',             '{if_countries}...{/if_countries}',             'Shows only when this listing has at least one country.') ?>
            <?= $tok('random_listing_template', '{if_description}{/if_description}',         '{if_description}...{/if_description}',         'Shows only when this listing has a description set.') ?>
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
            <?= $tok('latest_listing_template', '{listing_description}', '{listing_description}', 'The listing description, if set on the Edit Listing page.') ?>
            <?= $tok('latest_listing_template', '{if_listing_image}{/if_listing_image}',     '{if_listing_image}...{/if_listing_image}',     'Shows only when the listing has an image.') ?>
            <?= $tok('latest_listing_template', '{if_countries}{/if_countries}',             '{if_countries}...{/if_countries}',             'Shows only when this listing has at least one country.') ?>
            <?= $tok('latest_listing_template', '{if_description}{/if_description}',         '{if_description}...{/if_description}',         'Shows only when this listing has a description set.') ?>
            <?= $tok('latest_listing_template', '{if_status_current}{/if_status_current}',   '{if_status_current}...{/if_status_current}',   'Shows only when the listing is current.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Random Joined Listing ──────────────────── -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Random Joined Listing Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Always renders one joined listing. Wrap your row design in {each_joined}...{/each_joined} — it is required for tokens to be replaced.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('random_joined_listing_template', '{each_joined}{/each_joined}', '{each_joined}...{/each_joined}', 'Wrap your row design inside this. Required for token replacement.') ?>
            <?= $tok('random_joined_listing_template', '{listing_title}',      '{listing_title}',      'The joined fanlisting display name.') ?>
            <?= $tok('random_joined_listing_template', '{listing_url}',        '{listing_url}',        'The link to the joined fanlisting.') ?>
            <?= $tok('random_joined_listing_template', '{listing_category}',   '{listing_category}',   'The category saved for this joined listing.') ?>
            <?= $tok('random_joined_listing_template', '{listing_image_url}',  '{listing_image_url}',  'The image URL. Empty when there is no image.') ?>
            <?= $tok('random_joined_listing_template', '{listing_image_html}', '{listing_image_html}', 'A ready-made img tag. Empty when there is no image.') ?>
            <?= $tok('random_joined_listing_template', '{listing_status}',     '{listing_status}',     'The joined listing status.') ?>
            <?= $tok('random_joined_listing_template', '{date_joined}',        '{date_joined}',        'The date you added this joined listing.') ?>
            <?= $tok('random_joined_listing_template', '{if_listing_image}{/if_listing_image}', '{if_listing_image}...{/if_listing_image}', 'Shows only when the joined listing has an image.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Latest Joined Listing ──────────────────── -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Latest Joined Listing Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Always renders one joined listing. Wrap your row design in {each_joined}...{/each_joined} — it is required for tokens to be replaced.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('latest_joined_listing_template', '{each_joined}{/each_joined}', '{each_joined}...{/each_joined}', 'Wrap your row design inside this. Required for token replacement.') ?>
            <?= $tok('latest_joined_listing_template', '{listing_title}',      '{listing_title}',      'The joined fanlisting display name.') ?>
            <?= $tok('latest_joined_listing_template', '{listing_url}',        '{listing_url}',        'The link to the joined fanlisting.') ?>
            <?= $tok('latest_joined_listing_template', '{listing_category}',   '{listing_category}',   'The category saved for this joined listing.') ?>
            <?= $tok('latest_joined_listing_template', '{listing_image_url}',  '{listing_image_url}',  'The image URL. Empty when there is no image.') ?>
            <?= $tok('latest_joined_listing_template', '{listing_image_html}', '{listing_image_html}', 'A ready-made img tag. Empty when there is no image.') ?>
            <?= $tok('latest_joined_listing_template', '{listing_status}',     '{listing_status}',     'The joined listing status.') ?>
            <?= $tok('latest_joined_listing_template', '{date_joined}',        '{date_joined}',        'The date you joined this listing.') ?>
            <?= $tok('latest_joined_listing_template', '{if_listing_image}{/if_listing_image}', '{if_listing_image}...{/if_listing_image}', 'Shows only when the joined listing has an image.') ?>
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
            <?= $tok('collective_stats_template', '{if_pending}{/if_pending}', '{if_pending}...{/if_pending}', 'Shows only when at least one member across your owned listings is waiting for approval.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Collective Affiliate Application Form ──── -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Collective Affiliate Apply Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Controls the layout of the collective affiliate application form. Leave the template blank to use the built-in form. Field tokens output the full label + input block; <code>_input</code> variants output just the raw input element.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('collective_affiliate_apply_form_template', '{listing_title}', '{listing_title}', 'The collective/site name.') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{listing_url}',   '{listing_url}',   'The collective/site URL.') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{field_name}',          '{field_name}',          'Full label + input block for the affiliate site name field.') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{field_name_input}',    '{field_name_input}',    'Just the site name text input element.') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{field_url}',           '{field_url}',           'Full label + input block for the site URL field.') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{field_url_input}',     '{field_url_input}',     'Just the site URL input element.') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{field_email}',         '{field_email}',         'Full label + input block for the email field.') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{field_email_input}',   '{field_email_input}',   'Just the email input element.') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{field_image}',         '{field_image}',         'Full label + input block for the site button/image upload field.') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{field_image_input}',   '{field_image_input}',   'Just the file input element.') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{field_message}',       '{field_message}',       'Full label + input block for the message textarea.') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{field_message_input}', '{field_message_input}', 'Just the message textarea element.') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{captcha_block}',       '{captcha_block}',       'Full label + input block for the captcha field.') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{captcha_block_input}', '{captcha_block_input}', 'Just the captcha text input element.') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{captcha_question}',    '{captcha_question}',    'The arithmetic question text only (e.g. "2 + 3").') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{submit_button}',       '{submit_button}',       'The submit button.') ?>
            <?= $tok('collective_affiliate_apply_form_template', '{clear_button}',        '{clear_button}',        'A reset button that clears the form.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── KIM Sign-Up Form ───────────────────────── -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">KIM Sign-Up Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Controls the layout of the KIM sign-up form. Leave the template blank to use the built-in form. Field tokens output the full label + input block; <code>_input</code> variants output just the raw input element.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('kim_signup_form_template', '{site_name}',                   '{site_name}',                   'The collective/site name.') ?>
            <?= $tok('kim_signup_form_template', '{field_name}',                  '{field_name}',                  'Full label + input block for the name field.') ?>
            <?= $tok('kim_signup_form_template', '{field_name_input}',            '{field_name_input}',            'Just the name text input element.') ?>
            <?= $tok('kim_signup_form_template', '{field_url}',                   '{field_url}',                   'Full label + input block for the URL field.') ?>
            <?= $tok('kim_signup_form_template', '{field_url_input}',             '{field_url_input}',             'Just the URL input element.') ?>
            <?= $tok('kim_signup_form_template', '{field_email}',                 '{field_email}',                 'Full label + input block for the email field.') ?>
            <?= $tok('kim_signup_form_template', '{field_email_input}',           '{field_email_input}',           'Just the email input element.') ?>
            <?= $tok('kim_signup_form_template', '{field_fanlisting_id}',         '{field_fanlisting_id}',         'Full label + select block for the fanlisting selector.') ?>
            <?= $tok('kim_signup_form_template', '{field_fanlisting_id_input}',   '{field_fanlisting_id_input}',   'Just the fanlisting select element.') ?>
            <?= $tok('kim_signup_form_template', '{field_password}',              '{field_password}',              'Full label + input block for the password field.') ?>
            <?= $tok('kim_signup_form_template', '{field_password_input}',        '{field_password_input}',        'Just the password input element.') ?>
            <?= $tok('kim_signup_form_template', '{field_password_confirm}',      '{field_password_confirm}',      'Full label + input block for the confirm password field.') ?>
            <?= $tok('kim_signup_form_template', '{field_password_confirm_input}','{field_password_confirm_input}','Just the confirm password input element.') ?>
            <?= $tok('kim_signup_form_template', '{field_send_copy}',             '{field_send_copy}',             'Full label + checkbox block for the email copy opt-in.') ?>
            <?= $tok('kim_signup_form_template', '{field_send_copy_input}',       '{field_send_copy_input}',       'Just the send-copy checkbox group element.') ?>
            <?= $tok('kim_signup_form_template', '{captcha_block}',               '{captcha_block}',               'Full label + input block for the captcha field.') ?>
            <?= $tok('kim_signup_form_template', '{captcha_block_input}',         '{captcha_block_input}',         'Just the captcha text input element.') ?>
            <?= $tok('kim_signup_form_template', '{captcha_question}',            '{captcha_question}',            'The arithmetic question text only (e.g. "2 + 3").') ?>
            <?= $tok('kim_signup_form_template', '{submit_button}',               '{submit_button}',               'The submit button.') ?>
            <?= $tok('kim_signup_form_template', '{clear_button}',                '{clear_button}',                'A reset button that clears the form.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Wishlist ───────────────────────────────── -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Wishlist Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Wrap your row design in {each_wishlist_item}...{/each_wishlist_item}. This template is self-contained — include your own wrapper element.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('wishlist_template', '{each_wishlist_item}{/each_wishlist_item}', '{each_wishlist_item}...{/each_wishlist_item}', 'Repeats the HTML inside it once for each active wishlist entry.') ?>
            <?= $tok('wishlist_template', '{item_subject}',     '{item_subject}',     'The wishlist item title.') ?>
            <?= $tok('wishlist_template', '{item_url}',         '{item_url}',         'The link for this item. Empty when none is set.') ?>
            <?= $tok('wishlist_template', '{item_type}',        '{item_type}',        'The raw item type: wanted, top, or granted.') ?>
            <?= $tok('wishlist_template', '{item_type_label}',  '{item_type_label}',  'The human-readable type label: Wanted, Top Wanted, or Granted.') ?>
            <?= $tok('wishlist_template', '{item_category}',    '{item_category}',    'The category for this item. Empty when none is set.') ?>
            <?= $tok('wishlist_template', '{item_description}', '{item_description}','The description for this item. Empty when none is set.') ?>
            <?= $tok('wishlist_template', '{item_image_url}',   '{item_image_url}',   'The image URL for this item. Empty when there is no image.') ?>
            <?= $tok('wishlist_template', '{if_item_image}{/if_item_image}',             '{if_item_image}...{/if_item_image}',             'Shows only when this item has an image.') ?>
            <?= $tok('wishlist_template', '{if_item_url}{/if_item_url}',                 '{if_item_url}...{/if_item_url}',                 'Shows only when this item has a link.') ?>
            <?= $tok('wishlist_template', '{if_no_item_url}{/if_no_item_url}',           '{if_no_item_url}...{/if_no_item_url}',           'Shows only when this item has no link.') ?>
            <?= $tok('wishlist_template', '{if_item_category}{/if_item_category}',       '{if_item_category}...{/if_item_category}',       'Shows only when this item has a category.') ?>
            <?= $tok('wishlist_template', '{if_item_description}{/if_item_description}', '{if_item_description}...{/if_item_description}', 'Shows only when this item has a description.') ?>
            <?= $tok('wishlist_template', '{if_type_top}{/if_type_top}',         '{if_type_top}...{/if_type_top}',         'Shows only when this item\'s type is Top Wanted.') ?>
            <?= $tok('wishlist_template', '{if_type_wanted}{/if_type_wanted}',   '{if_type_wanted}...{/if_type_wanted}',   'Shows only when this item\'s type is Wanted.') ?>
            <?= $tok('wishlist_template', '{if_type_granted}{/if_type_granted}', '{if_type_granted}...{/if_type_granted}', 'Shows only when this item\'s type is Granted.') ?>
            <?= $tok('wishlist_template', '{each_wishlist_item_top}{/each_wishlist_item_top}',         '{each_wishlist_item_top}...{/each_wishlist_item_top}',         'Repeats the HTML inside it once for each Top Wanted entry on this page (same per-item tokens as above). Use to build a separate section for this group.') ?>
            <?= $tok('wishlist_template', '{each_wishlist_item_wanted}{/each_wishlist_item_wanted}',   '{each_wishlist_item_wanted}...{/each_wishlist_item_wanted}',   'Repeats the HTML inside it once for each Wanted entry on this page.') ?>
            <?= $tok('wishlist_template', '{each_wishlist_item_granted}{/each_wishlist_item_granted}', '{each_wishlist_item_granted}...{/each_wishlist_item_granted}', 'Repeats the HTML inside it once for each Granted entry on this page.') ?>
            <?= $tok('wishlist_template', '{if_has_top}{/if_has_top}',         '{if_has_top}...{/if_has_top}',         'Shows only when this page has at least one Top Wanted entry. Wrap a heading + {each_wishlist_item_top} block in this to build a conditional section.') ?>
            <?= $tok('wishlist_template', '{if_has_wanted}{/if_has_wanted}',   '{if_has_wanted}...{/if_has_wanted}',   'Shows only when this page has at least one Wanted entry.') ?>
            <?= $tok('wishlist_template', '{if_has_granted}{/if_has_granted}', '{if_has_granted}...{/if_has_granted}', 'Shows only when this page has at least one Granted entry.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Updates Feed ───────────────────────────── -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Updates Feed Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Wrap each post in {each_update}...{/each_update}. Luster repeats it once per update entry and adds the wrapper and pagination automatically.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('collective_updates_template', '{each_update}{/each_update}', '{each_update}...{/each_update}', 'Repeats the HTML inside it once for each update post shown.') ?>
            <?= $tok('collective_updates_template', '{update_title}',      '{update_title}',      'The update post title.') ?>
            <?= $tok('collective_updates_template', '{update_date}',       '{update_date}',       'The posted date in human-readable format, e.g. June 1, 2025.') ?>
            <?= $tok('collective_updates_template', '{update_date_raw}',   '{update_date_raw}',   'The posted date in YYYY-MM-DD format.') ?>
            <?= $tok('collective_updates_template', '{update_author}',     '{update_author}',     'The author name. Empty when not set.') ?>
            <?= $tok('collective_updates_template', '{update_categories}', '{update_categories}', 'Category names, comma-separated. Empty when not set.') ?>
            <?= $tok('collective_updates_template', '{update_body_html}',  '{update_body_html}',  'The update body text, with line breaks converted to HTML.') ?>
            <?= $tok('collective_updates_template', '{if_author}{/if_author}',         '{if_author}...{/if_author}',         'Shows only when the update has an author set.') ?>
            <?= $tok('collective_updates_template', '{if_categories}{/if_categories}', '{if_categories}...{/if_categories}', 'Shows only when the update has categories set.') ?>
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
      <iframe id="coltpl-prev-iframe" title="Template preview" sandbox=""></iframe>
    </div>
  </div>
</div>
