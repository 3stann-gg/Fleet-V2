<?php

if (($argv[1] ?? null) !== '--rebuild-disposable-test-schema') {
    fwrite(STDERR, "Refusing to run. Pass --rebuild-disposable-test-schema explicitly.\n");
    exit(1);
}

$argv = [dirname(__DIR__).'/bin/run.php', '--prepare-only'];

require __DIR__.'/run.php';
