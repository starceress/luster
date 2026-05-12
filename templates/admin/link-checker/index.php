<?php

declare(strict_types=1);

use Luster\Core\View;

/** @var \Luster\Models\Affiliate[]      $affiliates */
/** @var \Luster\Models\JoinedListing[]  $joined */
/** @var \Luster\Models\Affiliate[]      $moved_affiliates */
/** @var \Luster\Models\JoinedListing[]  $moved_joined */
/** @var \Luster\Models\Affiliate[]      $dead_affiliates */
/** @var \Luster\Models\JoinedListing[]  $dead_joined */
/** @var array{ok:int,dead:int,redirect:int,unchecked:int} $aff_stats */
/** @var array{ok:int,dead:int,redirect:int,unchecked:int} $joined_stats */
/** @var string $owner_url */
/** @var string $csrf_token */
/** @var string $base_url */
/** @var string $success */
/** @var string $error */

$b = rtrim(View::e($base_url), '/');

$totalMoved = count($moved_affiliates) + count($moved_joined);
$totalDead  = count($dead_affiliates)  + count($dead_joined);

$statusLabel = static function (string $s): string {
    return match ($s) {
        'ok'        => 'OK',
        'dead'      => 'Dead',
        'redirect'  => 'Redirect',
        'found'     => 'Found',
        'missing'   => 'Missing',
        default     => 'Unchecked',
    };
};
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ── Summary strip ───────────────────────────────────────────────────────── -->
<div class="lc-summary-grid">
  <div class="gradient-card lc-summary-card">
    <div class="lc-summary-title">Affiliates <span class="lc-summary-total"><?= count($affiliates) ?></span></div>
    <div class="lc-summary-stats">
      <span class="lc-badge lc-ok"><?= $aff_stats['ok'] ?> OK</span>
      <span class="lc-badge lc-dead"><?= $aff_stats['dead'] ?> Dead</span>
      <span class="lc-badge lc-redirect"><?= $aff_stats['redirect'] ?> Redirect</span>
      <span class="lc-badge lc-unchecked"><?= $aff_stats['unchecked'] ?> Unchecked</span>
    </div>
  </div>
  <div class="gradient-card lc-summary-card">
    <div class="lc-summary-title">Joined Listings <span class="lc-summary-total"><?= count($joined) ?></span></div>
    <div class="lc-summary-stats">
      <span class="lc-badge lc-ok"><?= $joined_stats['ok'] ?> OK</span>
      <span class="lc-badge lc-dead"><?= $joined_stats['dead'] ?> Dead</span>
      <span class="lc-badge lc-redirect"><?= $joined_stats['redirect'] ?> Redirect</span>
      <span class="lc-badge lc-unchecked"><?= $joined_stats['unchecked'] ?> Unchecked</span>
    </div>
  </div>
</div>

