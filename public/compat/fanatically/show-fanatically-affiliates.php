<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Fanatically's show-fanatically-affiliates.php. Drop
 this file and luster-bridge.php into your Fanatically folder to replace the
 original affiliates display + apply form. The listing is auto-detected from
 Fanatically's $fanatically_listing_id variable (0 or unset = collective
 affiliates) and rendered by Luster's affiliates embed, which already builds
 its own apply form inline — no separate embed needs to be required alongside
 it here.

 KNOWN PARITY GAP: Fanatically's original file guarantees the apply form
 always appears somewhere on the page — if its template doesn't reference the
 form directly, Fanatically appends it after the listing anyway. Luster's
 affiliates embed has no equivalent fallback: if this listing's affiliate
 section template is customized and doesn't include the
 {affiliate_apply_toggle}/{affiliate_apply_form} tokens, the apply form simply
 won't appear via this bridge. This only affects sites with a customized
 affiliate template — Luster's default template always includes those tokens.
*/

require_once __DIR__ . '/luster-bridge.php';

// No "listing not found" guard here on purpose: a $luster_fanlisting_id of 0
// is a legitimate, intentional mode for this embed (collective/site-wide
// affiliates), exactly matching Fanatically's own file — not an error state.
$luster_return = false;
require LUSTER_PATH . '/public/embed/affiliates.php';
