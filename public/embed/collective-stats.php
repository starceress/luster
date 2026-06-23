<?php

declare(strict_types=1);

use Luster\Repositories\CollectiveStatsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\DateFormatter;

/**
 * Luster embed — collective statistics.
 *
 * Always renders an HTML block (from the admin template or a built-in
 * default) AND exposes every stat as a PHP variable the host page can
 * echo wherever it likes. Drop-in compatible with Enthusiast's
 * show_collective_stats.php — all the old variable names work.
 *
 * ──────────────────────────────────────────────────────────────────────
 * Basic usage:
 *
 *   require '/absolute/path/to/luster/public/embed/collective-stats.php';
 *   // The rendered stats block is echoed automatically.
 *
 *   // You can also use the individual variables anywhere on the page:
 *   echo $luster_owned_active;        // e.g. 24
 *   echo $luster_owned_newest[0]->title; // e.g. "Always – Amy and Rory"
 *   echo $luster_attribution;         // "Powered by Luster" footer
 *
 * ──────────────────────────────────────────────────────────────────────
 * Optional variables (set before require):
 *
 *   $luster_count     int   How many listings to fetch for newest/random
 *                           arrays. Default: 3. Affects both owned and joined.
 *
 *   $luster_cache_ttl int   Cache lifetime in seconds. Default: 0 (disabled).
 *                           When > 0, serialised stats are written to
 *                           storage/cache/collective_stats.json and re-used
 *                           within the TTL. Newest/random listings are NOT
 *                           cached (they are always fetched fresh so random
 *                           actually rotates).
 *
 *   $luster_return    bool  When true, returns a pre-rendered HTML string
 *                           instead of echoing it. Default: false (echo).
 *
 * ──────────────────────────────────────────────────────────────────────
 * Variables exposed after require (always available):
 *
 *  Counts
 *   $luster_owned               int   Total owned listings
 *   $luster_owned_active        int   Open / active owned (not upcoming/pending)
 *   $luster_owned_upcoming      int   Upcoming owned listings
 *   $luster_owned_pending       int   Pending / inactive owned listings
 *   $luster_joined              int   Total joined listings
 *   $luster_joined_active       int   Active joined listings (any status, active=1)
 *   $luster_joined_approved     int   Approved joined listings (active, not upcoming/pending)
 *   $luster_joined_upcoming     int   Upcoming joined listings
 *   $luster_joined_pending      int   Pending joined listings
 *   $luster_affiliates          int   Active collective affiliates
 *   $luster_members_approved    int   Approved members across all listings
 *   $luster_members_pending     int   Pending members across all listings
 *   $luster_members_total       int   Total members across all listings
 *
 *  Last collective update
 *   $luster_collective_last_updated string  Date the most recently added owned
 *                                            listing was opened, formatted
 *                                            "Month Dth Year" (e.g. "March 1st 2026"),
 *                                            or "Never" if no owned listing has an
 *                                            opened date set.
 *
 *  Growth rates (formatted to 2 decimal places)
 *   $luster_owned_growth_rate   string  Active owned listings per year
 *   $luster_joined_growth_rate  string  Active joined listings per year
 *   $luster_members_growth_rate string  Approved members per day (all listings)
 *
 *  Object arrays (Luster models — full property access, not flat arrays)
 *   $luster_owned_newest        Listing[]        Newest $luster_count active owned
 *   $luster_joined_newest       JoinedListing[]  Newest $luster_count active joined
 *   $luster_owned_random        ?Listing         One random active owned, or null
 *   $luster_joined_random       ?JoinedListing   One random active joined, or null
 *
 *  Category breakdown
 *   $luster_categories  array  [{id, name, source, count}, ...]
 *                              count = active owned listings in that category
 *
 *  Category count
 *   $luster_total_cats          int   count($luster_categories)
 *
 * ──────────────────────────────────────────────────────────────────────
 * Accessing newest/random listing properties:
 *
 *   // Owned Listing object properties:
 *   $luster_owned_newest[0]->title       // listing title
 *   $luster_owned_newest[0]->subject     // subject / topic
 *   $luster_owned_newest[0]->url         // listing URL
 *   $luster_owned_newest[0]->listingtype // e.g. 'listing'
 *   $luster_owned_newest[0]->joined      // opened date string (nullable)
 *   $luster_owned_newest[0]->id          // listing ID
 *
 *   // Joined JoinedListing object properties:
 *   $luster_joined_newest[0]->name       // listing name
 *   $luster_joined_newest[0]->url        // listing URL
 *   $luster_joined_newest[0]->category   // category string
 *   $luster_joined_newest[0]->dateJoined // date joined (nullable)
 *   $luster_joined_newest[0]->image      // image filename (nullable)
 *   $luster_joined_newest[0]->status     // 'joined', 'upcoming', etc.
 *
 * ──────────────────────────────────────────────────────────────────────
 * Example usage (newest owned link):
 *
 *   Newest listing:
 *   <a href="<?= htmlspecialchars($luster_owned_newest[0]->url ?? '#', ENT_QUOTES, 'UTF-8') ?>">
 *     <?= htmlspecialchars($luster_owned_newest[0]->title ?? '', ENT_QUOTES, 'UTF-8') ?>
 *   </a>
 *
 * Example (category list):
 *
 *   <?php foreach ($luster_categories as $cat): ?>
 *     <a href="..."><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></a>
 *     (<?= $cat['count'] ?> listings)<br>
 *   <?php endforeach; ?>
 */

