<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 UNSUPPORTED STUB for Fanatically's show-fanatically-head.php.

 The original file isn't an HTML embed at all — it sets PHP variables
 ($fan_title, $fan_subject, $fan_description, $fan_url, $fan_image_url,
 $fan_listing) for a caller to use directly inside their own <head> section.
 Luster has no equivalent "give me raw listing metadata as variables" embed,
 so this can't be bridged to a real Luster feature the way the other shims
 in this folder are.

 This stub still defines all six variables (to safe, empty defaults) rather
 than only echoing an explanatory comment, because the original file's whole
 purpose is defining them — any existing template that references $fan_title
 etc. after requiring this file would otherwise hit a PHP undefined-variable
 warning. If you need real listing metadata in your <head>, query it directly
 from Luster (e.g. via the stats embed's $luster_description output variable)
 rather than relying on this stub.
*/

if (!isset($fan_title))       { $fan_title = ''; }
if (!isset($fan_subject))     { $fan_subject = ''; }
if (!isset($fan_description)) { $fan_description = ''; }
if (!isset($fan_url))         { $fan_url = ''; }
if (!isset($fan_image_url))   { $fan_image_url = ''; }
if (!isset($fan_listing))     { $fan_listing = []; }

echo '<!-- Luster compat: show-fanatically-head.php has no Luster equivalent. '
   . '$fan_title/$fan_subject/$fan_description/$fan_url/$fan_image_url/$fan_listing '
   . 'are defined as safe empty defaults only — see this file\'s doc comment. -->';
