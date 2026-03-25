<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Owned listings list — content fragment.
 *
 * Variables injected by embed/owned.php:
 *   $listings        Listing[]  filtered list of owned listings
 *   $categories         array         [{id, name, source},...] for the category selector
 *   $current_category   int           selected category ID (0 = all)
 *   $status             string        active status filter: current|upcoming|trouble|all
 *   $hide_dropdown      bool          suppress the category selector entirely
 *   $show_list          bool          show category links as a bulleted list (vs dropdown)
 *   $form_action        string        URL the category filter form/links point to
 *   $uploads_url        string        base URL for listing images
 *   $base_url           string
 *   $theme_path         string
 */
?>
<div>

  <?php if (!$hide_dropdown && !empty($categories)): ?>
    <div>
      <?php if ($show_list): ?>
        <ul>
          <li>
            <a <?= $current_category === 0 ? 'aria-current="true"' : '' ?>
               href="<?= View::e($form_action) ?>">All</a>
          </li>
          <?php foreach ($categories as $cat): ?>
            <li>
              <a <?= $current_category === $cat['id'] ? 'aria-current="true"' : '' ?>
                 href="<?= View::e($form_action . '?cat=' . $cat['id']) ?>">
                <?php if ($cat['parent_name'] !== null): ?>
                  <span><?= View::e($cat['parent_name']) ?> &rsaquo;</span>
                <?php endif; ?>
                <?= View::e($cat['name']) ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <?php
        // Build grouped structure for <optgroup> rendering
        $ownedParentGroups = [];  // parent_id => ['label' => parent_name, 'children' => [...]]
        $ownedTopLevel     = [];  // categories with no parent
        foreach ($categories as $_oc) {
            if ($_oc['parent_id'] !== null) {
                $ownedParentGroups[$_oc['parent_id']] ??= ['label' => (string) $_oc['parent_name'], 'children' => []];
                $ownedParentGroups[$_oc['parent_id']]['children'][] = $_oc;
            } else {
                $ownedTopLevel[] = $_oc;
            }
        }
        ?>
        <form method="GET" action="<?= View::e($form_action) ?>">
          <label for="fl-owned-cat">Category:</label>
          <select id="fl-owned-cat" name="cat"
                  onchange="this.form.submit()">
            <option value="0"<?= $current_category === 0 ? ' selected' : '' ?>>All</option>
            <?php foreach ($ownedTopLevel as $_oc): ?>
              <option value="<?= (int) $_oc['id'] ?>"
                      <?= $current_category === $_oc['id'] ? ' selected' : '' ?>>
                <?= View::e($_oc['name']) ?>
              </option>
            <?php endforeach; ?>
            <?php foreach ($ownedParentGroups as $_grp): ?>
              <optgroup label="<?= View::e($_grp['label']) ?>">
                <?php foreach ($_grp['children'] as $_oc): ?>
                  <option value="<?= (int) $_oc['id'] ?>"
                          <?= $current_category === $_oc['id'] ? ' selected' : '' ?>>
                    <?= View::e($_oc['name']) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
          <noscript><button type="submit">Go</button></noscript>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (empty($listings)): ?>
    <p>No listings to display.</p>
  <?php elseif (($listing_html ?? null) !== null && $listing_html !== ''): ?>
    <!-- Custom DB template listing rows -->
    <ul>
      <?= $listing_html ?>
    </ul>
  <?php else: ?>
    <ul>
      <?php foreach ($listings as $fl): ?>
        <li>
          <a href="<?= View::e($fl->url) ?>"
             target="_blank" rel="noopener noreferrer">
            <?= View::e($fl->name) ?>
          </a>
          <?php if ($fl->subject !== ''): ?>
            &mdash; <?= View::e($fl->subject) ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

</div>