// ---------------------------------------------------------------------------
// 1. Bootstrap Luster
// ---------------------------------------------------------------------------

$_lusterRoot = dirname(__DIR__, 2);

if (!is_file($_lusterRoot . '/vendor/autoload.php') || !is_file($_lusterRoot . '/config/app.php')) {
    $_lusterError = '<p class="fl-errors">Luster embed error: Luster is not installed correctly.</p>';
    if ($luster_return ?? false) {
        return $_lusterError;
    }
    echo $_lusterError;
    return;
}

defined('LUSTER_BASE') || define('LUSTER_BASE', $_lusterRoot);

/** @var \Luster\Core\Application $_lusterApp */
$_lusterApp = require $_lusterRoot . '/bootstrap.php';
$luster_attribution = \Luster\Core\View::attribution();

// ---------------------------------------------------------------------------
// 2. Parameters
// ---------------------------------------------------------------------------

$_lusterCount    = max(1, (int) ($luster_count    ?? 3));
$_lusterCacheTtl = max(0, (int) ($luster_cache_ttl ?? 0));
$_lusterCacheDir = $_lusterRoot . '/storage/cache';
$_lusterCacheFile = $_lusterCacheDir . '/collective_stats.json';

// ---------------------------------------------------------------------------
// 3. Aggregate stats (with optional file cache for scalar/category data)
// ---------------------------------------------------------------------------

$_lusterStatsRepo = new CollectiveStatsRepo(
    $_lusterApp->getDatabase(),
    $_lusterApp->getConfig()
);

$_lusterCached = null;

if ($_lusterCacheTtl > 0 && is_file($_lusterCacheFile)) {
    $mtime = filemtime($_lusterCacheFile);
    if ($mtime !== false && (time() - $mtime) < $_lusterCacheTtl) {
        $_lusterRaw = json_decode(file_get_contents($_lusterCacheFile), true);
        if (is_array($_lusterRaw)) {
            $_lusterCached = $_lusterRaw;
        }
    }
}

// Old caches written before countries_count was added report 0 silently.
// Treat their absence as a miss so the live value is fetched instead.
if ($_lusterCached !== null && !array_key_exists('countries_count', $_lusterCached)) {
    $_lusterCached = null;
}

