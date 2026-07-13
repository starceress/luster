<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Fanatically's show-fanatically-kim.php. Drop this file
 and luster-bridge.php into your Fanatically folder to replace the original
 combined KIM (Keep In Mind) member list + sign-up form. The listing is
 auto-detected from Fanatically's $fanatically_listing_id variable.
 Fanatically's file always renders both the member list and the sign-up form
 together with no way to select just one, so this shim does the same by
 requiring Luster's kim-list and kim-signup embeds back to back.

 KNOWN PARITY GAP: Fanatically preselects the current listing in the sign-up
 form's dropdown. Luster's kim-signup embed only preselects from previously
 submitted form data (e.g. after a validation error) — it has no way to
 default to $luster_fanlisting_id — so the dropdown here will show its normal
 "— Select a Listing —" placeholder even though the visitor arrived from this
 specific listing's page. Not broken, just a manual pick instead of a default.
*/

require_once __DIR__ . '/luster-bridge.php';

if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: listing not found. Set $fanatically_listing_id or $luster_listing_id. -->';
    return;
}

$luster_kim_subject_id = (int) $luster_fanlisting_id;
$luster_return          = true;
$_lusterKimListHtml     = (string) require LUSTER_PATH . '/public/embed/kim-list.php';

$luster_return          = true;
$_lusterKimSignupHtml   = (string) require LUSTER_PATH . '/public/embed/kim-signup.php';

echo $_lusterKimListHtml . $_lusterKimSignupHtml;
