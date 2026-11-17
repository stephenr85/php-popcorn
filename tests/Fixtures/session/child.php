<?php

/**
 * The child process for ProcessSessionTest. One mode per behaviour a session must report truthfully.
 *
 *   echo    answer every line with `echo:<line>`, exit 0 at stdin EOF. Two commands are understood:
 *             `flood <bytes>`       write <bytes> of 'x' then "END-MARKER" to stderr, then answer `flooded`
 *             `flood-exit <bytes>`  the same, then exit 0 WITHOUT answering (no host call follows the flood)
 *   env     answer every line with the JSON of the child's whole environment
 *   fail    write a diagnostic to stderr and exit 3 without reading anything
 *   ignore  ignore stdin EOF and keep running until signalled
 *   noisy   write 1 MiB to stderr first, then behave as `echo`
 */
$mode = $argv[1] ?? 'echo';

if ($mode === 'fail') {
    fwrite(STDERR, "child: refusing to start\n");
    exit(3);
}

if ($mode === 'ignore') {
    while (true) {
        usleep(50000);
    }
}

if ($mode === 'noisy') {
    fwrite(STDERR, str_repeat('x', 1024 * 1024));
}

$flood = static function (int $bytes): void {
    $chunk = str_repeat('x', 65536);
    for ($left = $bytes; $left > 0; $left -= 65536) {
        fwrite(STDERR, $left >= 65536 ? $chunk : substr($chunk, 0, $left));
    }
    fwrite(STDERR, "\nEND-MARKER\n");
    fflush(STDERR);
};

while (($line = fgets(STDIN)) !== false) {
    $line = rtrim($line, "\r\n");

    if (preg_match('/^flood(-exit)? (\d+)$/', $line, $m)) {
        $flood((int) $m[2]);
        if ($m[1] === '-exit') {
            exit(0);
        }
        fwrite(STDOUT, "flooded\n");
        fflush(STDOUT);

        continue;
    }

    $answer = $mode === 'env' ? json_encode(getenv()) : 'echo:'.$line;
    fwrite(STDOUT, $answer."\n");
    fflush(STDOUT);
}

exit(0);
