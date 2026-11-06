<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Thrown before anything is written when someone confirmed where a listing's members
 are, and what's there no longer matches what the Preview step showed them.
*/

declare(strict_types=1);

namespace Luster\Import;

use RuntimeException;

/**
 * The import's Preview listed the listings a tick covers, and they've changed since. A
 * yes only covers what was shown, so the owner goes back to look again.
 */
final class MemberConfirmationChanged extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Something changed since the preview. Check it and confirm again. Nothing was imported.');
    }
}