<!-- ── Tab navigation ─────────────────────────────────────────────────────── -->
<div class="gradient-card lc-card">
  <div class="lc-tab-nav" role="tablist">
    <button class="lc-tab-btn active" role="tab" aria-selected="true"  aria-controls="lc-panel-affiliates" data-tab="affiliates">
      Affiliates
    </button>
    <button class="lc-tab-btn"        role="tab" aria-selected="false" aria-controls="lc-panel-joined"     data-tab="joined">
      Joined Listings
    </button>
    <button class="lc-tab-btn"        role="tab" aria-selected="false" aria-controls="lc-panel-moved"      data-tab="moved">
      Moved Links<?php if ($totalMoved > 0): ?> <span class="badge badge-warning"><?= $totalMoved ?></span><?php endif; ?>
    </button>
    <button class="lc-tab-btn"        role="tab" aria-selected="false" aria-controls="lc-panel-dead"       data-tab="dead">
      Dead Links<?php if ($totalDead > 0): ?> <span class="badge badge-danger"><?= $totalDead ?></span><?php endif; ?>
    </button>
  </div>

  <!-- ── Affiliates panel ─────────────────────────────────────────────────── -->
  <div id="lc-panel-affiliates" class="lc-tab-panel" role="tabpanel">
    <div class="lc-controls">
      <button class="btn btn-primary btn-sm" id="lc-check-affiliates"
              data-csrf="<?= View::e($csrf_token) ?>"
              data-endpoint="<?= $b ?>/admin/link-checker/check-affiliate"
              data-panel="affiliates">
        Check All Affiliates
      </button>
      <form method="POST" action="<?= $b ?>/admin/link-checker/reset-affiliates" class="lc-reset-form">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <button type="submit" class="btn btn-ghost btn-sm">Reset Statuses</button>
      </form>
      <div class="lc-progress-wrap is-hidden" id="lc-prog-affiliates">
        <div class="lc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100">
          <div class="lc-progress-bar" id="lc-prog-bar-affiliates" style="width:0%"></div>
        </div>
        <span class="lc-prog-label" id="lc-prog-label-affiliates">0 / <?= count($affiliates) ?></span>
      </div>
    </div>

    <?php if (empty($affiliates)): ?>
      <div class="empty">
        <span class="empty-icon"><?= View::icon('link') ?></span>
        No affiliates found. Add some affiliates to start checking links.
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>URL</th>
              <th>Link Status</th>
              <th>Reciprocal</th>
              <th>Last Checked</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($affiliates as $aff): ?>
            <tr id="lc-aff-<?= $aff->id ?>">
              <td><?= View::e($aff->name) ?></td>
              <td>
                <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer" class="link">
                  <?= View::e($aff->url) ?>
                </a>
                <?php if ($aff->linkRedirectUrl !== null): ?>
                  <div class="lc-redirect-info">
                    &rarr; <a href="<?= View::e($aff->linkRedirectUrl) ?>" target="_blank" rel="noopener noreferrer" class="link"><?= View::e($aff->linkRedirectUrl) ?></a>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <span class="lc-badge lc-<?= View::e($aff->linkStatus) ?>" data-lc-link-badge>
                  <?= View::e($statusLabel($aff->linkStatus)) ?>
                </span>
              </td>
              <td>
                <span class="lc-badge lc-<?= View::e($aff->reciprocalStatus) ?>" data-lc-recip-badge>
                  <?= View::e($statusLabel($aff->reciprocalStatus)) ?>
                </span>
              </td>
              <td class="lc-checked-cell" data-lc-checked>
                <?= $aff->linkCheckedAt !== null ? View::e($aff->linkCheckedAt) : '<span class="text-muted">&mdash;</span>' ?>
              </td>
              <td class="table-actions">
                <button class="btn btn-ghost btn-sm js-lc-single"
                        data-id="<?= $aff->id ?>"
                        data-type="affiliate"
                        data-csrf="<?= View::e($csrf_token) ?>"
                        data-endpoint="<?= $b ?>/admin/link-checker/check-affiliate">
                  Check
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── Joined listings panel ────────────────────────────────────────────── -->
  <div id="lc-panel-joined" class="lc-tab-panel is-hidden" role="tabpanel">
    <div class="lc-controls">
      <button class="btn btn-primary btn-sm" id="lc-check-joined"
              data-csrf="<?= View::e($csrf_token) ?>"
              data-endpoint="<?= $b ?>/admin/link-checker/check-joined"
              data-panel="joined">
        Check All Joined
      </button>
      <form method="POST" action="<?= $b ?>/admin/link-checker/reset-joined" class="lc-reset-form">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <button type="submit" class="btn btn-ghost btn-sm">Reset Statuses</button>
      </form>
      <div class="lc-progress-wrap is-hidden" id="lc-prog-joined">
        <div class="lc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100">
          <div class="lc-progress-bar" id="lc-prog-bar-joined" style="width:0%"></div>
        </div>
        <span class="lc-prog-label" id="lc-prog-label-joined">0 / <?= count($joined) ?></span>
      </div>
    </div>

    <?php if (empty($joined)): ?>
      <div class="empty">
        <span class="empty-icon"><?= View::icon('heart') ?></span>
        No joined listings found. Add some joined listings to start checking links.
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>URL</th>
              <th>Status</th>
              <th>Last Checked</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($joined as $jl): ?>
            <tr id="lc-joined-<?= $jl->id ?>">
              <td><?= View::e($jl->name) ?></td>
              <td>
                <a href="<?= View::e($jl->url) ?>" target="_blank" rel="noopener noreferrer" class="link">
                  <?= View::e($jl->url) ?>
                </a>
                <?php if ($jl->linkRedirectUrl !== null): ?>
                  <div class="lc-redirect-info">
                    &rarr; <a href="<?= View::e($jl->linkRedirectUrl) ?>" target="_blank" rel="noopener noreferrer" class="link"><?= View::e($jl->linkRedirectUrl) ?></a>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <span class="lc-badge lc-<?= View::e($jl->linkStatus) ?>" data-lc-link-badge>
                  <?= View::e($statusLabel($jl->linkStatus)) ?>
                </span>
              </td>
              <td class="lc-checked-cell" data-lc-checked>
                <?= $jl->linkCheckedAt !== null ? View::e($jl->linkCheckedAt) : '<span class="text-muted">&mdash;</span>' ?>
              </td>
              <td class="table-actions">
                <button class="btn btn-ghost btn-sm js-lc-single"
                        data-id="<?= $jl->id ?>"
                        data-type="joined"
                        data-csrf="<?= View::e($csrf_token) ?>"
                        data-endpoint="<?= $b ?>/admin/link-checker/check-joined">
                  Check
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── Moved Links panel ────────────────────────────────────────────────── -->
  <div id="lc-panel-moved" class="lc-tab-panel is-hidden" role="tabpanel">
    <?php if ($totalMoved === 0): ?>
      <div class="empty">
        <span class="empty-icon"><?= View::icon('link') ?></span>
        No moved links found. Run the checker on the Affiliates or Joined Listings tabs first.
      </div>
    <?php else: ?>
      <p class="lc-panel-hint">
        These URLs returned a redirect response. Click <strong>Update URL</strong> to replace
        the old URL with the redirect target. The record will be re-checked on the next run.
      </p>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Name</th>
              <th>Current URL</th>
              <th>Redirect Target</th>
              <th>Checked</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($moved_affiliates as $aff): ?>
            <tr>
              <td><span class="lc-type-tag lc-type-affiliate">Affiliate</span></td>
              <td><?= View::e($aff->name) ?></td>
              <td>
                <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer" class="link lc-old-url">
                  <?= View::e($aff->url) ?>
                </a>
              </td>
              <td>
                <?php if ($aff->linkRedirectUrl !== null): ?>
                  <a href="<?= View::e($aff->linkRedirectUrl) ?>" target="_blank" rel="noopener noreferrer" class="link">
                    <?= View::e($aff->linkRedirectUrl) ?>
                  </a>
                <?php else: ?>
                  <span class="text-muted">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="text-muted"><?= $aff->linkCheckedAt !== null ? View::e($aff->linkCheckedAt) : '&mdash;' ?></td>
              <td class="table-actions">
                <?php if ($aff->linkRedirectUrl !== null): ?>
                <form method="POST" action="<?= $b ?>/admin/link-checker/apply-affiliate-redirect">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                  <input type="hidden" name="id"    value="<?= $aff->id ?>">
                  <button type="submit" class="btn btn-primary btn-sm">Update URL</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($moved_joined as $jl): ?>
            <tr>
              <td><span class="lc-type-tag lc-type-joined">Joined</span></td>
              <td><?= View::e($jl->name) ?></td>
              <td>
                <a href="<?= View::e($jl->url) ?>" target="_blank" rel="noopener noreferrer" class="link lc-old-url">
                  <?= View::e($jl->url) ?>
                </a>
              </td>
              <td>
                <?php if ($jl->linkRedirectUrl !== null): ?>
                  <a href="<?= View::e($jl->linkRedirectUrl) ?>" target="_blank" rel="noopener noreferrer" class="link">
                    <?= View::e($jl->linkRedirectUrl) ?>
                  </a>
                <?php else: ?>
                  <span class="text-muted">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="text-muted"><?= $jl->linkCheckedAt !== null ? View::e($jl->linkCheckedAt) : '&mdash;' ?></td>
              <td class="table-actions">
                <?php if ($jl->linkRedirectUrl !== null): ?>
                <form method="POST" action="<?= $b ?>/admin/link-checker/apply-joined-redirect">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                  <input type="hidden" name="id"    value="<?= $jl->id ?>">
                  <button type="submit" class="btn btn-primary btn-sm">Update URL</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── Dead Links panel ─────────────────────────────────────────────────── -->
  <div id="lc-panel-dead" class="lc-tab-panel is-hidden" role="tabpanel">
    <?php if ($totalDead === 0): ?>
      <div class="empty">
        <span class="empty-icon"><?= View::icon('caution') ?></span>
        No dead links found. Run the checker on the Affiliates or Joined Listings tabs first.
      </div>
    <?php else: ?>
      <p class="lc-panel-hint">
        These URLs returned an error or could not be reached. Review and remove or replace
        the records as needed.
      </p>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Name</th>
              <th>URL</th>
              <th>Checked</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dead_affiliates as $aff): ?>
            <tr>
              <td><span class="lc-type-tag lc-type-affiliate">Affiliate</span></td>
              <td><?= View::e($aff->name) ?></td>
              <td>
                <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer" class="link lc-old-url">
                  <?= View::e($aff->url) ?>
                </a>
              </td>
              <td class="text-muted"><?= $aff->linkCheckedAt !== null ? View::e($aff->linkCheckedAt) : '&mdash;' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($dead_joined as $jl): ?>
            <tr>
              <td><span class="lc-type-tag lc-type-joined">Joined</span></td>
              <td><?= View::e($jl->name) ?></td>
              <td>
                <a href="<?= View::e($jl->url) ?>" target="_blank" rel="noopener noreferrer" class="link lc-old-url">
                  <?= View::e($jl->url) ?>
                </a>
              </td>
              <td class="text-muted"><?= $jl->linkCheckedAt !== null ? View::e($jl->linkCheckedAt) : '&mdash;' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
