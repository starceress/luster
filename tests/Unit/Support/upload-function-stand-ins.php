<?php

declare(strict_types=1);

/*
 PHP only lets real form uploads through is_uploaded_file() and move_uploaded_file(),
 and a test has no real form upload. A function defined in a controller's own namespace
 is found before PHP's, so with these loaded the Code Donation form runs all the way
 through. Nothing is copied into public/uploads.
*/

namespace Luster\Controllers\Public;

function is_uploaded_file(string $filename): bool
{
    return is_file($filename);
}

function move_uploaded_file(string $from, string $to): bool
{
    return is_file($from);
}
