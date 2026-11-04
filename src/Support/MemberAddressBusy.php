<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Thrown when MemberAddressLock can't get every lock it needs. Nothing has been
 read, consumed or written by then, so the only thing to do is say so.
*/

declare(strict_types=1);

namespace Luster\Support;

use RuntimeException;

final class MemberAddressBusy extends RuntimeException
{
    public const MESSAGE = 'Please try again in a moment.';
}
