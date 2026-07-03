<?php

declare(strict_types=1);

/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 CLI cron entry point for scheduled admin notifications.

 Runs the same three periodic checks that fire on admin page loads, but
 independently of any admin session — so they run even when no admin has
 logged in recently.

 Usage:
   php /path/to/luster/public/cron.php

 Suggested cron schedule (cPanel / plain crontab):
   0 8 * * * php /path/to/luster/public/cron.php >> /path/to/luster/storage/logs/cron.log 2>&1

 Three checks are run each time this script is invoked:
   1. Overdue listings — emails if any listing hasn't been updated in N days.
      Throttled to at most once every 24 hours (overdue_notify_last_sent).
   2. Pending items digest — emails a summary of all pending members, affiliate
      requests, code submissions, etc. Throttled to at most once every 7 days
      (pending_digest_last_sent), and only sent when the queue is non-empty.
   3. Luster update availability — emails once per new release version when a
      newer Luster version is detected on GitHub (update_notified_version).

 All three checks respect their admin-panel on/off toggles
 (overdue_notify_enabled, pending_digest_enabled, update_email_notify) and
 require owner_email to be set in config/app.php.

 This script must be run via PHP CLI. Web requests are refused with a 403.
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

/** @var \Luster\Core\Application $app */
$app = require dirname(__DIR__) . '/bootstrap.php';

$app->runScheduledNotifications(forceUpdateCheck: true);
