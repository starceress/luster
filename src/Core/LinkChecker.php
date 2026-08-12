<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 HTTP link checker for affiliate and joined-listing URLs. Classifies each
 URL as ok, dead, or redirect and can optionally perform a reciprocal check
 to verify that the target page links back to the owner's site. All outbound
 requests are routed through NetworkAddressGuard to prevent SSRF against
 private/loopback addresses.
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * HTTP link checker for affiliate and joined-listing URLs.
 *
 * check() makes a single non-redirecting GET and classifies the URL as
 * 'ok', 'redirect', or 'dead'. checkReciprocal() fetches a page (following
 * up to MAX_HOPS redirects) and searches the body for the owner's URL.
 *
 * All outbound connections are routed through NetworkAddressGuard to prevent
 * SSRF — any host that resolves to a private/loopback IP is rejected before
 * the TCP connection is opened.
 */
final class LinkChecker
{
    private const TIMEOUT        = 10;
    private const MAX_BODY_BYTES = 262144; // 256 KB — enough for a typical HTML page
    private const MAX_HOPS       = 5;

    /** Common sub-paths tried after a 'missing' reciprocal result (Step 4.2). */
    private const RECIPROCAL_SUB_PATHS = ['/fanlistings/', '/affiliates/', '/links/'];

    /** Anchor-text keywords used to discover a reciprocal-link page when none
     *  of RECIPROCAL_SUB_PATHS match the site's own naming convention. */
    private const RECIPROCAL_LINK_KEYWORDS = [
        'affiliate', 'fanlisting', 'fan listing', 'joined', 'linkback', 'link back', 'links',
    ];

    /** Anchor-text keywords used to discover a members-page link (Step 4.3). */
    private const MEMBERS_LINK_KEYWORDS = ['member', 'fan list', 'fans', 'fan-list', 'join', 'listed'];

    /** Maximum extra pages fetched by the bounded crawl in checkReciprocalDeep()
     *  (Step 4.2b) — real sites often need several hops of exploration (a hub
     *  page linking to a more specific one, then a category picker splitting
     *  content across query-string variants like joined.php?cat=N), and a
     *  page can also have more than one keyword-matching link where only one
     *  of them actually leads anywhere useful. Bounds the total cost. */
    private const RECIPROCAL_CRAWL_MAX_REQUESTS = 30;

    /** Wall-clock ceiling (seconds) for that crawl, independent of request
     *  count — guards against a slow/unresponsive host burning the whole PHP
     *  execution budget during a bulk link-check scan. No set_time_limit()
     *  override exists around this call, so this stays well under a typical
     *  30s default max_execution_time even stacked on top of the four
     *  requests (base check + 3 fixed sub-paths) that run before the crawl
     *  starts — a "found" result completes in ~15-17s against real sites
     *  either way, since it returns as soon as a match turns up. */
    private const RECIPROCAL_CRAWL_MAX_SECONDS = 18.0;

    /** Common member-list path candidates tried during owner-listed discovery (Step 4.3). */
    private const MEMBER_PATH_CANDIDATES = [
        '/members/', '/members.php', '/fans/', '/fans.php',
        '/members/list/', '/members/all/', '/fan-list/',
    ];

    /** Maximum outbound requests per checkOwnerListed() call (Step 4.3). */
    private const OWNER_LISTED_MAX_REQUESTS = 5;

    /**
     * Sends a single GET request (no redirect following) and classifies the URL.
     *
     * Returns:
     *   status       — 'ok' | 'dead' | 'redirect'
     *   redirect_url — the resolved absolute Location URL when status='redirect', else null
     *   http_code    — raw HTTP status code; 0 means the connection failed entirely
     */
    public static function check(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || NetworkAddressGuard::validatePublicHost($host) !== null) {
            return ['status' => 'dead', 'redirect_url' => null, 'http_code' => 0, 'content_flags' => null];
        }

        [$code, $body, $location] = self::singleGet($url);

        if ($code === 0) {
            return ['status' => 'dead', 'redirect_url' => null, 'http_code' => 0, 'content_flags' => null];
        }

