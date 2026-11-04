<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Add member page. Adds one member to a listing by hand: the listing, the
 member's details, their status, whether to email them, and the chosen
 listing's additional fields. Every listing's fields are rendered as their own
 group, named field_{listing id}_{field name} (MemberController::add() reads
 the same names), so two listings never share a name: browsers treat radios
 with one name as one group even while hidden and disabled. admin.js shows and
 enables only the selected listing's group, so only its answers are posted.
*/

use Luster\Core\Countries;
use Luster\Core\View;

$b     = rtrim(View::e($base_url), '/');
$fe    = $field_errors ?? [];
$old   = $old ?? [];
$selId = (int) ($selected_id ?? 0);
$sel   = $listings[$selId] ?? null;

$backUrl = $sel !== null ? $b . '/admin/members?listing_id=' . $selId . '&status=all' : $b . '/admin/members';

// A posted value to show again. Anything that isn't a string shows as blank.
$oldStr = static fn(string $key, string $default = ''): string =>
    isset($old[$key]) && is_string($old[$key]) ? $old[$key] : $default;

$showUrl  = in_array($oldStr('showurl'), ['1', '0'], true) ? $oldStr('showurl') : '1';
$status   = in_array($oldStr('status'), ['approved', 'pending'], true) ? $oldStr('status') : 'approved';
$emailFan = in_array($oldStr('email_fan'), ['0', '1'], true) ? $oldStr('email_fan') : '0';
?>

