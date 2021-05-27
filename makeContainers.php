#!/usr/bin/env php
<?php

$usage = "makeContainers.php [amount] [start]
    default amount is 10
    default start is 0
    if adding more to an existing set start to the first to be make after you last
";

if(!isset($argv[1])) {
    die($usage);
}

$num = $argv[1];
if($num < 1) {
	die($usage);
}

$start = $argv[2] ?? 0;
if($start < 0) {
    die($usage);
}

require_once 'quietExec.php';

for ($i = $start; $i < $num; $i++) {
    $name = "codesand$i";
    list($rc, $out, $err) = quietExec("lxc info $name");
    if($rc == 0) {
        die("container $name already exists, no containers made\n");
    }
}

$list = fopen("container.list", "a+");
if($list === false) {
    die("Couldn't open container.list\n");
}

for ($i = $start; $i < $num; $i++) {
    $name = "codesand$i";
    echo "Creating $name\n";
    list($rc, $out, $err) = quietExec("lxc copy codesand $name");
    if($rc != 0) {
        fclose($list);
        die("Problem encountered, RC=$rc:\n$err\n");
    }
    fwrite($list, "$name\n");
}

fclose($list);

echo "Containers created and container.list appended\n";