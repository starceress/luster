<?php

declare(strict_types=1);

/*
 Stand-in for sendmail during tests. JoinControllerSubmitTest starts its child
 PHP process with -d sendmail_path pointing here, so every message PHP's mail()
 sends is piped in on stdin and appended to the file named in argv[1] instead
 of going anywhere real.
*/

$out = $argv[1] ?? '';
if ($out === '') {
    fwrite(STDERR, "capture-mail.php: no output file given\n");
    exit(1);
}

$message = (string) stream_get_contents(STDIN);
file_put_contents($out, $message . "\n--LSTR-MAIL-END--\n", FILE_APPEND | LOCK_EX);
exit(0);
