#!/usr/bin/env php
<?php
$list = file("container.list", FILE_SKIP_EMPTY_LINES|FILE_IGNORE_NEW_LINES);

require_once 'quietExec.php';
foreach ($list as $c) {
    list($rc, $out, $err) = quietExec("lxc start $c");
    if($rc != 0) {
        if(preg_match("/.*The container is already running.*/", $err)) {
            echo "$c already running\n";
            continue;
        }
        var_dump([$rc, $out, $err]);
        die("Unknown problem starting $c\n");
    }
    echo "$c started\n";
}