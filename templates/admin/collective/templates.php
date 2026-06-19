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
$affApplyFormVal    = $ct['collective_affiliate_apply_form_template']  ?: $d['collective_affiliate_apply_form_template'];
$adoptionFormVal    = $ct['collective_adoption_form_template']          ?: ($d['collective_adoption_form_template'] ?? '');
$kimSignupFormVal   = $ct['kim_signup_form_template']                   ?: $d['kim_signup_form_template'];
$wishlistVal          = $ct['wishlist_template']                        ?: $d['wishlist_template'];
$collectiveUpdatesVal = $ct['collective_updates_template']              ?: $d['collective_updates_template'];
$collectiveCodesVal   = $ct['collective_codes_template']                ?: $d['collective_codes_template'];
$collectiveCodesSubmitFormVal = $ct['collective_codes_submit_form_template'] ?: $d['collective_codes_submit_form_template'];
$contactSentVal           = $ct['collective_contact_sent_template']            ?: $d['collective_contact_sent_template'];
$kimListOverviewVal       = $ct['collective_kim_list_overview_template']       ?: ($d['collective_kim_list_overview_template'] ?? '');
$kimListSubjectVal        = $ct['collective_kim_list_subject_template']        ?: ($d['collective_kim_list_subject_template'] ?? '');
$kimUpdateFormVal         = $ct['kim_update_form_template']                    ?: ($d['kim_update_form_template'] ?? '');
$kimResetFormVal          = $ct['kim_reset_form_template']                     ?: ($d['kim_reset_form_template'] ?? '');
$contactFormVal           = $ct['collective_contact_form_template']            ?: ($d['collective_contact_form_template'] ?? '');

// Renders one clickable token row for the sidebar reference panel.
$tok = static fn(string $ta, string $token, string $label, string $desc): string =>
    '<tr class="tok-row" tabindex="0" role="button"'
    . ' title="'          . View::e($desc)  . '"'
    . ' aria-label="Insert ' . View::e($label) . '. ' . View::e($desc) . '"'
    . ' data-textarea="'  . View::e($ta)    . '"'
    . ' data-token="'     . View::e($token) . '">'
    . '<td class="token-cell" data-desc="' . View::e($desc) . '"><code>' . View::e($label) . '</code></td>'
    . '</tr>' . "\n";

// Helper: returns 'hidden' when the active group does not match $g.
$_grp = static fn(string $g): string => ($activeGroup !== 'all' && $activeGroup !== $g) ? 'hidden' : '';
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

<div class="form-group" style="max-width:340px;margin-bottom:1rem">
  <div class="select-wrap">
    <select id="tpl-group-select" class="form">
      <option value="all"<?= $activeGroup === 'all' ? ' selected' : '' ?>>All Templates</option>
      <optgroup label="Owned Listings">
        <option value="owned"<?= $activeGroup === 'owned' ? ' selected' : '' ?>>Owned Listings</option>
        <option value="latest-owned"<?= $activeGroup === 'latest-owned' ? ' selected' : '' ?>>Latest Owned</option>
        <option value="random-owned"<?= $activeGroup === 'random-owned' ? ' selected' : '' ?>>Random Owned</option>
      </optgroup>
      <optgroup label="Joined Listings">
        <option value="joined"<?= $activeGroup === 'joined' ? ' selected' : '' ?>>Joined Listings</option>
        <option value="latest-joined"<?= $activeGroup === 'latest-joined' ? ' selected' : '' ?>>Latest Joined</option>
        <option value="random-joined"<?= $activeGroup === 'random-joined' ? ' selected' : '' ?>>Random Joined</option>
      </optgroup>
      <option value="stats"<?= $activeGroup === 'stats' ? ' selected' : '' ?>>Statistics</option>
      <optgroup label="Codes">
        <option value="codes"<?= $activeGroup === 'codes' ? ' selected' : '' ?>>Codes Display</option>
        <option value="codes-form"<?= $activeGroup === 'codes-form' ? ' selected' : '' ?>>Codes Donation Form</option>
      </optgroup>
      <optgroup label="Affiliates">
        <option value="affiliates"<?= $activeGroup === 'affiliates' ? ' selected' : '' ?>>Affiliates Display</option>
        <option value="affiliate-form"<?= $activeGroup === 'affiliate-form' ? ' selected' : '' ?>>Affiliate Application</option>
      </optgroup>
      <optgroup label="Contact Form">
        <option value="contact"<?= $activeGroup === 'contact' ? ' selected' : '' ?>>Contact Form</option>
        <option value="contact-sent"<?= $activeGroup === 'contact-sent' ? ' selected' : '' ?>>Contact Confirmation</option>
      </optgroup>
      <optgroup label="Keep In Mind">
        <option value="kim-overview"<?= $activeGroup === 'kim-overview' ? ' selected' : '' ?>>KIM List Overview</option>
        <option value="kim-subject"<?= $activeGroup === 'kim-subject' ? ' selected' : '' ?>>KIM Listing View</option>
        <option value="kim-signup"<?= $activeGroup === 'kim-signup' ? ' selected' : '' ?>>KIM Sign-Up Form</option>
        <option value="kim-update"<?= $activeGroup === 'kim-update' ? ' selected' : '' ?>>KIM Update Form</option>
        <option value="kim-reset"<?= $activeGroup === 'kim-reset' ? ' selected' : '' ?>>KIM Reset Form</option>
      </optgroup>
      <option value="adoption"<?= $activeGroup === 'adoption' ? ' selected' : '' ?>>Adoption Form</option>
      <option value="wishlist"<?= $activeGroup === 'wishlist' ? ' selected' : '' ?>>Wishlist</option>
      <option value="updates"<?= $activeGroup === 'updates' ? ' selected' : '' ?>>Updates Feed</option>
    </select>
  </div>
</div>