<div class="toolbar">
  <a href="<?= $backUrl ?>" class="link">
    &larr; Back to Members<?= $sel !== null ? ': ' . View::e($sel->title) : '' ?>
  </a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/members/add" class="js-warn-unsaved member-edit-form">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon"><?= View::icon('members') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Add Member</div>
        <div class="panel-header-sub">Add one member to a listing by hand.</div>
      </div>
    </div>
    <div class="card-body">
      <div class="callout callout-info">
        <?= View::icon('notes') ?>
        <div>
          <strong>Password:</strong> Luster makes a random password for this member, the same way the join
          form does when a fan leaves it blank. Once they're approved, they can choose their own with the
          listing's Reset Password form.
        </div>
      </div>

      <div class="section-label">General Information</div>

      <div class="field-row<?= !empty($fe['listing_id']) ? ' has-error' : '' ?>">
        <label class="field-label" for="ma-listing">
          Listing <span class="req">*</span>
        </label>
        <div>
          <div class="select-wrap">
            <select id="ma-listing" name="listing_id" required>
              <option value="">&mdash; Select a listing &mdash;</option>
              <?php foreach ($listings as $fl): ?>
                <option value="<?= (int) $fl->id ?>"<?= (int) $fl->id === $selId ? ' selected' : '' ?>>
                  <?= View::e($fl->subject ?: $fl->title) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($listings === []): ?>
            <p class="form-hint">No listing can take new members right now. Closed and Adopted Out listings aren't offered.</p>
          <?php endif; ?>
          <?php if (!empty($fe['listing_id'])): ?>
            <p class="field-error"><?= View::e($fe['listing_id'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['name']) ? ' has-error' : '' ?>">
        <label class="field-label" for="ma-name">
          Name <span class="req">*</span>
        </label>
        <div>
          <input id="ma-name" type="text" name="name" class="form"
                 maxlength="100" required
                 value="<?= View::e($oldStr('name')) ?>">
          <?php if (!empty($fe['name'])): ?>
            <p class="field-error"><?= View::e($fe['name'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['email']) ? ' has-error' : '' ?>">
        <label class="field-label" for="ma-email">
          Email <span class="req">*</span>
        </label>
        <div>
          <input id="ma-email" type="email" name="email" class="form"
                 maxlength="255" required
                 value="<?= View::e($oldStr('email')) ?>">
          <?php if (!empty($fe['email'])): ?>
            <p class="field-error"><?= View::e($fe['email'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['url']) ? ' has-error' : '' ?>">
        <label class="field-label" for="ma-url">Website URL</label>
        <div>
          <input id="ma-url" type="url" name="url" class="form"
                 maxlength="500" placeholder="https://"
                 value="<?= View::e($oldStr('url')) ?>">
          <?php if (!empty($fe['url'])): ?>
            <p class="field-error"><?= View::e($fe['url'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['country']) ? ' has-error' : '' ?>">
        <label class="field-label" for="ma-country">Country</label>
        <div>
          <div class="select-wrap">
            <select id="ma-country" name="country">
              <option value="">&mdash; Select Country &mdash;</option>
              <?php foreach (Countries::list() as $c): ?>
                <option value="<?= View::e($c) ?>"<?= $c === $oldStr('country') ? ' selected' : '' ?>>
                  <?= View::e($c) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if (!empty($fe['country'])): ?>
            <p class="field-error"><?= View::e($fe['country'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['showurl']) ? ' has-error' : '' ?>">
        <span class="field-label">Show Website?</span>
        <div>
          <div class="choice-inline-group">
            <label class="form-check">
              <input type="radio" name="showurl" value="1" data-ma-default<?= $showUrl === '1' ? ' checked' : '' ?>>
              <span class="toggle-label">Show</span>
            </label>
            <label class="form-check">
              <input type="radio" name="showurl" value="0"<?= $showUrl === '0' ? ' checked' : '' ?>>
              <span class="toggle-label">Hide</span>
            </label>
          </div>
          <?php if (!empty($fe['showurl'])): ?>
            <p class="field-error"><?= View::e($fe['showurl'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['status']) ? ' has-error' : '' ?>">
        <span class="field-label">Status</span>
        <div>
          <div class="choice-inline-group">
            <label class="form-check">
              <input type="radio" name="status" value="approved" data-ma-default<?= $status === 'approved' ? ' checked' : '' ?>>
              <span class="toggle-label">Approved</span>
            </label>
            <label class="form-check">
              <input type="radio" name="status" value="pending"<?= $status === 'pending' ? ' checked' : '' ?>>
              <span class="toggle-label">Pending</span>
            </label>
          </div>
          <?php if (!empty($fe['status'])): ?>
            <p class="field-error"><?= View::e($fe['status'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['email_fan']) ? ' has-error' : '' ?>">
        <span class="field-label">Email Fan?</span>
        <div>
          <div class="choice-inline-group">
            <label class="form-check">
              <input type="radio" name="email_fan" value="0" data-ma-default<?= $emailFan === '0' ? ' checked' : '' ?>>
              <span class="toggle-label">Off</span>
            </label>
            <label class="form-check">
              <input type="radio" name="email_fan" value="1"<?= $emailFan === '1' ? ' checked' : '' ?>>
              <span class="toggle-label">On</span>
            </label>
          </div>
          <p class="form-hint">
            Approved sends the approval email now. Pending sends the join email now, with their password,
            just like a real join, and the approval email follows when you approve them.
          </p>
          <?php if (!empty($fe['email_fan'])): ?>
            <p class="field-error"><?= View::e($fe['email_fan'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php foreach ($fields_by_listing as $fid => $fields):
      $fid = (int) $fid;
      $on  = $fid === $selId;
    ?>
    <div class="card-body" data-member-add-fields="<?= $fid ?>"<?= $on ? '' : ' hidden' ?>>
      <div class="section-label">Additional Fields</div>

      <?php foreach ($fields as $field):
        $fKey    = 'field_' . $fid . '_' . $field->name;
        $fId     = 'ma-f-' . $fid . '-' . $field->name;
        $kind    = $field->controlKind();
        $label   = $field->label !== '' ? $field->label : $field->name;
        $dis     = $on ? '' : ' disabled';
        // Posted answers only go back into the selected listing's group.
        $rawOld  = $on ? ($old[$fKey] ?? '') : '';
        $valStr  = is_string($rawOld) ? $rawOld : '';
        $picked  = is_array($rawOld)
            ? array_map('trim', array_filter($rawOld, 'is_string'))
            : ($valStr !== '' ? [trim($valStr)] : []);
        $hasErr  = $on && !empty($fe[$fKey]);
      ?>
        <div class="field-row<?= $hasErr ? ' has-error' : '' ?>">
          <?php if ($kind === 'radio' || $kind === 'checkboxes' || $kind === 'checkbox'): ?>
            <span class="field-label"><?= View::e($label) ?></span>
          <?php else: ?>
            <label class="field-label" for="<?= View::e($fId) ?>"><?= View::e($label) ?></label>
          <?php endif; ?>
          <div>
            <?php if ($kind === 'select'): ?>
              <div class="select-wrap">
                <select id="<?= View::e($fId) ?>" name="<?= View::e($fKey) ?>"<?= $dis ?>>
                  <option value="">&mdash; Select &mdash;</option>
                  <?php foreach ($field->optionList() as $opt): ?>
                    <option value="<?= View::e($opt) ?>"<?= $opt === trim($valStr) ? ' selected' : '' ?>><?= View::e($opt) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php elseif ($kind === 'radio' || $kind === 'checkboxes'): ?>
              <div class="choice-inline-group">
                <?php foreach ($field->optionList() as $n => $opt): ?>
                  <label class="form-check" for="<?= View::e($fId . '-' . $n) ?>">
                    <input id="<?= View::e($fId . '-' . $n) ?>"
                           type="<?= $kind === 'radio' ? 'radio' : 'checkbox' ?>"
                           name="<?= View::e($kind === 'radio' ? $fKey : $fKey . '[]') ?>"
                           value="<?= View::e($opt) ?>"<?= in_array($opt, $picked, true) ? ' checked' : '' ?><?= $dis ?>>
                    <span class="toggle-label"><?= View::e($opt) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php elseif ($kind === 'checkbox'): ?>
              <label class="form-check" for="<?= View::e($fId) ?>">
                <input id="<?= View::e($fId) ?>" type="checkbox" name="<?= View::e($fKey) ?>"
                       value="1"<?= $valStr === '1' ? ' checked' : '' ?><?= $dis ?>>
                <span class="toggle-label">Yes</span>
              </label>
            <?php elseif ($kind === 'textarea'): ?>
              <textarea id="<?= View::e($fId) ?>" name="<?= View::e($fKey) ?>"
                        class="form" rows="3"<?= $dis ?>><?= View::e($valStr) ?></textarea>
            <?php else: ?>
              <input id="<?= View::e($fId) ?>" type="text" name="<?= View::e($fKey) ?>"
                     class="form" maxlength="255"
                     value="<?= View::e($valStr) ?>"<?= $dis ?>>
            <?php endif; ?>
            <?php if ($hasErr): ?>
              <p class="field-error"><?= View::e($fe[$fKey][0]) ?></p>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Add Member</button>
      <a href="<?= $backUrl ?>" class="btn btn-ghost">Cancel</a>
      <button type="button" class="btn btn-ghost" id="ma-clear">Clear</button>
    </div>
  </div>
</form>