if ($_lusterCached !== null) {
    // Restore scalar data from cache
    $_lusterOwnedCounts    = $_lusterCached['owned_counts'];
    $_lusterJoinedCounts   = $_lusterCached['joined_counts'];
    $_lusterAffiliates     = (int) $_lusterCached['affiliates'];
    $_lusterMembers        = $_lusterCached['members'];
    $_lusterGrowthRates    = $_lusterCached['growth_rates'];
    $_lusterCategories     = $_lusterCached['categories'];
    $_lusterCountriesCount = (int) ($_lusterCached['countries_count'] ?? 0);
} else {
    $_lusterOwnedCounts    = $_lusterStatsRepo->getOwnedCounts();
    $_lusterJoinedCounts   = $_lusterStatsRepo->getJoinedCounts();
    $_lusterAffiliates     = $_lusterStatsRepo->getAffiliateCount();
    $_lusterMembers        = $_lusterStatsRepo->getMemberTotals();
    $_lusterGrowthRates    = $_lusterStatsRepo->getGrowthRates(
        $_lusterMembers['approved'],
        $_lusterOwnedCounts['active'],
        $_lusterJoinedCounts['active'],
    );
    $_lusterCategories     = $_lusterStatsRepo->getOwnedCategoryBreakdown();
    $_lusterCountriesCount = $_lusterStatsRepo->getDistinctCountryCount();

    // Write cache
    if ($_lusterCacheTtl > 0 && is_dir($_lusterCacheDir) && is_writable($_lusterCacheDir)) {
        file_put_contents($_lusterCacheFile, json_encode([
            'owned_counts'   => $_lusterOwnedCounts,
            'joined_counts'  => $_lusterJoinedCounts,
            'affiliates'     => $_lusterAffiliates,
            'members'        => $_lusterMembers,
            'growth_rates'   => $_lusterGrowthRates,
            'categories'     => $_lusterCategories,
            'countries_count' => $_lusterCountriesCount,
        ], JSON_THROW_ON_ERROR));
    }
}

// Network counts
$_lusterOwnedNetwork  = $_lusterStatsRepo->getOwnedNetworkCounts();
$_lusterJoinedNetwork = $_lusterStatsRepo->getJoinedNetworkCounts();

// Newest/random are always fetched fresh (random must rotate; newest should reflect reality)
$_lusterOwnedNewest  = $_lusterStatsRepo->getNewestOwned($_lusterCount);
$_lusterJoinedNewest = $_lusterStatsRepo->getNewestJoined($_lusterCount);
$_lusterOwnedRandom  = $_lusterStatsRepo->getRandomOwned();
$_lusterJoinedRandom = $_lusterStatsRepo->getRandomJoined();
$_lusterLastOwnedOpened = $_lusterStatsRepo->getLastOwnedOpenedDate();
try {
    $_lusterJoinedCats = (new \Luster\Repositories\JoinedListingRepo($_lusterApp->getDatabase()))->getCategories(true);
} catch (\Throwable) {
    $_lusterJoinedCats = [];
}

// ---------------------------------------------------------------------------
// 4. Expose public variables
// ---------------------------------------------------------------------------

// Counts
$luster_owned               = $_lusterOwnedCounts['total'];
$luster_owned_active        = $_lusterOwnedCounts['active'];
$luster_owned_upcoming      = $_lusterOwnedCounts['upcoming'];
$luster_owned_pending       = $_lusterOwnedCounts['trouble'];

$luster_joined              = $_lusterJoinedCounts['total'];
$luster_joined_active       = $_lusterJoinedCounts['active'];
$luster_joined_approved     = (int) ($_lusterJoinedCounts['approved'] ?? 0);
$luster_joined_upcoming     = $_lusterJoinedCounts['upcoming'];
$luster_joined_pending      = $_lusterJoinedCounts['pending'];

$luster_affiliates          = $_lusterAffiliates;
$luster_members_approved    = $_lusterMembers['approved'];
$luster_members_pending     = $_lusterMembers['pending'];
$luster_members_total       = $_lusterMembers['total'];

// Growth rates
$luster_owned_growth_rate   = $_lusterGrowthRates['owned_per_year'];
$luster_joined_growth_rate  = $_lusterGrowthRates['joined_per_year'];
$luster_members_growth_rate = $_lusterGrowthRates['members_per_day'];

// Object arrays
$luster_owned_newest        = $_lusterOwnedNewest;
$luster_owned_random        = $_lusterOwnedRandom;
$luster_joined_newest       = $_lusterJoinedNewest;
$luster_joined_random       = $_lusterJoinedRandom;

// Category breakdown
$luster_categories          = $_lusterCategories;
$luster_joined_categories   = $_lusterJoinedCats;
$luster_joined_total_cats   = count($_lusterJoinedCats);

