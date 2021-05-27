<?php
//little helper because exec() echod
function quietExec($cmd)
{
    $descSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open($cmd, $descSpec, $pipes);
    if (!is_resource($p)) {
        throw new Exception("Unable to execute $cmd\n");
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    return [$rc, $out, $err];
}