        if ($code >= 200 && $code < 300) {
            return [
                'status'        => 'ok',
                'redirect_url'  => null,
                'http_code'     => $code,
                'content_flags' => LinkContentClassifier::classify($body),
            ];
        }

        if ($code >= 300 && $code < 400) {
            $resolved = self::resolveRedirectTarget($url, $location);
            return ['status' => 'redirect', 'redirect_url' => $resolved, 'http_code' => $code, 'content_flags' => null];
        }

        return ['status' => 'dead', 'redirect_url' => null, 'http_code' => $code, 'content_flags' => null];
    }

    /**
     * Fetches $affiliateUrl (manually following redirects hop-by-hop, revalidating
     * each destination host with NetworkAddressGuard) and searches the body for $ownerUrl.
     *
     * Each redirect target is checked against NetworkAddressGuard before fetching,
     * preventing open-redirect SSRF where a public URL redirects to a private host.
     *
     * Matching is scheme- and www-agnostic: `http://example.com/path`,
     * `https://www.example.com/path`, and `//example.com/path` all match
     * an $ownerUrl of `https://example.com/path`.
     *
     * Returns:
     *   'found'     — back-link detected in the response body
     *   'missing'   — fetch succeeded but no back-link found
     *   'unchecked' — fetch failed or host is private (result indeterminate)
     */
    public static function checkReciprocal(string $affiliateUrl, string $ownerUrl): string
    {
        $needle = UrlNormalizer::normalizeUrlForSearch($ownerUrl);
        if ($needle === '') {
            return 'unchecked';
        }

        $url = $affiliateUrl;

        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            $host = parse_url($url, PHP_URL_HOST);
            if (!is_string($host) || $host === '' || NetworkAddressGuard::validatePublicHost($host) !== null) {
                return 'unchecked';
            }

            [$code, $body, $location] = self::singleGet($url);

            if ($code === 0) {
                return 'unchecked';
            }

            if ($code >= 200 && $code < 300) {
                return $body !== '' && stripos($body, $needle) !== false ? 'found' : 'missing';
            }

            if ($code >= 300 && $code < 400) {
                if ($location === null) {
                    return 'unchecked';
                }
                $url = UrlNormalizer::resolveLocation($url, $location);
                continue;
            }

            return 'unchecked';
        }

        return 'unchecked';
    }

    /**
     * Like checkReciprocal() but, on a 'missing' result, tries up to three
     * common sub-paths on the same origin (/fanlistings/, /affiliates/, /links/),
     * then — if still missing — runs a small bounded crawl (breadth-first,
     * same-host only) starting from the homepage to find a reciprocal-link
     * page whose naming convention doesn't match any of the fixed sub-paths.
     *
     * Each page visited during the crawl contributes two kinds of further
     * candidates: same-origin query-string variants of itself (a category
     * picker like joined.php linking to joined.php?cat=1, joined.php?cat=2,
     * ...), and anchor-text keyword matches (RECIPROCAL_LINK_KEYWORDS, the
     * same technique checkOwnerListed() uses for member pages). A single
     * page commonly has more than one keyword match where only one actually
     * leads anywhere useful (e.g. a blog's own category-archive link can
     * coincidentally match "fanlisting" too) — trying every candidate rather
     * than committing to the first is what makes the crawl reliable here.
     * The whole crawl is bounded by RECIPROCAL_CRAWL_MAX_REQUESTS/_SECONDS so
     * a host with many candidates (or a slow host) can't stall a bulk scan.
     *
     * Cross-origin redirect targets of $affiliateUrl are not tried for
     * sub-paths (the sub-paths are always on the original host).
     *
     * Returns 'found', 'missing', or 'unchecked' (same contract as checkReciprocal).
     */
    public static function checkReciprocalDeep(string $affiliateUrl, string $ownerUrl): string
    {
        $result = self::checkReciprocal($affiliateUrl, $ownerUrl);
        if ($result !== 'missing') {
            return $result;
        }

        $scheme = strtolower((string) (parse_url($affiliateUrl, PHP_URL_SCHEME) ?? ''));
        $host   = parse_url($affiliateUrl, PHP_URL_HOST);

        if (!is_string($host) || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return 'missing';
        }

        // Reject if the URL embeds credentials — don't pass user:pass to sub-paths.
        if (parse_url($affiliateUrl, PHP_URL_USER) !== null) {
            return 'missing';
        }

        if (NetworkAddressGuard::validatePublicHost($host) !== null) {
            return 'unchecked';
        }

        foreach (self::RECIPROCAL_SUB_PATHS as $path) {
            $candidate = $scheme . '://' . $host . $path;
            if (self::checkReciprocal($candidate, $ownerUrl) === 'found') {
                return 'found';
            }
        }

        $needle = UrlNormalizer::normalizeUrlForSearch($ownerUrl);
        if ($needle === '') {
            return 'unchecked';
        }

        $visited  = [$affiliateUrl];
        $queue    = [$affiliateUrl];
        $deadline = microtime(true) + self::RECIPROCAL_CRAWL_MAX_SECONDS;
        $budget   = self::RECIPROCAL_CRAWL_MAX_REQUESTS;

        while ($queue !== [] && $budget > 0 && microtime(true) < $deadline) {
            $current = array_shift($queue);
            $budget--;

            $body = self::safeGet($current);
            if ($body === null) {
                continue;
            }

            // The homepage itself was already covered by checkReciprocal() above.
            if ($current !== $affiliateUrl && stripos($body, $needle) !== false) {
                return 'found';
            }

            // Query-string variants first — a page that's already a category
            // picker is a stronger signal than any further keyword match on
            // the same page (which can easily lead somewhere unrelated), so
            // its variants are enqueued ahead of this page's own further
            // keyword matches below. Skipped on the homepage itself: a busy
            // homepage's own same-path query links are usually unrelated
            // noise (e.g. WordPress gallery/attachment links), not a
            // category picker for the section being searched for.
            if ($current !== $affiliateUrl) {
                foreach (self::discoverSameOriginQueryVariants($body, $current) as $variant) {
                    if (in_array($variant, $visited, true)) {
                        continue;
                    }
                    $visited[] = $variant;
                    $queue[]   = $variant;
                }
            }

            foreach (self::discoverLinksByKeywords($body, $current, self::RECIPROCAL_LINK_KEYWORDS, $visited) as $next) {
                $visited[] = $next;
                $queue[]   = $next;
            }
        }

        return 'missing';
    }

    /**
     * Checks whether $ownerName appears as a member on the joined fanlisting at $joinedUrl.
     *
     * Strategy:
     *   1. Fetch $joinedUrl and look for a members-page link in the HTML.
     *   2. If a separate members page is discovered, fetch it.
     *   3. If no link is found, probe MEMBER_PATH_CANDIDATES on the same origin.
     *   4. Search the members-page body for $ownerName (case-insensitive).
     *   5. If pagination markers are detected, never report 'not_found'.
     *
     * Total outbound requests are capped at OWNER_LISTED_MAX_REQUESTS.
     *
     * Returns an array:
     *   status      — 'listed' | 'not_found' | 'unchecked'
     *   members_url — the URL that was actually searched (or null)
     *   heuristic   — true when status='listed'; a name-only match has no duplicate
     *                 protection, so callers must treat it as a heuristic indicator
     */
    public static function checkOwnerListed(string $joinedUrl, string $ownerName): array
    {
        $unchecked = ['status' => 'unchecked', 'members_url' => null, 'heuristic' => false];

        $ownerName = trim($ownerName);
        if ($ownerName === '') {
            return $unchecked;
        }

        $scheme = strtolower((string) (parse_url($joinedUrl, PHP_URL_SCHEME) ?? ''));
        $host   = parse_url($joinedUrl, PHP_URL_HOST);

        if (!is_string($host) || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return $unchecked;
        }

        if (NetworkAddressGuard::validatePublicHost($host) !== null) {
            return $unchecked;
        }

        $requestsLeft = self::OWNER_LISTED_MAX_REQUESTS;

        // Step 1 — fetch the listing's homepage and hunt for a members-page link.
        $homepageBody = self::safeGet($joinedUrl);
        $requestsLeft--;

        $membersUrl  = null;
        $membersBody = null;

        if ($homepageBody !== null) {
            $membersUrl = self::discoverMembersLink($homepageBody, $joinedUrl);

            if ($membersUrl !== null) {
                if ($membersUrl === $joinedUrl) {
                    // The members page IS the homepage — no additional fetch needed.
                    $membersBody = $homepageBody;
                } elseif ($requestsLeft > 0) {
                    // Discovered a separate members page; fetch it now.
                    $membersBody = self::safeGet($membersUrl);
                    $requestsLeft--;
                }
            }
        }

        // Step 2 — if no link was found on the homepage, probe canonical sub-paths.
        if ($membersUrl === null && $requestsLeft > 0) {
            foreach (self::MEMBER_PATH_CANDIDATES as $path) {
                if ($requestsLeft <= 0) {
                    break;
                }
                $candidate     = $scheme . '://' . $host . $path;
                $candidateHost = parse_url($candidate, PHP_URL_HOST);
                if (!is_string($candidateHost) || NetworkAddressGuard::validatePublicHost($candidateHost) !== null) {
                    continue;
                }
                $body = self::safeGet($candidate);
                $requestsLeft--;
                if ($body !== null && trim($body) !== '') {
                    // Body is already fetched; no second request needed.
                    $membersUrl  = $candidate;
                    $membersBody = $body;
                    break;
                }
            }
        }

        if ($membersUrl === null || $membersBody === null) {
            return $unchecked;
        }

        // Step 3 — search the members-page body for the owner name.
        $text = self::normalizeVisibleText($membersBody);
        if (stripos($text, $ownerName) !== false) {
            return ['status' => 'listed', 'members_url' => $membersUrl, 'heuristic' => true];
        }

        // Step 4 — if pagination detected, don't falsely report 'not_found'.
        if (self::hasPaginationHints($membersBody)) {
            return $unchecked;
        }

        return ['status' => 'not_found', 'members_url' => $membersUrl, 'heuristic' => false];
    }

    // -----------------------------------------------------------------------
    // Private helpers for checkOwnerListed
    // -----------------------------------------------------------------------

    /**
     * Scans $html (already fetched) for a hyperlink whose anchor text suggests
     * it leads to a members / fans list page. Returns the absolute URL of the
     * best candidate, or null if none found.
     */
    private static function discoverMembersLink(string $html, string $baseUrl): ?string
    {
        return self::discoverLinkByKeywords($html, $baseUrl, self::MEMBERS_LINK_KEYWORDS);
    }

    /**
     * Convenience wrapper over discoverLinksByKeywords() for callers that only
     * want the single best (first, document-order) match — checkOwnerListed()'s
     * member-page discovery only ever needs one candidate and, unlike
     * checkReciprocalDeep(), doesn't retry further candidates on a miss.
     */
    private static function discoverLinkByKeywords(string $html, string $baseUrl, array $keywords, array $exclude = []): ?string
    {
        return self::discoverLinksByKeywords($html, $baseUrl, $keywords, $exclude)[0] ?? null;
    }

    /**
     * Scans $html (already fetched) for every hyperlink whose anchor text
     * matches one of $keywords (case-insensitive) and resolves to the same
     * host as $baseUrl. Returns all such matches (document order, deduped),
     * excluding anything in $exclude. $exclude should always include
     * $baseUrl itself -- a persistent site-wide nav bar (present on every
     * page, including the one being scanned) very commonly has an entry that
     * keyword-matches and points right back at the current page, which would
     * otherwise "discover" a self-loop instead of real progress.
     *
     * Returning every match rather than just the first matters for
     * checkReciprocalDeep()'s crawl: a single page commonly has more than one
     * keyword match where only one actually leads anywhere useful (e.g. a
     * blog's own category-archive link can coincidentally match "fanlisting"
     * too), so committing to the first candidate found is unreliable.
     *
     * Shared by member-page discovery (checkOwnerListed) and reciprocal-link
     * discovery (checkReciprocalDeep) — same technique, different keyword
     * sets, since guessing a fixed list of common sub-paths doesn't cover
     * every site's own naming convention (e.g. "joined.php").
     *
     * @param string[] $keywords
     * @param string[] $exclude
     * @return list<string>
     */
    private static function discoverLinksByKeywords(string $html, string $baseUrl, array $keywords, array $exclude = []): array
    {
        $exclude[] = $baseUrl;

        // Matches <a href="..."> with anchor text like "members", "fans", "fan list", etc.
        // The inner content is captured with a small length cap rather than a
        // strict "no nested tags" rule and run through strip_tags() below --
        // real anchors often wrap their text in a <span> (badge counts, icons,
        // etc.), and a plain [^<]* capture would never match those at all.
        $pattern = '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.{0,300}?)<\/a>/is';
        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

        // Plain substring matching, not word-boundary: nested tags are commonly
        // stripped without a text separator (e.g. an icon <i> immediately
        // followed by a <span> label), which can fuse two words together with
        // no space between them either way -- "Exit" + "Links" becomes
        // "ExitLinks" (an unrelated false positive), but the exact same
        // pattern also produces "Fan" + "fanlistings" -> "Fanfanlistings" (a
        // genuine match broken by requiring a boundary). The two are
        // structurally indistinguishable from markup alone, so precision
        // isn't attempted here -- returning every match and letting the
        // crawl in checkReciprocalDeep() try each one is what actually
        // handles a false positive like "Exit/Links" correctly: it just
        // becomes one more candidate that doesn't pan out, not something
        // that has to be filtered out up front.
        $found = [];
        foreach ($matches as $m) {
            $anchor = strtolower(trim(strip_tags($m[2])));
            foreach ($keywords as $kw) {
                if (!str_contains($anchor, $kw)) {
                    continue;
                }

                $resolved = UrlNormalizer::resolveLocation($baseUrl, $m[1]);
                $rHost    = parse_url($resolved, PHP_URL_HOST);
                $bHost    = parse_url($baseUrl, PHP_URL_HOST);
                // Only follow same-host links, and never a link already visited.
                if ($rHost === $bHost && !in_array($resolved, $exclude, true)) {
                    $found[$resolved] = true; // dedupe by resolved URL, keep first-seen order
                }
                break; // one match per anchor is enough; move to the next <a>
            }
        }

        return array_keys($found);
    }

    /**
     * Extracts absolute URLs of same-host, same-path links on $html whose
     * query string differs from $baseUrl's own -- the signature of a
     * "category picker" page (e.g. joined.php linking to joined.php?cat=1,
     * joined.php?cat=2, ...). Used as a last-resort fallback in
     * checkReciprocalDeep() when a discovered reciprocal-link page turns out
     * to be an index rather than the actual content.
     *
     * @return list<string>
     */
    private static function discoverSameOriginQueryVariants(string $html, string $baseUrl): array
    {
        preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $html, $matches);

        $baseHost  = parse_url($baseUrl, PHP_URL_HOST);
        $basePath  = parse_url($baseUrl, PHP_URL_PATH) ?? '';
        $baseQuery = parse_url($baseUrl, PHP_URL_QUERY) ?? '';

        $variants = [];
        foreach ($matches[1] as $href) {
            $resolved = UrlNormalizer::resolveLocation($baseUrl, $href);
            $rHost    = parse_url($resolved, PHP_URL_HOST);
            $rPath    = parse_url($resolved, PHP_URL_PATH) ?? '';
            $rQuery   = parse_url($resolved, PHP_URL_QUERY) ?? '';

            if ($rHost !== $baseHost || $rPath !== $basePath || $rQuery === '' || $rQuery === $baseQuery) {
                continue;
            }

            $variants[$resolved] = true; // dedupe by resolved URL
        }

        return array_keys($variants);
    }

    /**
     * Strips HTML tags and decodes entities to produce searchable plain text.
     */
    private static function normalizeVisibleText(string $html): string
    {
        // Remove <script> and <style> blocks first.
        $text = preg_replace('@<(script|style)[^>]*>.*?</\1>@si', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_replace('/\s+/', ' ', $text) ?? $text;
    }

    /**
     * Returns true if $html contains common pagination indicators, which means
     * we cannot conclude a name is absent just because it's not on this page.
     */
    private static function hasPaginationHints(string $html): bool
    {
        $patterns = [
            '/\bpage\s+\d+\s+of\s+\d+/i',
            '/\bnext\s+page\b/i',
            '/[?&]page=\d+/i',
            '/class=["\'][^"\']*\bpaginat/i',
            '/rel=["\']next["\']/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $html)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fetches $url (following up to MAX_HOPS redirects, DNS-pinned and TLS-verified)
     * and returns the response body when the final status is 2xx, or null on any
     * failure (connection error, SSRF guard rejection, non-2xx final code).
     *
     * Uses the same NetworkAddressGuard-validated, 256 KB-capped transport as
     * check() and checkReciprocal(). Safe for fetching TFL/TAFL category pages.
     */
    public static function safeGet(string $url): ?string
    {
        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            $host = parse_url($url, PHP_URL_HOST);
            if (!is_string($host) || $host === '' || NetworkAddressGuard::validatePublicHost($host) !== null) {
                return null;
            }

            [$code, $body, $location] = self::singleGet($url);

            if ($code === 0) {
                return null;
            }

            if ($code >= 200 && $code < 300) {
                return $body;
            }

            if ($code >= 300 && $code < 400 && $location !== null) {
                $url = UrlNormalizer::resolveLocation($url, $location);
                continue;
            }

            return null;
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Makes a single non-redirecting GET request with TLS verification and
     * DNS-pinned connection via CURLOPT_RESOLVE to prevent rebinding.
     *
     * @return array{int, string, ?string}  [http_code, body, location_header_value]
     */
    private static function singleGet(string $url): array
    {
        if (!function_exists('curl_init')) {
            return [0, '', null];
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return [0, '', null];
        }

        $result = NetworkAddressGuard::validateAndResolve($host);
        if ($result['error'] !== null || $result['ips'] === []) {
            return [0, '', null];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [0, '', null];
        }

        $responseHeaders = [];
        $bodyParts       = [];
        $bodyLen         = 0;
        $maxBody         = self::MAX_BODY_BYTES;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LusterLinkChecker/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$responseHeaders): int {
                $responseHeaders[] = $header;
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$bodyParts, &$bodyLen, $maxBody): int {
                $len = strlen($chunk);
                if ($bodyLen + $len > $maxBody) {
                    $bodyParts[] = substr($chunk, 0, $maxBody - $bodyLen);
                    $bodyLen = $maxBody;
                    return 0;
                }
                $bodyParts[] = $chunk;
                $bodyLen += $len;
                return $len;
            },
        ]);

        $port = (int) (parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80));
        curl_setopt($ch, CURLOPT_RESOLVE, [NetworkAddressGuard::buildCurlResolveEntry($host, $port, $result['ips'])]);

        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = implode('', $bodyParts);

        if ($code === 0) {
            return [0, '', null];
        }

        $location = self::parseLocation($responseHeaders);
        return [$code, $body, $location];
    }

    /**
     * Resolves a Location header value against the request URL and returns the
     * absolute redirect target — or null if there is no Location header or the
     * resolved target's host is private/loopback/reserved.
     *
     * A redirect target is never returned unvalidated: it is displayed to the
     * admin and can be applied directly into a public affiliate/joined/member
     * URL field via applyRedirectUrl(), so a private-host target must never
     * surface here.
     */
    private static function resolveRedirectTarget(string $requestUrl, ?string $location): ?string
    {
        if ($location === null) {
            return null;
        }

        $resolved = UrlNormalizer::resolveLocation($requestUrl, $location);
        $resolvedHost = parse_url($resolved, PHP_URL_HOST);

        if (!is_string($resolvedHost) || $resolvedHost === '' || NetworkAddressGuard::validatePublicHost($resolvedHost) !== null) {
            return null;
        }

        return $resolved;
    }

    /**
     * @param string[] $headers
     */
    private static function parseLocation(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Location:') === 0) {
                return trim(substr($header, 9));
            }
        }
        return null;
    }
}
