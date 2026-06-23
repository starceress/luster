<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Generic error page content fragment. Displays an error title and message when
 an unrecoverable error occurs (e.g. listing not found, invalid request). Served
 as a full page via View::renderPublic() from the application exception handler
 or from controllers.
*/

declare(strict_types=1);

use Luster\Core\View;
?>
<h2 class="fl-heading"><?= View::e($error_title) ?></h2>
<p class="fl-error"><?= View::e($error_message) ?></p>
