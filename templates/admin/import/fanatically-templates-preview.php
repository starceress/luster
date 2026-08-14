<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Fanatically template rescue -- step 2. Shows a table of extracted templates with
 translation status, unmapped tokens, and import options. Importable templates can
 be applied to the collective or a specific listing; preview-only entries can be
 downloaded as text files for manual adaptation.
*/
use Luster\Core\View;
$b    = rtrim(View::e($base_url), '/');
$csrf = View::e($csrf_token);

$importable   = array_filter($translations, fn($t) => $t['importable']);
$previewOnly  = array_filter($translations, fn($t) => !$t['importable']);
$hasImportable = !empty($importable);

$labelFor = static function(string $key): string {
    static $map = [
        'stats_template'                => 'Stats',
        'collective_stats_template'     => 'Collective Stats',
        'owned_template'                => 'Owned Listings',
        'joined_template'               => 'Joined Listings',
        'stats_template'                => 'Stats',
        'members_template'              => 'Members List',
        'affiliates_section_template'   => 'Affiliates Section',
        'newsfeed_template'             => 'News Feed',
        'codes_submit_form_template'    => 'Codes Submit Form',
        'join_form_template'            => 'Join Form',
        'affiliate_apply_form_template' => 'Affiliate Apply Form',
        'adopt_form_template'           => 'Adopt Form',
        'contact_form_template'         => 'Contact Form',
        'contact_sent_template'         => 'Contact Sent',
        'update_form_template'          => 'Update Form',
        'reset_form_template'           => 'Reset Form',
        'join'                          => 'Email: Join Confirmation',
        'approve'                       => 'Email: Approval',
        'update'                        => 'Email: Update Confirmation',
        'remove'                        => 'Email: Removal',
        'reset'                         => 'Email: Password Reset',
        'notify_join'                   => 'Email: Admin Join Notification',
        'code_approved'                 => 'Email: Code Approved',
    ];
    return $map[$key] ?? str_replace('_', ' ', ucfirst($key));
};
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/import/fanatically/templates" class="link">&larr; Upload another ZIP</a>
</div>

<?php if (empty($translations) && empty($rejected)): ?>
  <div class="alert alert-error">No template files were found in the uploaded ZIP.</div>
<?php else: ?>

<form method="POST" action="<?= $b ?>/admin/import/fanatically/templates/apply">
  <input type="hidden" name="_csrf" value="<?= $csrf ?>">

  <?php if ($hasImportable): ?>
  <?php
    $hasListingKeys    = !empty(array_filter($importable, fn($t) => ($t['key_scope'] ?? 'listing') === 'listing'));
    $hasCollectiveKeys = !empty(array_filter($importable, fn($t) => ($t['key_scope'] ?? 'listing') === 'collective'));
  ?>
  <div class="card card--gradient">
    <div class="card-body">
      <?php if ($hasListingKeys): ?>
      <div class="field-row">
        <label class="field-label" for="listing_id">Target listing</label>
        <div>
          <div class="select-wrap">
            <select name="listing_id" id="listing_id" class="form">
              <option value="">— choose a listing (required for per-listing &amp; email templates) —</option>
              <?php foreach ($listings as $lst): ?>
                <option value="<?= (int) $lst->id ?>"><?= View::e($lst->subject) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <p class="form-hint">Per-listing and email templates are applied to the selected listing. Leave blank to import collective templates only.</p>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($hasCollectiveKeys): ?>
      <div class="field-row">
        <label class="field-label">Collective templates</label>
        <div>
          <p class="form-hint">Collective templates (collective stats, owned listings, joined listings) are always applied to the collective regardless of the listing chosen above.</p>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($hasImportable): ?>
  <h2 class="section-heading">Templates ready to import</h2>
  <div class="card card--gradient">
    <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th><input type="checkbox" id="select-all-importable" title="Select all"></th>
          <th>Source file</th>
          <th>Luster template</th>
          <th>Saves to</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($importable as $path => $t): ?>
        <tr>
          <td><input type="checkbox" name="selected[]" value="<?= View::e($path) ?>" class="importable-cb"></td>
          <td>
            <code class="mono-sm"><?= View::e(basename($path)) ?></code>
            <?php if ($t['changed_from_default']): ?>
              <span class="badge badge-info" title="This template was customised (differs from Fanatically defaults)">customised</span>
            <?php else: ?>
              <span class="badge badge-inactive" title="This template matches the Fanatically default">default</span>
            <?php endif; ?>
          </td>
          <td><code class="mono-sm"><?= View::e($labelFor($t['target_template_key'] ?? '')) ?></code></td>
          <td>
            <?php if (($t['key_scope'] ?? 'listing') === 'collective'): ?>
              <span class="badge badge-info">Collective</span>
            <?php elseif (str_starts_with($t['target_template_key'] ?? '', 'email:')): ?>
              <span class="badge badge-info">Listing email</span>
            <?php else: ?>
              <span class="badge badge-info">Listing</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($t['unmapped_tokens'])): ?>
              <span class="badge badge-redirect" title="Unmapped tokens: <?= View::e(implode(', ', $t['unmapped_tokens'])) ?>">
                <?= count($t['unmapped_tokens']) ?> unmapped
              </span>
            <?php else: ?>
              <span class="badge badge-current">Ready</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($hasImportable): ?>
  <div class="composer-footer">
    <button type="submit" class="btn btn-primary">Import selected</button>
    <a href="<?= $b ?>/admin/import/fanatically/templates" class="btn btn-ghost">Cancel</a>
  </div>
  <?php endif; ?>

</form>

<?php if (!empty($previewOnly)): ?>
<h2 class="section-heading">Preview-only (download for manual adaptation)</h2>
<div class="card card--gradient">
  <div class="card-body">
    <p class="form-hint">These templates use structures that Luster cannot map automatically (pre-rendered blocks, PHP includes, or form wrappers). Download them as plain text to use as a starting point when writing your Luster templates manually.</p>
  </div>
  <div class="table-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>Source file</th>
        <th>Reason</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($previewOnly as $path => $t): ?>
      <tr>
        <td><code class="mono-sm"><?= View::e(basename($path)) ?></code></td>
        <td>
          <?php if ($t['contains_php']): ?>
            <span class="badge badge-error">Contains PHP</span>
          <?php elseif ($t['unsupported_structure']): ?>
            <span class="badge badge-inactive">Unsupported structure</span>
          <?php elseif ($t['target_template_key'] === null): ?>
            <span class="badge badge-inactive">No matching Luster template</span>
          <?php else: ?>
            <span class="badge badge-inactive">Preview only</span>
          <?php endif; ?>
          <?php if (!empty($t['unmapped_tokens'])): ?>
            &nbsp;<span class="badge badge-redirect" title="<?= View::e(implode(', ', $t['unmapped_tokens'])) ?>"><?= count($t['unmapped_tokens']) ?> unmapped tokens</span>
          <?php endif; ?>
        </td>
        <td>
          <a href="<?= $b ?>/admin/import/fanatically/templates/download?path=<?= urlencode($path) ?>" class="btn btn-xs btn-ghost">Download</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($rejected)): ?>
<h2 class="section-heading">Skipped entries</h2>
<div class="card card--gradient">
  <div class="card-body">
    <p class="form-hint">These files were skipped during extraction — they are either outside the expected template paths, exceeded size limits, or failed security checks.</p>
  </div>
  <div class="table-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>Path</th>
        <th>Reason</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rejected as $entry): ?>
      <tr>
        <td><code class="mono-sm"><?= View::e($entry['path'] ?? '') ?></code></td>
        <td><?= View::e($entry['reason'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>
