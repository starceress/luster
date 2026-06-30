<?php

declare(strict_types=1);

/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Canonical legacy (pre-donation-toggle) defaults for the
 collective_affiliates_section_template and collective_codes_template
 lstr_site_settings keys.

 Shared between the migration runner (Migrations 89 & 90, which reset rows
 holding these EXACT values back to NULL so they pick up the new
 {affiliate_apply_toggle} / {codes_submit_toggle} built-in defaults) and the
 regression tests that guard those migrations, so the migrations and the
 tests can never silently drift apart from one another.

 Plain data file (no namespace, no class) so it can be require()'d directly
 by both the standalone, non-autoloaded migration runner and PHPUnit tests.

 Returns an array with two keys, each holding the LF-line-ending literal
 used by the original PHP source. Callers needing the CRLF variant (as HTML
 <textarea> form submissions produce) should derive it with
 str_replace("\n", "\r\n", $value).
*/

return [
    'collective_affiliates_section_template' => '<div class="fl-affiliates-section">' . "\n"
        . '{if_affiliates}' . "\n"
        . '<p><a href="{affiliate_apply_url}">Apply to become an affiliate?</a></p>' . "\n"
        . "\n"
        . '{each_affiliate}' . "\n"
        . '{if_aff_image}<a href="{aff_url}" title="{aff_name}" target="_blank"><img src="{aff_image_url}" alt="{aff_name}"></a>{/if_aff_image}' . "\n"
        . '{if_no_aff_image}<a href="{aff_url}" title="{aff_name}" target="_blank">{aff_name}</a>{/if_no_aff_image}' . "\n"
        . '{/each_affiliate}' . "\n"
        . "\n"
        . '{/if_affiliates}' . "\n"
        . "\n"
        . '{if_no_affiliates}' . "\n"
        . 'No affiliates yet. <a href="{affiliate_apply_url}">Apply to become an affiliate?</a>' . "\n"
        . '{/if_no_affiliates}' . "\n"
        . '</div>',

    'collective_codes_template' => '{if_no_codes}<p>No codes available yet.</p>{/if_no_codes}' . "\n"
        . '{if_codes}' . "\n"
        . "\n"
        . '<div class="fl-codes">' . "\n"
        . '{each_size_group}' . "\n"
        . "\n"
        . '<div class="fl-code-group">' . "\n"
        . '<h2>{group_size}</h2>' . "\n"
        . "\n"
        . '<ul class="fl-code-list">' . "\n"
        . '{each_code}' . "\n"
        . "\n"
        . '<li class="fl-code-item">' . "\n"
        . '<img src="{code_url}" alt="{code_size}" class="fl-code-image">' . "\n"
        . '{if_donor}<span class="fl-code-caption">by {if_donor_url}<a href="{code_donor_url}" target="_blank">{code_donor_name}</a>{/if_donor_url}{if_no_donor_url}{code_donor_name}{/if_no_donor_url}</span>{/if_donor}' . "\n"
        . '{if_code_name}<span class="fl-code-caption">{code_name}</span>{/if_code_name}' . "\n"
        . '</li>' . "\n"
        . "\n"
        . '{/each_code}' . "\n"
        . '</ul>' . "\n"
        . '</div>' . "\n"
        . "\n"
        . '{/each_size_group}' . "\n"
        . '</div>' . "\n"
        . "\n"
        . '{/if_codes}',
];
