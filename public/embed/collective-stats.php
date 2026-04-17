<?php

declare(strict_types=1);

use Luster\Repositories\CollectiveStatsRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Luster embed — collective statistics.
 *
 * Exposes site-wide statistics as PHP variables you can echo directly,
 * plus an optional pre-rendered HTML block. Significantly more capable
 * than Enthusiast's show_collective_stats.php.
 *
 * ──────────────────────────────────────────────────────────────────────
 * Basic usage (variable mode — echo values yourself):
 *
 *   $luster_return = false;   // optional: true = return rendered HTML
 *   require '/absolute/path/to/luster/public/embed/collective-stats.php';
 *
 *   // Then use the exposed variables:
 *   echo $luster_owned_active;        // e.g. 24
 *   echo $luster_owned_newest[0]->name; // e.g. "Always – Amy and Rory"
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
 *   $luster_joined_active       int   Active joined listings
 *   $luster_joined_upcoming     int   Upcoming joined listings
 *   $luster_joined_pending      int   Pending joined listings
 *   $luster_affiliates          int   Active collective affiliates
 *   $luster_members_approved    int   Approved members across all listings
 *   $luster_members_pending     int   Pending members across all listings
 *   $luster_members_total       int   Total members across all listings
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
 *  Convenience (backwards-compat aliases matching Enthusiast naming)
 *   $luster_total_cats          int   Alias of count($luster_categories)
 *   $luster_owned_current       int   Alias of $luster_owned_active
 *   $luster_collective_fans     int   Alias of $luster_members_total
 *
 * ──────────────────────────────────────────────────────────────────────
 * Accessing newest/random listing properties:
 *
 *   // Owned Listing object properties:
 *   $luster_owned_newest[0]->name        // listing title
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
 *     <?= htmlspecialchars($luster_owned_newest[0]->name ?? '', ENT_QUOTES, 'UTF-8') ?>
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

if ($_lusterCached !== null) {
    // Restore scalar data from cache
    $_lusterOwnedCounts  = $_lusterCached['owned_counts'];
    $_lusterJoinedCounts = $_lusterCached['joined_counts'];
    $_lusterAffiliates   = (int) $_lusterCached['affiliates'];
    $_lusterMembers      = $_lusterCached['members'];
    $_lusterGrowthRates  = $_lusterCached['growth_rates'];
    $_lusterCategories   = $_lusterCached['categories'];
} else {
    $_lusterOwnedCounts  = $_lusterStatsRepo->getOwnedCounts();
    $_lusterJoinedCounts = $_lusterStatsRepo->getJoinedCounts();
    $_lusterAffiliates   = $_lusterStatsRepo->getAffiliateCount();
    $_lusterMembers      = $_lusterStatsRepo->getMemberTotals();
    $_lusterGrowthRates  = $_lusterStatsRepo->getGrowthRates(
        $_lusterMembers['approved'],
        $_lusterOwnedCounts['active'],
        $_lusterJoinedCounts['active'],
    );
    $_lusterCategories   = $_lusterStatsRepo->getOwnedCategoryBreakdown();

    // Write cache
    if ($_lusterCacheTtl > 0 && is_dir($_lusterCacheDir) && is_writable($_lusterCacheDir)) {
        file_put_contents($_lusterCacheFile, json_encode([
            'owned_counts'  => $_lusterOwnedCounts,
            'joined_counts' => $_lusterJoinedCounts,
            'affiliates'    => $_lusterAffiliates,
            'members'       => $_lusterMembers,
            'growth_rates'  => $_lusterGrowthRates,
            'categories'    => $_lusterCategories,
        ], JSON_THROW_ON_ERROR));
    }
}

// Newest/random are always fetched fresh (random must rotate; newest should reflect reality)
$_lusterOwnedNewest  = $_lusterStatsRepo->getNewestOwned($_lusterCount);
$_lusterJoinedNewest = $_lusterStatsRepo->getNewestJoined($_lusterCount);
$_lusterOwnedRandom  = $_lusterStatsRepo->getRandomOwned();
$_lusterJoinedRandom = $_lusterStatsRepo->getRandomJoined();

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

