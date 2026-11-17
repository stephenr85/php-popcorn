<?php

/**
 * The child process for ProcessSessionTest. One mode per behaviour a session must report truthfully.
 *
 *   echo    answer every line with `echo:<line>`, exit 0 at stdin EOF
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

while (($line = fgets(STDIN)) !== false) {
    $line = rtrim($line, "\r\n");
    $answer = $mode === 'env' ? json_encode(getenv()) : 'echo:'.$line;
    fwrite(STDOUT, $answer."\n");
    fflush(STDOUT);
}

exit(0);