<form method="POST" action="<?= $b ?>/admin/settings/templates" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="return_group" id="tpl-return-group" value="<?= View::e($activeGroup) ?>">

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

        <!-- ── Owned Listings ── -->
        <div class="tpl-group-section" data-tpl-group="owned" <?= $_grp('owned') ?>>
          <div class="pc-section-label">Owned Listings</div>
          <div class="form-group">
            <textarea name="owned_template" id="owned_template" class="form form-mono" spellcheck="false"><?= View::e($ownedVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="owned" data-textarea="owned_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="owned_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── Latest Owned ── -->
        <div class="tpl-group-section" data-tpl-group="latest-owned" <?= $_grp('latest-owned') ?>>
          <div class="pc-section-label">Latest Owned</div>
          <p class="form-hint mb-sm">Used by the Latest Owned embed. Styles the most recently opened listing.</p>
          <div class="form-group">
            <textarea name="latest_listing_template" id="latest_listing_template" class="form form-mono" spellcheck="false"><?= View::e($latestVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="latest" data-textarea="latest_listing_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="latest_listing_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── Random Owned ── -->
        <div class="tpl-group-section" data-tpl-group="random-owned" <?= $_grp('random-owned') ?>>
          <div class="pc-section-label">Random Owned</div>
          <p class="form-hint mb-sm">Used by the Random Owned embed. Styles one listing chosen at random from your active pool.</p>
          <div class="form-group">
            <textarea name="random_listing_template" id="random_listing_template" class="form form-mono" spellcheck="false"><?= View::e($randomVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="random" data-textarea="random_listing_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="random_listing_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── Joined Listings ── -->
        <div class="tpl-group-section" data-tpl-group="joined" <?= $_grp('joined') ?>>
          <div class="pc-section-label">Joined Listings</div>
          <div class="form-group">
            <textarea name="joined_template" id="joined_template" class="form form-mono" spellcheck="false"><?= View::e($joinedVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="joined" data-textarea="joined_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="joined_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── Latest Joined ── -->
        <div class="tpl-group-section" data-tpl-group="latest-joined" <?= $_grp('latest-joined') ?>>
          <div class="pc-section-label">Latest Joined</div>
          <p class="form-hint mb-sm">Used by the Latest Joined Listing embed. Styles the most recently joined active, approved listing.</p>
          <div class="form-group">
            <textarea name="latest_joined_listing_template" id="latest_joined_listing_template" class="form form-mono" spellcheck="false"><?= View::e($latestJoinedVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="latest-joined" data-textarea="latest_joined_listing_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="latest_joined_listing_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── Random Joined ── -->
        <div class="tpl-group-section" data-tpl-group="random-joined" <?= $_grp('random-joined') ?>>
          <div class="pc-section-label">Random Joined</div>
          <p class="form-hint mb-sm">Used by the Random Joined Listing embed. Styles one joined listing chosen at random from your active, approved pool.</p>
          <div class="form-group">
            <textarea name="random_joined_listing_template" id="random_joined_listing_template" class="form form-mono" spellcheck="false"><?= View::e($randomJoinedVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="random-joined" data-textarea="random_joined_listing_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="random_joined_listing_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── Statistics ── -->
        <div class="tpl-group-section" data-tpl-group="stats" <?= $_grp('stats') ?>>
          <div class="pc-section-label">Statistics</div>
          <div class="form-group">
            <textarea name="collective_stats_template" id="collective_stats_template" class="form form-mono" spellcheck="false"><?= View::e($statsVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="collective_stats" data-textarea="collective_stats_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_stats_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── Codes Display ── -->
        <div class="tpl-group-section" data-tpl-group="codes" <?= $_grp('codes') ?>>
          <div class="pc-section-label">Collective Codes</div>
          <p class="form-hint mb-sm">Controls how collective-wide fan codes (codes with no fanlisting assigned) are displayed. Use <code>{each_size_group}&hellip;{/each_size_group}</code> to group codes by dimensions, or a single <code>{each_code}&hellip;{/each_code}</code> loop for a flat gallery.</p>
          <div class="form-group">
            <textarea name="collective_codes_template" id="collective_codes_template" class="form form-mono" spellcheck="false"><?= View::e($collectiveCodesVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="collective_codes" data-textarea="collective_codes_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_codes_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── Codes Donation Form ── -->
        <div class="tpl-group-section" data-tpl-group="codes-form" <?= $_grp('codes-form') ?>>
          <div class="pc-section-label">Collective Codes Donation Form</div>
          <p class="form-hint mb-sm">Controls the layout of the code donation form shown by the collective Codes Donation embed. Use the tokens on the right to arrange fields. Leave blank to use the built-in form.</p>
          <div class="form-group">
            <textarea name="collective_codes_submit_form_template" id="collective_codes_submit_form_template" class="form form-mono" spellcheck="false"><?= View::e($collectiveCodesSubmitFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="collective_codes_submit_form" data-textarea="collective_codes_submit_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_codes_submit_form_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── Affiliates Display ── -->
        <div class="tpl-group-section" data-tpl-group="affiliates" <?= $_grp('affiliates') ?>>
          <div class="pc-section-label">Affiliates</div>
          <p class="form-hint mb-sm">Controls the full affiliates section on <code>/affiliates</code> and the collective affiliates embed. Use <code>{each_affiliate}&hellip;{/each_affiliate}</code> to design each row yourself.</p>
          <div class="form-group">
            <textarea name="collective_affiliates_section_template" id="collective_affiliates_section_template" class="form form-mono" spellcheck="false"><?= View::e($affSectionVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="collective_affiliates_section" data-textarea="collective_affiliates_section_template"><?= View::icon('eye-on') ?> Preview</button>
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_affiliates_section_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── Affiliate Application ── -->
        <div class="tpl-group-section" data-tpl-group="affiliate-form" <?= $_grp('affiliate-form') ?>>
          <div class="pc-section-label">Collective Affiliate Application Form</div>
          <p class="form-hint mb-sm">Controls the layout of the collective affiliate application form. Use the tokens on the right to arrange fields. This template is used by the affiliate-apply embed in collective mode.</p>
          <div class="form-group">
            <textarea name="collective_affiliate_apply_form_template" id="collective_affiliate_apply_form_template" class="form form-mono" spellcheck="false"><?= View::e($affApplyFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="collective_affiliate_apply_form" data-textarea="collective_affiliate_apply_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_affiliate_apply_form_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── Contact Form ── -->
        <div class="tpl-group-section" data-tpl-group="contact" <?= $_grp('contact') ?>>
          <div class="pc-section-label">Collective Contact Form</div>
          <p class="form-hint mb-sm">Controls the layout of the collective contact form. Use the tokens on the right to arrange fields. Leave blank to use the built-in form.</p>
          <div class="form-group">
            <textarea name="collective_contact_form_template" id="collective_contact_form_template" class="form form-mono" spellcheck="false"><?= View::e($contactFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="collective_contact_form" data-textarea="collective_contact_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_contact_form_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── Contact Confirmation ── -->
        <div class="tpl-group-section" data-tpl-group="contact-sent" <?= $_grp('contact-sent') ?>>
          <div class="pc-section-label">Contact Sent Message</div>
          <p class="form-hint mb-sm">The confirmation message shown after a visitor submits the collective contact form.</p>
          <div class="form-group">
            <textarea name="collective_contact_sent_template" id="collective_contact_sent_template" class="form form-mono" spellcheck="false"><?= View::e($contactSentVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="collective_contact_sent" data-textarea="collective_contact_sent_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_contact_sent_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── KIM List Overview ── -->
        <div class="tpl-group-section" data-tpl-group="kim-overview" <?= $_grp('kim-overview') ?>>
          <div class="pc-section-label">KIM List Overview</div>
          <p class="form-hint mb-sm">Controls the layout of the public KIM list overview (<code>/kim/list</code>). Use <code>{each_kim_listing}&hellip;{/each_kim_listing}</code> to design each fanlisting row.</p>
          <div class="form-group">
            <textarea name="collective_kim_list_overview_template" id="collective_kim_list_overview_template" class="form form-mono" spellcheck="false"><?= View::e($kimListOverviewVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="collective_kim_list_overview" data-textarea="collective_kim_list_overview_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_kim_list_overview_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── KIM Listing View ── -->
        <div class="tpl-group-section" data-tpl-group="kim-subject" <?= $_grp('kim-subject') ?>>
          <div class="pc-section-label">KIM List &mdash; Subject View</div>
          <p class="form-hint mb-sm">Controls the layout of the KIM member list for a single fanlisting (<code>/kim/list/{id}</code>). Use <code>{each_kim_member}&hellip;{/each_kim_member}</code> to design each member row.</p>
          <div class="form-group">
            <textarea name="collective_kim_list_subject_template" id="collective_kim_list_subject_template" class="form form-mono" spellcheck="false"><?= View::e($kimListSubjectVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="collective_kim_list_subject" data-textarea="collective_kim_list_subject_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_kim_list_subject_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── KIM Sign-Up Form ── -->
        <div class="tpl-group-section" data-tpl-group="kim-signup" <?= $_grp('kim-signup') ?>>
          <div class="pc-section-label">KIM Sign-Up Form</div>
          <p class="form-hint mb-sm">Controls the layout of the KIM (Keep In Mind) sign-up form. Use the tokens on the right to arrange fields.</p>
          <div class="form-group">
            <textarea name="kim_signup_form_template" id="kim_signup_form_template" class="form form-mono" spellcheck="false"><?= View::e($kimSignupFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="kim_signup_form" data-textarea="kim_signup_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="kim_signup_form_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── KIM Update Form ── -->
        <div class="tpl-group-section" data-tpl-group="kim-update" <?= $_grp('kim-update') ?>>
          <div class="pc-section-label">KIM Update Form</div>
          <p class="form-hint mb-sm">Controls the layout of the KIM update form. Use the tokens on the right to arrange fields. Leave blank to use the built-in form.</p>
          <div class="form-group">
            <textarea name="kim_update_form_template" id="kim_update_form_template" class="form form-mono" spellcheck="false"><?= View::e($kimUpdateFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="kim_update_form" data-textarea="kim_update_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="kim_update_form_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── KIM Reset Form ── -->
        <div class="tpl-group-section" data-tpl-group="kim-reset" <?= $_grp('kim-reset') ?>>
          <div class="pc-section-label">KIM Reset Form</div>
          <p class="form-hint mb-sm">Controls the layout of the KIM password reset form &mdash; both the email-entry step and the new-password step. Use <code>{if_step_email}&hellip;{/if_step_email}</code> and <code>{if_step_confirm}&hellip;{/if_step_confirm}</code> to separate the two steps. Leave blank to use the built-in form.</p>
          <div class="form-group">
            <textarea name="kim_reset_form_template" id="kim_reset_form_template" class="form form-mono" spellcheck="false"><?= View::e($kimResetFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="kim_reset_form" data-textarea="kim_reset_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="kim_reset_form_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── Adoption ── -->
        <div class="tpl-group-section" data-tpl-group="adoption" <?= $_grp('adoption') ?>>
          <div class="pc-section-label">Collective Adoption Form</div>
          <p class="form-hint mb-sm">Controls the layout of the collective adoption application form. Use the tokens on the right to arrange fields. Requires the Adoption add-on.</p>
          <div class="form-group">
            <textarea name="collective_adoption_form_template" id="collective_adoption_form_template" class="form form-mono" spellcheck="false"><?= View::e($adoptionFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="collective_adopt_form" data-textarea="collective_adoption_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_adoption_form_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── Wishlist ── -->
        <div class="tpl-group-section" data-tpl-group="wishlist" <?= $_grp('wishlist') ?>>
          <div class="pc-section-label">Wishlist</div>
          <p class="form-hint mb-sm">Used by the Wishlist embed. This template is self-contained &mdash; include your own wrapper element.</p>
          <div class="form-group">
            <textarea name="wishlist_template" id="wishlist_template" class="form form-mono" spellcheck="false"><?= View::e($wishlistVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="wishlist" data-textarea="wishlist_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="wishlist_template">Reset to Default</button>
          </div>
        </div>

        <!-- ── Updates Feed ── -->
        <div class="tpl-group-section" data-tpl-group="updates" <?= $_grp('updates') ?>>
          <div class="pc-section-label">Updates Feed</div>
          <p class="form-hint mb-sm">Used by the collective Updates Feed embed. Wrap each post in <code>{each_update}&hellip;{/each_update}</code> &mdash; the outer wrapper and pagination are added automatically.</p>
          <div class="form-group">
            <textarea name="collective_updates_template" id="collective_updates_template" class="form form-mono" spellcheck="false"><?= View::e($collectiveUpdatesVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="collective_updates" data-textarea="collective_updates_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="collective_updates_template">Reset to Default</button>
          </div>
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
      <details class="card chevron" data-token-card data-tpl-group="owned" <?= $_grp('owned') ?>>
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
            <?= $tok('owned_template', '{approved_by}',        '{approved_by}',        'Network acronym: &ldquo;TFL&rdquo;, &ldquo;TAFL&rdquo;, or empty. Plain text.') ?>
            <?= $tok('owned_template', '{network}',            '{network}',            'Same as {approved_by} &mdash; network acronym as raw text. Empty when not set.') ?>
            <?= $tok('owned_template', '{network_status}',    '{network_status}',    'Deprecated alias for {network}.') ?>
            <?= $tok('owned_template', '{network_name}',      '{network_name}',      'Full network name, or empty.') ?>
            <?= $tok('owned_template', '{tfl_url}',            '{tfl_url}',            'TFL website URL.') ?>
            <?= $tok('owned_template', '{tafl_url}',           '{tafl_url}',           'TAFL website URL.') ?>
            <?= $tok('owned_template', '{listing_description}', '{listing_description}', 'The listing description, if set on the Edit Listing page.') ?>
            <?= $tok('owned_template', '{if_listing_image}{/if_listing_image}',       '{if_listing_image}...{/if_listing_image}',       'Shows only when the listing has an image.') ?>
            <?= $tok('owned_template', '{if_countries}{/if_countries}',               '{if_countries}...{/if_countries}',               'Shows only when this listing has at least one country.') ?>
            <?= $tok('owned_template', '{if_description}{/if_description}',          '{if_description}...{/if_description}',          'Shows only when this listing has a description set.') ?>
            <?= $tok('owned_template', '{if_pending}{/if_pending}',                   '{if_pending}...{/if_pending}',                   'Shows only when this listing has members waiting for approval.') ?>
            <?= $tok('owned_template', '{if_status_current}{/if_status_current}',     '{if_status_current}...{/if_status_current}',     'Shows only when the listing is current.') ?>
            <?= $tok('owned_template', '{if_status_upcoming}{/if_status_upcoming}',   '{if_status_upcoming}...{/if_status_upcoming}',   'Shows only when the listing is upcoming.') ?>
            <?= $tok('owned_template', '{if_status_pending}{/if_status_pending}',     '{if_status_pending}...{/if_status_pending}',     'Shows only when the listing is pending.') ?>
            <?= $tok('owned_template', '{if_date_updated}{/if_date_updated}',         '{if_date_updated}...{/if_date_updated}',         'Shows only when the last-updated date is set.') ?>
            <?= $tok('owned_template', '{if_tfl}{/if_tfl}',                           '{if_tfl}...{/if_tfl}',                           'Shows only when this listing is TFL-approved.') ?>
            <?= $tok('owned_template', '{if_tafl}{/if_tafl}',                         '{if_tafl}...{/if_tafl}',                         'Shows only when this listing is TAFL-approved.') ?>
            <?= $tok('owned_template', '{if_approved_by}{/if_approved_by}',           '{if_approved_by}...{/if_approved_by}',           'Shows only when this listing is approved by either network.') ?>
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

      <!-- ── Latest Owned ───────────────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="latest-owned" <?= $_grp('latest-owned') ?>>
        <summary class="chevron-head">Latest Owned Tokens</summary>
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
            <?= $tok('latest_listing_template', '{approved_by}',        '{approved_by}',        'Network acronym: &ldquo;TFL&rdquo;, &ldquo;TAFL&rdquo;, or empty. Plain text.') ?>
            <?= $tok('latest_listing_template', '{network}',            '{network}',            'Same as {approved_by} &mdash; network acronym as raw text. Empty when not set.') ?>
            <?= $tok('latest_listing_template', '{network_status}',    '{network_status}',    'Deprecated alias for {network}.') ?>
            <?= $tok('latest_listing_template', '{network_name}',      '{network_name}',      'Full network name, or empty.') ?>
            <?= $tok('latest_listing_template', '{tfl_url}',            '{tfl_url}',            'TFL website URL.') ?>
            <?= $tok('latest_listing_template', '{tafl_url}',           '{tafl_url}',           'TAFL website URL.') ?>
            <?= $tok('latest_listing_template', '{listing_description}', '{listing_description}', 'The listing description, if set on the Edit Listing page.') ?>
            <?= $tok('latest_listing_template', '{if_listing_image}{/if_listing_image}',     '{if_listing_image}...{/if_listing_image}',     'Shows only when the listing has an image.') ?>
            <?= $tok('latest_listing_template', '{if_countries}{/if_countries}',             '{if_countries}...{/if_countries}',             'Shows only when this listing has at least one country.') ?>
            <?= $tok('latest_listing_template', '{if_description}{/if_description}',         '{if_description}...{/if_description}',         'Shows only when this listing has a description set.') ?>
            <?= $tok('latest_listing_template', '{if_pending}{/if_pending}',                 '{if_pending}...{/if_pending}',                 'Shows only when this listing has members waiting for approval.') ?>
            <?= $tok('latest_listing_template', '{if_status_current}{/if_status_current}',   '{if_status_current}...{/if_status_current}',   'Shows only when the listing is current.') ?>
            <?= $tok('latest_listing_template', '{if_status_upcoming}{/if_status_upcoming}', '{if_status_upcoming}...{/if_status_upcoming}', 'Shows only when the listing is upcoming.') ?>
            <?= $tok('latest_listing_template', '{if_status_pending}{/if_status_pending}',   '{if_status_pending}...{/if_status_pending}',   'Shows only when the listing is pending.') ?>
            <?= $tok('latest_listing_template', '{if_date_updated}{/if_date_updated}',       '{if_date_updated}...{/if_date_updated}',       'Shows only when the last-updated date is set.') ?>
            <?= $tok('latest_listing_template', '{if_tfl}{/if_tfl}',                         '{if_tfl}...{/if_tfl}',                         'Shows only when this listing is TFL-approved.') ?>
            <?= $tok('latest_listing_template', '{if_tafl}{/if_tafl}',                       '{if_tafl}...{/if_tafl}',                       'Shows only when this listing is TAFL-approved.') ?>
            <?= $tok('latest_listing_template', '{if_approved_by}{/if_approved_by}',         '{if_approved_by}...{/if_approved_by}',         'Shows only when this listing is approved by either network.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Random Owned ───────────────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="random-owned" <?= $_grp('random-owned') ?>>
        <summary class="chevron-head">Random Owned Tokens</summary>
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
            <?= $tok('random_listing_template', '{approved_by}',        '{approved_by}',        'Network acronym: &ldquo;TFL&rdquo;, &ldquo;TAFL&rdquo;, or empty. Plain text.') ?>
            <?= $tok('random_listing_template', '{network}',            '{network}',            'Same as {approved_by} &mdash; network acronym as raw text. Empty when not set.') ?>
            <?= $tok('random_listing_template', '{network_status}',    '{network_status}',    'Deprecated alias for {network}.') ?>
            <?= $tok('random_listing_template', '{network_name}',      '{network_name}',      'Full network name, or empty.') ?>
            <?= $tok('random_listing_template', '{tfl_url}',            '{tfl_url}',            'TFL website URL.') ?>
            <?= $tok('random_listing_template', '{tafl_url}',           '{tafl_url}',           'TAFL website URL.') ?>
            <?= $tok('random_listing_template', '{listing_description}', '{listing_description}', 'The listing description, if set on the Edit Listing page.') ?>
            <?= $tok('random_listing_template', '{if_listing_image}{/if_listing_image}',     '{if_listing_image}...{/if_listing_image}',     'Shows only when the listing has an image.') ?>
            <?= $tok('random_listing_template', '{if_countries}{/if_countries}',             '{if_countries}...{/if_countries}',             'Shows only when this listing has at least one country.') ?>
            <?= $tok('random_listing_template', '{if_description}{/if_description}',         '{if_description}...{/if_description}',         'Shows only when this listing has a description set.') ?>
            <?= $tok('random_listing_template', '{if_pending}{/if_pending}',                 '{if_pending}...{/if_pending}',                 'Shows only when this listing has members waiting for approval.') ?>
            <?= $tok('random_listing_template', '{if_status_current}{/if_status_current}',   '{if_status_current}...{/if_status_current}',   'Shows only when the listing is current.') ?>
            <?= $tok('random_listing_template', '{if_status_upcoming}{/if_status_upcoming}', '{if_status_upcoming}...{/if_status_upcoming}', 'Shows only when the listing is upcoming.') ?>
            <?= $tok('random_listing_template', '{if_status_pending}{/if_status_pending}',   '{if_status_pending}...{/if_status_pending}',   'Shows only when the listing is pending.') ?>
            <?= $tok('random_listing_template', '{if_date_updated}{/if_date_updated}',       '{if_date_updated}...{/if_date_updated}',       'Shows only when the last-updated date is set.') ?>
            <?= $tok('random_listing_template', '{if_tfl}{/if_tfl}',                         '{if_tfl}...{/if_tfl}',                         'Shows only when this listing is TFL-approved.') ?>
            <?= $tok('random_listing_template', '{if_tafl}{/if_tafl}',                       '{if_tafl}...{/if_tafl}',                       'Shows only when this listing is TAFL-approved.') ?>
            <?= $tok('random_listing_template', '{if_approved_by}{/if_approved_by}',         '{if_approved_by}...{/if_approved_by}',         'Shows only when this listing is approved by either network.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Joined Listings ────────────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="joined" <?= $_grp('joined') ?>>
        <summary class="chevron-head">Joined Listings Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Use {each_joined}...{/each_joined} for a flat list. Use {each_current_joined}...{/each_current_joined} when you want the list to follow category order.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('joined_template', '{each_joined}{/each_joined}',                 '{each_joined}...{/each_joined}',                 'Repeats the HTML inside it once for each joined listing. Use this for a simple list.') ?>
            <?= $tok('joined_template', '{each_current_joined}{/each_current_joined}', '{each_current_joined}...{/each_current_joined}', 'Repeats the HTML inside it once for each joined listing, in category order.') ?>
            <?= $tok('joined_template', '{listing_subject}',    '{listing_subject}',    'What this fanlisting is about (e.g. Archeology, Marilyn Monroe).') ?>
            <?= $tok('joined_template', '{listing_url}',        '{listing_url}',        'The link to the joined fanlisting.') ?>
            <?= $tok('joined_template', '{listing_category}',   '{listing_category}',   'The category saved for this joined listing.') ?>
            <?= $tok('joined_template', '{listing_image_url}',  '{listing_image_url}',  'The image URL. Empty when there is no image.') ?>
            <?= $tok('joined_template', '{listing_image_html}', '{listing_image_html}', 'A ready-made img tag. Empty when there is no image.') ?>
            <?= $tok('joined_template', '{listing_status}',     '{listing_status}',     'The joined listing status.') ?>
            <?= $tok('joined_template', '{date_joined}',        '{date_joined}',        'The date you added this joined listing.') ?>
            <?= $tok('joined_template', '{if_listing_image}{/if_listing_image}',         '{if_listing_image}...{/if_listing_image}',         'Shows only when the joined listing has an image.') ?>
            <?= $tok('joined_template', '{if_no_listing_image}{/if_no_listing_image}', '{if_no_listing_image}...{/if_no_listing_image}', 'Shows only when the joined listing has no image.') ?>
            <?= $tok('joined_template', '{current_count}',  '{current_count}',  'How many joined listings are being shown.') ?>
            <?= $tok('joined_template', '{total_count}',    '{total_count}',    'The total number of joined listings in this view.') ?>
            <?= $tok('joined_template', '{if_current_listings}{/if_current_listings}', '{if_current_listings}...{/if_current_listings}', 'Shows only when there is at least one joined listing.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Latest Joined Listing ──────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="latest-joined" <?= $_grp('latest-joined') ?>>
        <summary class="chevron-head">Latest Joined Listing Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Always renders one joined listing. Wrap your row design in {each_joined}...{/each_joined} — it is required for tokens to be replaced.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('latest_joined_listing_template', '{each_joined}{/each_joined}', '{each_joined}...{/each_joined}', 'Wrap your row design inside this. Required for token replacement.') ?>
            <?= $tok('latest_joined_listing_template', '{listing_subject}',    '{listing_subject}',    'What this fanlisting is about (e.g. Archeology, Marilyn Monroe).') ?>
            <?= $tok('latest_joined_listing_template', '{listing_url}',        '{listing_url}',        'The link to the joined fanlisting.') ?>
            <?= $tok('latest_joined_listing_template', '{listing_category}',   '{listing_category}',   'The category saved for this joined listing.') ?>
            <?= $tok('latest_joined_listing_template', '{listing_image_url}',  '{listing_image_url}',  'The image URL. Empty when there is no image.') ?>
            <?= $tok('latest_joined_listing_template', '{listing_image_html}', '{listing_image_html}', 'A ready-made img tag. Empty when there is no image.') ?>
            <?= $tok('latest_joined_listing_template', '{listing_status}',     '{listing_status}',     'The joined listing status.') ?>
            <?= $tok('latest_joined_listing_template', '{date_joined}',        '{date_joined}',        'The date you joined this listing.') ?>
            <?= $tok('latest_joined_listing_template', '{if_listing_image}{/if_listing_image}',         '{if_listing_image}...{/if_listing_image}',         'Shows only when the joined listing has an image.') ?>
            <?= $tok('latest_joined_listing_template', '{if_no_listing_image}{/if_no_listing_image}', '{if_no_listing_image}...{/if_no_listing_image}', 'Shows only when the joined listing has no image.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Random Joined Listing ──────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="random-joined" <?= $_grp('random-joined') ?>>
        <summary class="chevron-head">Random Joined Listing Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Always renders one joined listing. Wrap your row design in {each_joined}...{/each_joined} — it is required for tokens to be replaced.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('random_joined_listing_template', '{each_joined}{/each_joined}', '{each_joined}...{/each_joined}', 'Wrap your row design inside this. Required for token replacement.') ?>
            <?= $tok('random_joined_listing_template', '{listing_subject}',    '{listing_subject}',    'What this fanlisting is about (e.g. Archeology, Marilyn Monroe).') ?>
            <?= $tok('random_joined_listing_template', '{listing_url}',        '{listing_url}',        'The link to the joined fanlisting.') ?>
            <?= $tok('random_joined_listing_template', '{listing_category}',   '{listing_category}',   'The category saved for this joined listing.') ?>
            <?= $tok('random_joined_listing_template', '{listing_image_url}',  '{listing_image_url}',  'The image URL. Empty when there is no image.') ?>
            <?= $tok('random_joined_listing_template', '{listing_image_html}', '{listing_image_html}', 'A ready-made img tag. Empty when there is no image.') ?>
            <?= $tok('random_joined_listing_template', '{listing_status}',     '{listing_status}',     'The joined listing status.') ?>
            <?= $tok('random_joined_listing_template', '{date_joined}',        '{date_joined}',        'The date you added this joined listing.') ?>
            <?= $tok('random_joined_listing_template', '{if_listing_image}{/if_listing_image}',         '{if_listing_image}...{/if_listing_image}',         'Shows only when the joined listing has an image.') ?>
            <?= $tok('random_joined_listing_template', '{if_no_listing_image}{/if_no_listing_image}', '{if_no_listing_image}...{/if_no_listing_image}', 'Shows only when the joined listing has no image.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Collective Statistics ─────────────────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="stats" <?= $_grp('stats') ?>>
        <summary class="chevron-head">Statistics Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Summary numbers for the whole collective. These are template tokens, not PHP variables for sample pages.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('collective_stats_template', '{owned_active}',      '{owned_active}',      'How many owned listings are currently open.') ?>
            <?= $tok('collective_stats_template', '{owned_upcoming}',    '{owned_upcoming}',    'How many owned listings are marked upcoming.') ?>
            <?= $tok('collective_stats_template', '{owned_pending}',     '{owned_pending}',     'How many owned listings are marked pending.') ?>
            <?= $tok('collective_stats_template', '{owned_tfl_count}',   '{owned_tfl_count}',   'How many active owned listings are approved by TFL.') ?>
            <?= $tok('collective_stats_template', '{owned_tafl_count}',  '{owned_tafl_count}',  'How many active owned listings are approved by TAFL.') ?>
            <?= $tok('collective_stats_template', '{joined_active}',     '{joined_active}',     'How many joined listings are active.') ?>
            <?= $tok('collective_stats_template', '{joined_pending}',    '{joined_pending}',    'How many joined listings are awaiting approval.') ?>
            <?= $tok('collective_stats_template', '{joined_tfl_count}',  '{joined_tfl_count}',  'How many active joined listings are in TFL categories.') ?>
            <?= $tok('collective_stats_template', '{joined_tafl_count}', '{joined_tafl_count}', 'How many active joined listings are in TAFL categories.') ?>
            <?= $tok('collective_stats_template', '{members_total}',     '{members_total}',     'Total members across your owned listings, approved and pending.') ?>
            <?= $tok('collective_stats_template', '{members_approved}',  '{members_approved}',  'Approved members across your owned listings.') ?>
            <?= $tok('collective_stats_template', '{members_pending}',   '{members_pending}',   'Members waiting for approval across your owned listings.') ?>
            <?= $tok('collective_stats_template', '{owned_newest_name}', '{owned_newest_name}', 'The display name of your newest owned listing.') ?>
            <?= $tok('collective_stats_template', '{owned_newest_url}',  '{owned_newest_url}',  'The link to your newest owned listing.') ?>
            <?= $tok('collective_stats_template', '{owned_random_name}', '{owned_random_name}', 'The display name of a randomly chosen owned listing.') ?>
            <?= $tok('collective_stats_template', '{owned_random_url}',  '{owned_random_url}',  'The link to a randomly chosen owned listing.') ?>
            <?= $tok('collective_stats_template', '{countries_count}',   '{countries_count}',   'How many different countries are represented across your owned listings.') ?>
            <?= $tok('collective_stats_template', '{collective_last_updated}', '{collective_last_updated}', 'The date your newest owned listing was opened — the last time the collective grew.') ?>
            <?= $tok('collective_stats_template', '{tfl_url}',           '{tfl_url}',           'TFL website URL: <code>https://thefanlistings.org/</code>') ?>
            <?= $tok('collective_stats_template', '{tafl_url}',          '{tafl_url}',          'TAFL website URL: <code>https://www.animefanlistings.com/</code>') ?>
            <?= $tok('collective_stats_template', '{if_countries}{/if_countries}', '{if_countries}...{/if_countries}', 'Shows only when the collective country count is greater than zero.') ?>
            <?= $tok('collective_stats_template', '{if_pending}{/if_pending}', '{if_pending}...{/if_pending}', 'Shows only when at least one member across your owned listings is waiting for approval.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Collective Codes ───────────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="codes" <?= $_grp('codes') ?>>
        <summary class="chevron-head">Collective Codes Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Use {each_size_group}...{/each_size_group} (with an inner {each_code}...{/each_code}) to group codes by dimensions, or a single {each_code}...{/each_code} loop for a flat gallery. Renders codes with no fanlisting assigned.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('collective_codes_template', '{each_size_group}{/each_size_group}', '{each_size_group}...{/each_size_group}', 'Repeats the HTML inside it once per group of same-sized codes. Wrap an {each_code}...{/each_code} loop inside it.') ?>
            <?= $tok('collective_codes_template', '{group_size}',       '{group_size}',       'The group label, e.g. "88×31px" or "Other". Use inside {each_size_group}.') ?>
            <?= $tok('collective_codes_template', '{group_code_count}', '{group_code_count}', 'How many codes are in this size group. Use inside {each_size_group}.') ?>
            <?= $tok('collective_codes_template', '{each_code}{/each_code}', '{each_code}...{/each_code}', 'Repeats the HTML inside it once for each approved collective code shown.') ?>
            <?= $tok('collective_codes_template', '{code_url}',        '{code_url}',        'The full image URL for the code.') ?>
            <?= $tok('collective_codes_template', '{code_alt}',        '{code_alt}',        'Suggested alt text: donor name, then size, then site name. Good for &lt;img alt=&quot;{code_alt}&quot;&gt;.') ?>
            <?= $tok('collective_codes_template', '{code_width}',      '{code_width}',      'The code\'s width in pixels. Empty when dimensions are unknown.') ?>
            <?= $tok('collective_codes_template', '{code_height}',     '{code_height}',     'The code\'s height in pixels. Empty when dimensions are unknown.') ?>
            <?= $tok('collective_codes_template', '{code_size}',       '{code_size}',       'The code\'s dimensions as &ldquo;WxH&rdquo;, e.g. &ldquo;88×31&rdquo;. Empty when unknown.') ?>
            <?= $tok('collective_codes_template', '{code_name}',       '{code_name}',       'The code\'s saved name/title. Empty when not set.') ?>
            <?= $tok('collective_codes_template', '{code_categories}', '{code_categories}', 'Comma-separated code category names. Empty when no categories assigned.') ?>
            <?= $tok('collective_codes_template', '{code_donor_name}', '{code_donor_name}', 'The donor\'s name. Empty when the code was not donated.') ?>
            <?= $tok('collective_codes_template', '{code_donor_url}',  '{code_donor_url}',  'The donor\'s website URL. Empty when not set.') ?>
            <?= $tok('collective_codes_template', '{if_size}{/if_size}',           '{if_size}...{/if_size}',           'Shows only when width and height are known.') ?>
            <?= $tok('collective_codes_template', '{if_size_changed}{/if_size_changed}', '{if_size_changed}...{/if_size_changed}', 'Flat mode only. Shows when the code size differs from the previous code &mdash; useful for size headings.') ?>
            <?= $tok('collective_codes_template', '{if_donor}{/if_donor}',         '{if_donor}...{/if_donor}',         'Shows only when the code was donated by a named donor.') ?>
            <?= $tok('collective_codes_template', '{if_no_donor}{/if_no_donor}',   '{if_no_donor}...{/if_no_donor}',   'Shows only when the code has no donor.') ?>
            <?= $tok('collective_codes_template', '{if_donor_url}{/if_donor_url}', '{if_donor_url}...{/if_donor_url}', 'Shows only when the donor has a website URL saved.') ?>
            <?= $tok('collective_codes_template', '{if_no_donor_url}{/if_no_donor_url}', '{if_no_donor_url}...{/if_no_donor_url}', 'Shows only when the donor has no website URL.') ?>
            <?= $tok('collective_codes_template', '{if_categories}{/if_categories}', '{if_categories}...{/if_categories}', 'Shows only when the code has categories assigned.') ?>
            <?= $tok('collective_codes_template', '{if_code_name}{/if_code_name}',   '{if_code_name}...{/if_code_name}',   'Shows only when the code has a saved name.') ?>
            <?= $tok('collective_codes_template', '{if_codes}{/if_codes}',       '{if_codes}...{/if_codes}',       'Shows when there is at least one approved collective code. Place outside the loop.') ?>
            <?= $tok('collective_codes_template', '{if_no_codes}{/if_no_codes}', '{if_no_codes}...{/if_no_codes}', 'Shows when there are no approved collective codes (empty state). Place outside the loop.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Collective Codes Donation Form ────────── -->
      <details class="card chevron" data-token-card data-tpl-group="codes-form" <?= $_grp('codes-form') ?>>
        <summary class="chevron-head">Collective Codes Donation Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Controls the layout of the collective code donation form. Leave the template blank to use the built-in form. Field tokens output the full label + input block; <code>_input</code> variants output just the raw input element.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('collective_codes_submit_form_template', '{site_name}',                    '{site_name}',                    'The collective/site name.') ?>
            <?= $tok('collective_codes_submit_form_template', '{field_code_image}',             '{field_code_image}',             'Full label + input block for the code image upload field.') ?>
            <?= $tok('collective_codes_submit_form_template', '{field_code_image_input}',       '{field_code_image_input}',       'Just the file input element.') ?>
            <?= $tok('collective_codes_submit_form_template', '{field_submitter_name}',         '{field_submitter_name}',         'Full label + input block for the submitter name field.') ?>
            <?= $tok('collective_codes_submit_form_template', '{field_submitter_name_input}',   '{field_submitter_name_input}',   'Just the submitter name text input element.') ?>
            <?= $tok('collective_codes_submit_form_template', '{field_submitter_email}',        '{field_submitter_email}',        'Full label + input block for the submitter email field.') ?>
            <?= $tok('collective_codes_submit_form_template', '{field_submitter_email_input}',  '{field_submitter_email_input}',  'Just the submitter email input element.') ?>
            <?= $tok('collective_codes_submit_form_template', '{field_submitter_url}',          '{field_submitter_url}',          'Full label + input block for the submitter website field.') ?>
            <?= $tok('collective_codes_submit_form_template', '{field_submitter_url_input}',    '{field_submitter_url_input}',    'Just the submitter website input element.') ?>
            <?= $tok('collective_codes_submit_form_template', '{captcha_block}',                '{captcha_block}',                'Full label + input block for the captcha field.') ?>
            <?= $tok('collective_codes_submit_form_template', '{captcha_block_input}',          '{captcha_block_input}',          'Just the captcha text input element.') ?>
            <?= $tok('collective_codes_submit_form_template', '{captcha_question}',             '{captcha_question}',             'The arithmetic question text only (e.g. "2 + 3").') ?>
            <?= $tok('collective_codes_submit_form_template', '{submit_button}',                '{submit_button}',                'The submit button.') ?>
            <?= $tok('collective_codes_submit_form_template', '{clear_button}',                 '{clear_button}',                 'A reset button that clears the form.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Collective Affiliates ─────────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="affiliates" <?= $_grp('affiliates') ?>>
        <summary class="chevron-head">Affiliates Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Use {each_affiliate}...{/each_affiliate} to design each row yourself.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('collective_affiliates_section_template', '{each_affiliate}{/each_affiliate}', '{each_affiliate}...{/each_affiliate}', 'Repeats the HTML inside it once for each affiliate.') ?>
            <?= $tok('collective_affiliates_section_template', '{aff_name}',      '{aff_name}',      'The affiliate site name. Use inside {each_affiliate}.') ?>
            <?= $tok('collective_affiliates_section_template', '{aff_url}',       '{aff_url}',       'The affiliate site link. Use inside {each_affiliate}.') ?>
            <?= $tok('collective_affiliates_section_template', '{aff_image_url}', '{aff_image_url}', 'The affiliate image URL. Empty when there is no image. Use inside {each_affiliate}.') ?>
            <?= $tok('collective_affiliates_section_template', '{affiliates_count}', '{affiliates_count}', 'How many active affiliates there are.') ?>
            <?= $tok('collective_affiliates_section_template', '{affiliate_apply_url}', '{affiliate_apply_url}', 'The link to the collective affiliate application form.') ?>
            <?= $tok('collective_affiliates_section_template', '{if_affiliates}{/if_affiliates}',       '{if_affiliates}...{/if_affiliates}',       'Shows only when there is at least one affiliate.') ?>
            <?= $tok('collective_affiliates_section_template', '{if_no_affiliates}{/if_no_affiliates}', '{if_no_affiliates}...{/if_no_affiliates}', 'Shows only when there are no affiliates.') ?>
            <?= $tok('collective_affiliates_section_template', '{if_aff_image}{/if_aff_image}',       '{if_aff_image}...{/if_aff_image}',       'Inside {each_affiliate}, shows only when the affiliate has an image.') ?>
            <?= $tok('collective_affiliates_section_template', '{if_no_aff_image}{/if_no_aff_image}', '{if_no_aff_image}...{/if_no_aff_image}', 'Inside {each_affiliate}, shows only when the affiliate has no image.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Collective Affiliate Form ──── -->
      <details class="card chevron" data-token-card data-tpl-group="affiliate-form" <?= $_grp('affiliate-form') ?>>
        <summary class="chevron-head">Affiliate Form Tokens</summary>
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

      <!-- ── Collective Contact Form ───────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="contact" <?= $_grp('contact') ?>>
        <summary class="chevron-head">Collective Contact Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Controls the layout of the collective contact form. Field tokens output the full label + input block; <code>_input</code> variants output just the raw input.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('collective_contact_form_template', '{field_name}',          '{field_name}',          'Full label + input for the name field.') ?>
            <?= $tok('collective_contact_form_template', '{field_name_input}',    '{field_name_input}',    'Just the name text input element.') ?>
            <?= $tok('collective_contact_form_template', '{field_email}',           '{field_email}',           'Full label + input for the email field.') ?>
            <?= $tok('collective_contact_form_template', '{field_email_input}',     '{field_email_input}',     'Just the email input element.') ?>
            <?= $tok('collective_contact_form_template', '{field_url}',             '{field_url}',             'Full label + input for the website URL field.') ?>
            <?= $tok('collective_contact_form_template', '{field_url_input}',       '{field_url_input}',       'Just the URL input element.') ?>
            <?= $tok('collective_contact_form_template', '{field_subject}',         '{field_subject}',         'Full label + select for the subject dropdown.') ?>
            <?= $tok('collective_contact_form_template', '{field_subject_input}',   '{field_subject_input}',   'Just the subject select element.') ?>
            <?= $tok('collective_contact_form_template', '{field_message}',         '{field_message}',         'Full label + textarea for the message field.') ?>
            <?= $tok('collective_contact_form_template', '{field_message_input}', '{field_message_input}', 'Just the message textarea element.') ?>
            <?= $tok('collective_contact_form_template', '{captcha_block}',       '{captcha_block}',       'Full label + input for the captcha field.') ?>
            <?= $tok('collective_contact_form_template', '{captcha_block_input}', '{captcha_block_input}', 'Just the captcha text input element.') ?>
            <?= $tok('collective_contact_form_template', '{captcha_question}',    '{captcha_question}',    'The arithmetic question text only (e.g. "2 + 3").') ?>
            <?= $tok('collective_contact_form_template', '{submit_button}',       '{submit_button}',       'The submit button.') ?>
            <?= $tok('collective_contact_form_template', '{clear_button}',        '{clear_button}',        'A reset button that clears the form.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Contact Sent Message ───────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="contact-sent" <?= $_grp('contact-sent') ?>>
        <summary class="chevron-head">Contact Sent Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Shown after a visitor submits the collective contact form.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('collective_contact_sent_template', '{sender_name}',  '{sender_name}',  'The name the visitor entered on the contact form.') ?>
            <?= $tok('collective_contact_sent_template', '{sender_email}', '{sender_email}', 'The email address the visitor entered on the contact form.') ?>
            <?= $tok('collective_contact_sent_template', '{site_name}',    '{site_name}',    'The collective/site name.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── KIM List Overview ─────────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="kim-overview" <?= $_grp('kim-overview') ?>>
        <summary class="chevron-head">KIM List Overview Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Shown on the public KIM list overview. Wrap each fanlisting row in {each_kim_listing}...{/each_kim_listing}.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('collective_kim_list_overview_template', '{each_kim_listing}{/each_kim_listing}', '{each_kim_listing}...{/each_kim_listing}', 'Repeats the HTML inside it once for each fanlisting that has KIM sign-ups.') ?>
            <?= $tok('collective_kim_list_overview_template', '{listing_title}',     '{listing_title}',     'Fanlisting display name. Use inside {each_kim_listing}.') ?>
            <?= $tok('collective_kim_list_overview_template', '{listing_subject}',   '{listing_subject}',   'What the fanlisting is about. Use inside {each_kim_listing}.') ?>
            <?= $tok('collective_kim_list_overview_template', '{listing_url}',       '{listing_url}',       'Link to the fanlisting. Use inside {each_kim_listing}.') ?>
            <?= $tok('collective_kim_list_overview_template', '{listing_kim_url}',   '{listing_kim_url}',   'Link to the KIM member list for this fanlisting. Use inside {each_kim_listing}.') ?>
            <?= $tok('collective_kim_list_overview_template', '{listing_kim_count}', '{listing_kim_count}', 'How many approved KIM members this fanlisting has. Use inside {each_kim_listing}.') ?>
            <?= $tok('collective_kim_list_overview_template', '{if_has_listings}{/if_has_listings}', '{if_has_listings}...{/if_has_listings}', 'Shows only when there is at least one fanlisting with KIM sign-ups.') ?>
            <?= $tok('collective_kim_list_overview_template', '{if_no_listings}{/if_no_listings}',   '{if_no_listings}...{/if_no_listings}',   'Shows only when no fanlistings have KIM sign-ups yet.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── KIM List Subject ───────────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="kim-subject" <?= $_grp('kim-subject') ?>>
        <summary class="chevron-head">KIM Subject View Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Shown when a visitor views KIM members for a single fanlisting. Wrap each member in {each_kim_member}...{/each_kim_member}.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('collective_kim_list_subject_template', '{listing_title}',                '{listing_title}',                'Fanlisting display name.') ?>
            <?= $tok('collective_kim_list_subject_template', '{listing_subject}',              '{listing_subject}',              'The fanlisting subject (what it is about).') ?>
            <?= $tok('collective_kim_list_subject_template', '{listing_url}',                  '{listing_url}',                  'Link to the fanlisting.') ?>
            <?= $tok('collective_kim_list_subject_template', '{each_kim_member}{/each_kim_member}', '{each_kim_member}...{/each_kim_member}', 'Repeats the HTML inside it once for each approved KIM member.') ?>
            <?= $tok('collective_kim_list_subject_template', '{member_name}',                 '{member_name}',                 'The KIM member\'s name. Use inside {each_kim_member}.') ?>
            <?= $tok('collective_kim_list_subject_template', '{member_url}',                  '{member_url}',                  'The KIM member\'s website URL. Empty when not set. Use inside {each_kim_member}.') ?>
            <?= $tok('collective_kim_list_subject_template', '{kim_overview_url}',             '{kim_overview_url}',             'Link back to the KIM list overview page.') ?>
            <?= $tok('collective_kim_list_subject_template', '{kim_signup_url}',               '{kim_signup_url}',               'Link to the KIM sign-up form.') ?>
            <?= $tok('collective_kim_list_subject_template', '{pagination}',                   '{pagination}',                   'Pagination links (embed mode only). Empty on the direct route.') ?>
            <?= $tok('collective_kim_list_subject_template', '{if_has_kim_members}{/if_has_kim_members}', '{if_has_kim_members}...{/if_has_kim_members}', 'Shows only when this fanlisting has at least one approved KIM member.') ?>
            <?= $tok('collective_kim_list_subject_template', '{if_no_kim_members}{/if_no_kim_members}',   '{if_no_kim_members}...{/if_no_kim_members}',   'Shows only when this fanlisting has no approved KIM members yet.') ?>
            <?= $tok('collective_kim_list_subject_template', '{if_member_url}{/if_member_url}',           '{if_member_url}...{/if_member_url}',           'Inside {each_kim_member}, shows only when the member has a website URL.') ?>
            <?= $tok('collective_kim_list_subject_template', '{if_no_member_url}{/if_no_member_url}',     '{if_no_member_url}...{/if_no_member_url}',     'Inside {each_kim_member}, shows only when the member has no website URL.') ?>
            <?= $tok('collective_kim_list_subject_template', '{if_kim_overview_url}{/if_kim_overview_url}', '{if_kim_overview_url}...{/if_kim_overview_url}', 'Shows only when a KIM overview URL is available.') ?>
            <?= $tok('collective_kim_list_subject_template', '{if_kim_signup_url}{/if_kim_signup_url}',     '{if_kim_signup_url}...{/if_kim_signup_url}',     'Shows only when a KIM sign-up URL is available.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── KIM Sign-Up Form ───────────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="kim-signup" <?= $_grp('kim-signup') ?>>
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

      <!-- ── KIM Update Form ───────────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="kim-update" <?= $_grp('kim-update') ?>>
        <summary class="chevron-head">KIM Update Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Controls the KIM update form layout. Field tokens output the full label + input block; <code>_input</code> variants output just the raw input.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('kim_update_form_template', '{field_name}',                       '{field_name}',                       'Full label + input for the name field.') ?>
            <?= $tok('kim_update_form_template', '{field_name_input}',                 '{field_name_input}',                 'Just the name text input element.') ?>
            <?= $tok('kim_update_form_template', '{field_current_email}',              '{field_current_email}',              'Full label + input for the current email field.') ?>
            <?= $tok('kim_update_form_template', '{field_current_email_input}',        '{field_current_email_input}',        'Just the current email input element.') ?>
            <?= $tok('kim_update_form_template', '{field_current_password}',           '{field_current_password}',           'Full label + input for the current password field.') ?>
            <?= $tok('kim_update_form_template', '{field_current_password_input}',     '{field_current_password_input}',     'Just the current password input element.') ?>
            <?= $tok('kim_update_form_template', '{field_fanlisting_id}',              '{field_fanlisting_id}',              'Full label + select for the fanlisting selector.') ?>
            <?= $tok('kim_update_form_template', '{field_fanlisting_id_input}',        '{field_fanlisting_id_input}',        'Just the fanlisting select element.') ?>
            <?= $tok('kim_update_form_template', '{field_new_email}',                  '{field_new_email}',                  'Full label + input for the new email field (optional update).') ?>
            <?= $tok('kim_update_form_template', '{field_new_email_input}',            '{field_new_email_input}',            'Just the new email input element.') ?>
            <?= $tok('kim_update_form_template', '{field_new_url}',                    '{field_new_url}',                    'Full label + input for the new website URL field (optional update).') ?>
            <?= $tok('kim_update_form_template', '{field_new_url_input}',              '{field_new_url_input}',              'Just the new URL input element.') ?>
            <?= $tok('kim_update_form_template', '{field_new_password}',               '{field_new_password}',               'Full label + input for the new password field (optional update).') ?>
            <?= $tok('kim_update_form_template', '{field_new_password_input}',         '{field_new_password_input}',         'Just the new password input element.') ?>
            <?= $tok('kim_update_form_template', '{field_new_password_confirm}',       '{field_new_password_confirm}',       'Full label + input for the confirm new password field.') ?>
            <?= $tok('kim_update_form_template', '{field_new_password_confirm_input}', '{field_new_password_confirm_input}', 'Just the confirm new password input element.') ?>
            <?= $tok('kim_update_form_template', '{field_send_copy}',                  '{field_send_copy}',                  'Full label + checkbox for the email copy opt-in.') ?>
            <?= $tok('kim_update_form_template', '{field_send_copy_input}',            '{field_send_copy_input}',            'Just the email copy checkbox element.') ?>
            <?= $tok('kim_update_form_template', '{captcha_block}',                    '{captcha_block}',                    'Full label + input for the captcha field.') ?>
            <?= $tok('kim_update_form_template', '{captcha_block_input}',              '{captcha_block_input}',              'Just the captcha text input element.') ?>
            <?= $tok('kim_update_form_template', '{captcha_question}',                 '{captcha_question}',                 'The arithmetic question text only.') ?>
            <?= $tok('kim_update_form_template', '{submit_button}',                    '{submit_button}',                    'The submit button.') ?>
            <?= $tok('kim_update_form_template', '{clear_button}',                     '{clear_button}',                     'A reset button that clears the form.') ?>
            <?= $tok('kim_update_form_template', '{lost_password_url}',                '{lost_password_url}',                'A link to the KIM password reset page.') ?>
            <?= $tok('kim_update_form_template', '{if_lost_password_url}{/if_lost_password_url}', '{if_lost_password_url}...{/if_lost_password_url}', 'Shows only when a "Forgot your password?" link is available.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── KIM Reset Form ────────────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="kim-reset" <?= $_grp('kim-reset') ?>>
        <summary class="chevron-head">KIM Reset Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Controls both steps of the KIM password reset form. Use <code>{if_step_email}</code> and <code>{if_step_confirm}</code> to separate the two flows in a single template.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('kim_reset_form_template', '{if_step_email}{/if_step_email}',   '{if_step_email}...{/if_step_email}',   'Shows only on the email-entry step (the first step).') ?>
            <?= $tok('kim_reset_form_template', '{if_step_confirm}{/if_step_confirm}', '{if_step_confirm}...{/if_step_confirm}', 'Shows only on the new-password step (after clicking the email link).') ?>
            <?= $tok('kim_reset_form_template', '{if_token_valid}{/if_token_valid}', '{if_token_valid}...{/if_token_valid}', 'Inside {if_step_confirm} — shows only when the reset token is valid.') ?>
            <?= $tok('kim_reset_form_template', '{if_token_invalid}{/if_token_invalid}', '{if_token_invalid}...{/if_token_invalid}', 'Inside {if_step_confirm} — shows only when the reset token is expired or invalid.') ?>
            <?= $tok('kim_reset_form_template', '{field_email}',                '{field_email}',                'Full label + input for the email field (email step).') ?>
            <?= $tok('kim_reset_form_template', '{field_email_input}',          '{field_email_input}',          'Just the email input element (email step).') ?>
            <?= $tok('kim_reset_form_template', '{field_fanlisting_id}',        '{field_fanlisting_id}',        'Full label + select for the fanlisting selector (email step).') ?>
            <?= $tok('kim_reset_form_template', '{field_fanlisting_id_input}',  '{field_fanlisting_id_input}',  'Just the fanlisting select element (email step).') ?>
            <?= $tok('kim_reset_form_template', '{captcha_block}',              '{captcha_block}',              'Full label + input for the captcha field (email step).') ?>
            <?= $tok('kim_reset_form_template', '{captcha_block_input}',        '{captcha_block_input}',        'Just the captcha text input element (email step).') ?>
            <?= $tok('kim_reset_form_template', '{captcha_question}',           '{captcha_question}',           'The arithmetic question text only (email step).') ?>
            <?= $tok('kim_reset_form_template', '{field_password}',             '{field_password}',             'Full label + input for the new password field (confirm step).') ?>
            <?= $tok('kim_reset_form_template', '{field_password_input}',       '{field_password_input}',       'Just the new password input element (confirm step).') ?>
            <?= $tok('kim_reset_form_template', '{field_password_confirm}',     '{field_password_confirm}',     'Full label + input for the confirm new password field (confirm step).') ?>
            <?= $tok('kim_reset_form_template', '{field_password_confirm_input}', '{field_password_confirm_input}', 'Just the confirm new password input element (confirm step).') ?>
            <?= $tok('kim_reset_form_template', '{reset_request_url}',          '{reset_request_url}',          'A link to the reset email-entry form (for the invalid-token error message).') ?>
            <?= $tok('kim_reset_form_template', '{submit_button}',              '{submit_button}',              'The submit button.') ?>
            <?= $tok('kim_reset_form_template', '{clear_button}',               '{clear_button}',               'The "Clear" reset button.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Collective Adoption Form ─────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="adoption" <?= $_grp('adoption') ?>>
        <summary class="chevron-head">Adoption Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Controls the layout of the collective adoption application form. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts. Requires the Adoption add-on.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('collective_adoption_form_template', '{site_name}',              '{site_name}',              'The collective / site name.') ?>
            <?= $tok('collective_adoption_form_template', '{field_listing_id}',       '{field_listing_id}',       'The Listing selector field, with label and wrapper.') ?>
            <?= $tok('collective_adoption_form_template', '{field_listing_id_input}', '{field_listing_id_input}', 'Just the Listing select element, no label or wrapper.') ?>
            <?= $tok('collective_adoption_form_template', '{field_name}',             '{field_name}',             'The Name field, with label and wrapper.') ?>
            <?= $tok('collective_adoption_form_template', '{field_name_input}',       '{field_name_input}',       'Just the Name input.') ?>
            <?= $tok('collective_adoption_form_template', '{field_email}',            '{field_email}',            'The Email field, with label and wrapper.') ?>
            <?= $tok('collective_adoption_form_template', '{field_email_input}',      '{field_email_input}',      'Just the Email input.') ?>
            <?= $tok('collective_adoption_form_template', '{field_url}',              '{field_url}',              'The Website URL field (optional), with label and wrapper.') ?>
            <?= $tok('collective_adoption_form_template', '{field_url_input}',        '{field_url_input}',        'Just the Website URL input.') ?>
            <?= $tok('collective_adoption_form_template', '{field_message}',          '{field_message}',          'The Message textarea (optional), with label and wrapper.') ?>
            <?= $tok('collective_adoption_form_template', '{field_message_input}',    '{field_message_input}',    'Just the Message textarea.') ?>
            <?= $tok('collective_adoption_form_template', '{captcha_block}',          '{captcha_block}',          'Full label + input block for the captcha field.') ?>
            <?= $tok('collective_adoption_form_template', '{captcha_block_input}',    '{captcha_block_input}',    'Just the captcha text input element.') ?>
            <?= $tok('collective_adoption_form_template', '{captcha_question}',       '{captcha_question}',       'The arithmetic question text only.') ?>
            <?= $tok('collective_adoption_form_template', '{submit_button}',          '{submit_button}',          'The submit button.') ?>
            <?= $tok('collective_adoption_form_template', '{clear_button}',           '{clear_button}',           'A reset button that clears the form.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- ── Wishlist ───────────────────────────────── -->
      <details class="card chevron" data-token-card data-tpl-group="wishlist" <?= $_grp('wishlist') ?>>
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
      <details class="card chevron" data-token-card data-tpl-group="updates" <?= $_grp('updates') ?>>
        <summary class="chevron-head">Updates Feed Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Wrap each post in {each_update}...{/each_update}. Luster repeats it once per update entry and adds the wrapper and pagination automatically.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('collective_updates_template', '{if_updates}{/if_updates}',       '{if_updates}...{/if_updates}',       'Shows only when there is at least one update on the current page.') ?>
            <?= $tok('collective_updates_template', '{if_no_updates}{/if_no_updates}', '{if_no_updates}...{/if_no_updates}', 'Shows when the current page has no updates (use for an empty state message).') ?>
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