// Network counts
$luster_owned_tfl_count     = $_lusterOwnedNetwork['tfl'];
$luster_owned_tafl_count    = $_lusterOwnedNetwork['tafl'];
$luster_joined_tfl_count    = $_lusterJoinedNetwork['tfl'];
$luster_joined_tafl_count   = $_lusterJoinedNetwork['tafl'];

// Countries across all listings (distinct, case-insensitive)
$luster_countries_count     = $_lusterCountriesCount;

// Last collective update — date the most recently added owned listing was opened
$luster_collective_last_updated = $_lusterLastOwnedOpened !== null
    ? DateFormatter::format($_lusterLastOwnedOpened)
    : 'Never';

$luster_total_cats          = count($_lusterCategories);

// Enthusiast-compatible unprefixed aliases
$total_cats                     = $luster_total_cats;
$owned                          = $luster_owned;
$owned_current                  = $luster_owned_active;
$owned_upcoming                 = $luster_owned_upcoming;
$owned_pending                  = $luster_owned_pending;
$joined                         = $luster_joined;
$joined_current                 = $luster_joined_active;
$joined_approved                = $luster_joined_approved;
$joined_upcoming                = $luster_joined_upcoming;
$joined_pending                 = $luster_joined_pending;
$affiliates_collective          = $luster_affiliates;
$collective_affiliates          = $luster_affiliates;
$collective_total_fans_approved = $luster_members_approved;
$collective_total_fans_pending  = $luster_members_pending;
$collective_total_fans          = $luster_members_total;
$collective_categories          = $luster_total_cats;
$countries_count                = $luster_countries_count;
$collective_last_updated        = $luster_collective_last_updated;
$owned_growth_rate              = $luster_owned_growth_rate;
$joined_growth_rate             = $luster_joined_growth_rate;
$collective_fans_growth_rate    = $luster_members_growth_rate;

// ---------------------------------------------------------------------------
// 5. Render HTML block
// ---------------------------------------------------------------------------

$_lusterSiteRepo       = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterCStatsTemplate = $_lusterSiteRepo->get('collective_stats_template');
if ($_lusterCStatsTemplate !== null && trim($_lusterCStatsTemplate) === '') {
    $_lusterCStatsTemplate = null;
}

