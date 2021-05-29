<?php
namespace codesand;

use Amp\Process\Process;

class Container
{
    public bool $busy = false;
    protected ?Process $proc = null;
    public array $out = [];

    public function __construct(public string $name)
    {
        if($this->getStatus() != "Running") {
            echo "Container $name is not running attempting to start it...\n";
            passthru("lxc start $name");
        }
        if($this->getStatus() != "Running") {
            die("Container $name still is not running?? status: ".$this->getStatus()."\n");
        }
    }

    public function __destruct()
    {
        //Hopefully this helps leave the containers in a clean state
        //But that wont always be the case if bot dies while running something
        if($this->busy) {
            // TODO Cancel any amp watchers etc, block for restart to finish?
            $this->restart();
        }
    }

    /**
     * @return false|mixed Status of container reported by lxc info
     */
    function getStatus() {
        $r = null;
        exec("lxc info {$this->name}", $r);
        foreach($r as $l) {
            if(preg_match("/^Status: (.+)$/i", $l, $m)) {
                return $m[1];
            }
        }
        return false;
    }

    /**
     * Execute command as root on container
     * @param $exec don't send anything that exits quotes
     * @return string output from exec
     */
    function rootExec($exec) {
        echo " {$this->name} root$ $exec\n";
        $r = null;
        exec("lxc exec {$this->name} -- $exec", $r);
        return implode("\n", $r);
    }

    /**
     * Execute command as our user on container
     * @param $exec don't send anything that exits quotes
     * @return string output from exec
     */
    function userExec($exec) {
        echo " {$this->name} user$ $exec\n";
        //$exec = escapeshellarg($exec);
        $r = null;
        exec("lxc exec {$this->name} -- su -l codesand -c \"$exec\"", $r);
        return implode("\n", $r);
    }

    /**
     * Execute command on host
     * @param $exec don't send anything that exits quotes
     * @return string output from exec
     */
    function hostExec($exec) {
        echo " {$this->name} host$ $exec\n";
        $r = null;
        exec($exec, $r);
        return implode("\n", $r);
    }

    protected $restarting = false;
    function restart() {
        if($this->restarting)
            return;

        //restore will stop anything running but without the kill -9 can take very long
        \Amp\asyncCall(function () {
            $this->restarting = true;
            $run = function($cmd) {
                echo "Started: $cmd\n";
                $p = new Process($cmd);
                yield $p->start();
                yield $p->join();
                echo "Finished: $cmd\n";
            };
            //During testing with forkbombs etc normal kill methods did not work well and took forever
            //An alternative to this could be lxc stop {$this->>name} --timeout 1 --force
            yield from $run("lxc exec {$this->name} -T -n -- killall -9 -u codesand");
            yield from $run("lxc restore {$this->name} default");
            $this->busy = false;
            $this->restarting = false;
            //sometimes will have Killed ir ERR if timed out
            $this->out = [];
        });
    }

    function sendFile($name, $contents)
    {
        $fname = "running-{$this->name}-{$name}";
        $file = __DIR__ . "/$fname";
        file_put_contents($file, $contents);
        $this->hostExec("lxc file push $file {$this->name}/home/codesand/");
        $this->rootExec("chown -R codesand:codesand /home/codesand/");
        return $fname;
    }

    /**
     * Runs PHP code
     * @param string $code
     * @return \Amp\Promise
     */
    function runPHP(string $code)
    {
        $this->busy = true;
        echo "{$this->name} starting php code run\n";
        $fname = $this->sendFile("code.php", "<?php\n$code\n");
        return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- /bin/bash -c \"php /home/codesand/$fname ; echo\"");
    }

    function runBash(string $code)
    {
        $this->busy = true;
        echo "{$this->name} starting bash code run\n";
        $fname = $this->sendFile('code.sh', $code);
        return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- /bin/bash -c \"/bin/bash /home/codesand/$fname ; echo\"");
    }

    function runPy3(string $code)
    {
        $this->busy = true;
        echo "{$this->name} starting python code run\n";
        $fname = $this->sendFile('code.py', $code);
        return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- /bin/bash -c \"python3 /home/codesand/$fname ; echo\"");
    }

    function runPy2(string $code)
    {
        $this->busy = true;
        echo "{$this->name} starting python code run\n";
        $fname = $this->sendFile('code.py', $code);
        return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- /bin/bash -c \"python2 /home/codesand/$fname ; echo\"");
    }

    /**
     * Runs cmd on container asyncly capturing output for channel
     * @param $cmd
     */
    function runCMD($cmd) {
        return \Amp\call(function () use ($cmd) {
            $this->busy = true;
            echo "launching Process with: $cmd\n";
            try {
                $this->proc = new Process($cmd);
                yield $this->proc->start();
                \Amp\asyncCall([$this, 'getStdout']);
                \Amp\asyncCall([$this, 'getStderr']);
                echo "{$this->name} runCMD joining proc\n";
                try {
                    yield \Amp\Promise\timeout($this->proc->join(), 5000);
                    echo "{$this->name} runCMD joined proc\n";
                    if(json_encode($this->out) === false) {
                        $this->out = [json_last_error_msg()];
                    }
                } catch (\Amp\TimeoutException $e) {
                    //$this->proc->kill();
                    if(json_encode($this->out) === false) {
                        $this->out = [json_last_error_msg()];
                    }
                    $this->out[] = "timeout reached";
                    echo "{$this->name} runCMD timed out\n";
                }
            } catch (\Amp\Process\ProcessException $e) {
                $this->out[] = "Exception: " . $e->getMessage();
            }
            $out = $this->out;
            $this->finish();
            return $out;
        });
    }

    function getStdout() {
        $stream = $this->proc->getStdout();
        $lr = new SafeLineReader($stream);
        while (!$this->restarting && null !== $line = yield $lr->readLine()) {
            if(trim($line) == '')
                continue;
            $this->out[] = "OUT: $line";
            if(count($this->out) > 10) {
                $this->out[] = "max lines reached";
                break;
            }
            if (strlen(implode(' ', $this->out)) > 4000) {
                break;
            }
        }
    }

    function getStderr() {
        $stream = $this->proc->getStderr();
        $lr = new SafeLineReader($stream);
        while (!$this->restarting && null !== $line = yield $lr->readLine()) {
            if(trim($line) == '')
                continue;
            $this->out[] = "ERR: $line";
            if(count($this->out) > 10) {
                $this->out[] = "max lines reached";
                break;
            }
            if (strlen(implode(' ', $this->out)) > 4000) {
                break;
            }
        }
    }

    function finish() {
        $this->out = [];
        $this->restart();
    }
}