// Convenience aliases (Enthusiast-compatible naming)
$luster_total_cats          = count($_lusterCategories);
$luster_owned_current       = $_lusterOwnedCounts['active'];
$luster_collective_fans     = $_lusterMembers['total'];

// ---------------------------------------------------------------------------
// 5. Optional rendered HTML block
// ---------------------------------------------------------------------------

// Load collective stats template from DB (if set)
$_lusterSiteRepo    = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterCStatsTemplate = $_lusterSiteRepo->get('collective_stats_template');
if ($_lusterCStatsTemplate !== null && trim($_lusterCStatsTemplate) === '') {
    $_lusterCStatsTemplate = null;
}

if (!($luster_return ?? false) && $_lusterCStatsTemplate === null) {
    return;
}

// When a DB template is set, do token substitution and return/echo it
if ($_lusterCStatsTemplate !== null) {
    $_lusterE = static fn(string $v): string =>
        htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $_lusterHtml = str_replace(
        [
            '{owned_active}',
            '{owned_upcoming}',
            '{owned_pending}',
            '{joined_active}',
            '{affiliates}',
            '{members_total}',
            '{members_approved}',
            '{members_pending}',
            '{owned_newest_name}',
            '{owned_newest_url}',
            '{owned_random_name}',
            '{owned_random_url}',
        ],
        [
            (string) $luster_owned_active,
            (string) $luster_owned_upcoming,
            (string) $luster_owned_pending,
            (string) $luster_joined_active,
            (string) $luster_affiliates,
            (string) $luster_members_total,
            (string) $luster_members_approved,
            (string) $luster_members_pending,
            $_lusterE(!empty($_lusterOwnedNewest) ? $_lusterOwnedNewest[0]->name : ''),
            $_lusterE(!empty($_lusterOwnedNewest) ? $_lusterOwnedNewest[0]->url  : ''),
            $_lusterE($_lusterOwnedRandom !== null ? $_lusterOwnedRandom->name : ''),
            $_lusterE($_lusterOwnedRandom !== null ? $_lusterOwnedRandom->url  : ''),
        ],
        $_lusterCStatsTemplate,
    );

    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

$_lusterE = static fn(string $v): string =>
    htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

ob_start();
?>
<div class="fl-collective-stats">
  <dl class="fl-stats-grid">
    <dt>Owned listings</dt>
    <dd><?= $luster_owned_active ?> open<?= $luster_owned_upcoming > 0 ? ', ' . $luster_owned_upcoming . ' upcoming' : '' ?></dd>

    <dt>Joined listings</dt>
    <dd><?= $luster_joined_active ?></dd>

    <dt>Total members</dt>
    <dd><?= $luster_members_approved ?> approved<?= $luster_members_pending > 0 ? ', ' . $luster_members_pending . ' pending' : '' ?></dd>

    <dt>Member growth</dt>
    <dd><?= $_lusterE($luster_members_growth_rate) ?> per day</dd>
  </dl>

  <?php if (!empty($_lusterOwnedNewest)): ?>
  <div class="fl-newest-owned">
    <h3>Newest Listings</h3>
    <ul>
      <?php foreach ($_lusterOwnedNewest as $_lusterListing): ?>
      <li>
        <a href="<?= $_lusterE($_lusterListing->url) ?>"><?= $_lusterE($_lusterListing->name) ?></a>
        <?php if ($_lusterListing->subject !== ''): ?>
          <span class="fl-muted">&mdash; <?= $_lusterE($_lusterListing->subject) ?></span>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($_lusterOwnedRandom !== null): ?>
  <div class="fl-random-owned">
    <h3>Featured Listing</h3>
    <p>
      <a href="<?= $_lusterE($_lusterOwnedRandom->url) ?>"><?= $_lusterE($_lusterOwnedRandom->name) ?></a>
      <?php if ($_lusterOwnedRandom->subject !== ''): ?>
        <span class="fl-muted">&mdash; <?= $_lusterE($_lusterOwnedRandom->subject) ?></span>
      <?php endif; ?>
    </p>
  </div>
  <?php endif; ?>

  <?php if (!empty($_lusterCategories)): ?>
  <div class="fl-categories">
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

return $_lusterHtml;