$_lusterE = static fn(string $v): string =>
    htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if ($_lusterCStatsTemplate !== null) {
    // Admin-defined template — token substitution
    $_lusterCStatsTemplate = (string) preg_replace(
        '/\{if_countries\}(.*?)\{\/if_countries\}/s',
        $_lusterCountriesCount > 0 ? '$1' : '',
        $_lusterCStatsTemplate,
    );
    $_lusterCStatsTemplate = (string) preg_replace(
        '/\{if_pending\}(.*?)\{\/if_pending\}/s',
        $luster_members_pending > 0 ? '$1' : '',
        $_lusterCStatsTemplate,
    );
    $_lusterCStatsTemplate = (string) preg_replace(
        '/\{if_owned_upcoming\}(.*?)\{\/if_owned_upcoming\}/s',
        $luster_owned_upcoming > 0 ? '$1' : '',
        $_lusterCStatsTemplate,
    );
    $_lusterCStatsTemplate = (string) preg_replace(
        '/\{if_owned_pending\}(.*?)\{\/if_owned_pending\}/s',
        $luster_owned_pending > 0 ? '$1' : '',
        $_lusterCStatsTemplate,
    );

    $_lusterSiteName = (string) $_lusterApp->getConfig()->get('site_name', 'Collective');

    $_lusterHtml = str_replace(
        [
            '{site_name}',
            '{owned_active}',
            '{owned_upcoming}',
            '{owned_pending}',
            '{owned_tfl_count}',
            '{owned_tafl_count}',
            '{joined_active}',
            '{joined_pending}',
            '{joined_tfl_count}',
            '{joined_tafl_count}',
            '{members_total}',
            '{members_approved}',
            '{members_pending}',
            '{countries_count}',
            '{owned_newest_title}',
            '{owned_newest_subject}',
            '{owned_newest_url}',
            '{owned_random_title}',
            '{owned_random_subject}',
            '{owned_random_url}',
            '{collective_last_updated}',
            '{joined_total_cats}',
            '{tfl_url}',
            '{tafl_url}',
        ],
        [
            $_lusterE($_lusterSiteName),
            (string) $luster_owned_active,
            (string) $luster_owned_upcoming,
            (string) $luster_owned_pending,
            (string) $luster_owned_tfl_count,
            (string) $luster_owned_tafl_count,
            (string) $luster_joined_active,
            (string) $luster_joined_pending,
            (string) $luster_joined_tfl_count,
            (string) $luster_joined_tafl_count,
            (string) $luster_members_total,
            (string) $luster_members_approved,
            (string) $luster_members_pending,
            (string) $_lusterCountriesCount,
            $_lusterE(!empty($_lusterOwnedNewest) ? $_lusterOwnedNewest[0]->title   : ''),
            $_lusterE(!empty($_lusterOwnedNewest) ? $_lusterOwnedNewest[0]->subject : ''),
            $_lusterE(!empty($_lusterOwnedNewest) ? $_lusterOwnedNewest[0]->url     : ''),
            $_lusterE($_lusterOwnedRandom !== null ? $_lusterOwnedRandom->title   : ''),
            $_lusterE($_lusterOwnedRandom !== null ? $_lusterOwnedRandom->subject : ''),
            $_lusterE($_lusterOwnedRandom !== null ? $_lusterOwnedRandom->url     : ''),
            $_lusterE($luster_collective_last_updated),
            (string) $luster_joined_total_cats,
            'https://thefanlistings.org/',
            'https://www.animefanlistings.com/',
        ],
        $_lusterCStatsTemplate,
    );
} else {
    // Built-in default layout
    ob_start();
    ?>
<div class="fl-cstats">
  <dl class="fl-cstats-grid">
    <dt>Owned listings</dt>
    <dd><?= $luster_owned_active ?> open<?= $luster_owned_upcoming > 0 ? ', ' . $luster_owned_upcoming . ' upcoming' : '' ?></dd>

    <dt>Joined listings</dt>
    <dd><?= $luster_joined_active ?><?= $luster_joined_pending > 0 ? ', ' . $luster_joined_pending . ' pending' : '' ?></dd>

    <dt>Total members</dt>
    <dd><?= $luster_members_approved ?> approved<?= $luster_members_pending > 0 ? ', ' . $luster_members_pending . ' pending' : '' ?></dd>

    <dt>Member growth</dt>
    <dd><?= $_lusterE($luster_members_growth_rate) ?> per day</dd>

    <dt>Collective last updated</dt>
    <dd><?= $_lusterE($luster_collective_last_updated) ?></dd>
  </dl>

  <?php if (!empty($_lusterOwnedNewest)): ?>
  <div class="fl-cstats-newest">
    <h3>Newest Listings</h3>
    <ul>
      <?php foreach ($_lusterOwnedNewest as $_lusterListing): ?>
      <li>
        <a href="<?= $_lusterE($_lusterListing->url) ?>"><?= $_lusterE($_lusterListing->title) ?></a>
        <?php if ($_lusterListing->subject !== ''): ?>
          <span class="fl-muted">&mdash; <?= $_lusterE($_lusterListing->subject) ?></span>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($_lusterOwnedRandom !== null): ?>
  <div class="fl-cstats-random">
    <h3>Featured Listing</h3>
    <p>
      <a href="<?= $_lusterE($_lusterOwnedRandom->url) ?>"><?= $_lusterE($_lusterOwnedRandom->title) ?></a>
      <?php if ($_lusterOwnedRandom->subject !== ''): ?>
        <span class="fl-muted">&mdash; <?= $_lusterE($_lusterOwnedRandom->subject) ?></span>
      <?php endif; ?>
    </p>
  </div>
  <?php endif; ?>

  <?php if (!empty($_lusterCategories)): ?>
  <div class="fl-cstats-categories">
    <h3>Categories</h3>
    <ul>
      <?php foreach ($_lusterCategories as $_lusterCat): ?>
        <?php if ($_lusterCat['count'] > 0): ?>
        <li><?= $_lusterE($_lusterCat['name']) ?> (<?= (int) $_lusterCat['count'] ?>)</li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</div>
    <?php
    $_lusterHtml = ob_get_clean();
}


if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
