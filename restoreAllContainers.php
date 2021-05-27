#!/usr/bin/env php
<?php
$list = file("container.list", FILE_SKIP_EMPTY_LINES|FILE_IGNORE_NEW_LINES);

require_once 'quietExec.php';
foreach ($list as $c) {
    list($rc, $out, $err) = quietExec("lxc restore $c default");
    if($rc != 0) {
        var_dump([$rc, $out, $err]);
        die("Unknown problem restoring $c\n");
    }
    echo "$c restored\n";
}