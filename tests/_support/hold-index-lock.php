<?php

// Run by LinkIndexTest in a process of its own, as another process writing the link index: it
// takes the index's lock through Craft's own mutex, says "held", and keeps the lock until it
// reads a line on its standard input (not until the input closes: a child process can inherit
// the very pipe end that would close it), then releases it and says "released".

use Tahadudhiya\SmartLinks\services\Index;

require __DIR__ . '/../integration-bootstrap.php';

$mutex = Craft::$app->getMutex();

if (!$mutex->acquire(Index::LOCK, 10)) {
    fwrite(STDOUT, "busy\n");
    exit(1);
}

fwrite(STDOUT, "held\n");

// Blocks until the test says to let go, or gives up after a minute.
stream_set_timeout(STDIN, 60);
fgets(STDIN);

$mutex->release(Index::LOCK);
fwrite(STDOUT, "released\n");
