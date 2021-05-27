<?php
namespace codesand;

use Amp\Process\Process;

class Container
{
    public ?string $timeout;
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
            // TODO Cancel any amp watchers etc
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
        //During testing with forkbombs etc normal kill methods did not work well and took forever
        $this->rootExec("killall -9 -u codesand");
        //restore will stop anything running but without that kill can take very long
        \Amp\asyncCall(function () {
            $this->restarting = true;
            echo "Started: lxc restore {$this->name} default\n";
            $p = new Process("lxc restore {$this->name} default");
            yield $p->start();
            yield $p->join();
            echo "Finished: lxc restore {$this->name} default\n";
            $this->busy = false;
            $this->restarting = false;
        });
        //server is already started after restore, though this could be due to how state was saved
    }

    function timedOut() {
        echo "{$this->name} has timed out\n";
        $this->out[] = "Timeout reached";
        $this->timeout = null;
        $this->restart();
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
        $file = __DIR__ . "/running-{$this->name}.php";
        $code = "<?php\n$code\n";
        file_put_contents($file, $code);
        $this->hostExec("lxc file push $file {$this->name}/home/codesand/");
        $this->rootExec("chown -R codesand:codesand /home/codesand/");
        return $this->runCMD("lxc exec {$this->name} -- su -l codesand -c \"php /home/codesand/running-{$this->name}.php ; echo\"");
    }

    /**
     * Runs cmd on container asyncly capturing output for channel
     * @param $cmd
     */
    function runCMD($cmd) {
        return \Amp\call(function () use ($cmd) {
            $this->busy = true;
            $this->timeout = \Amp\Loop::delay(5000, [$this, 'timedOut']);
            echo "launching Process with: $cmd\n";
            $this->proc = new Process($cmd);
            yield $this->proc->start();
            \Amp\asyncCall([$this, 'getStdout']);
            \Amp\asyncCall([$this, 'getStderr']);
            echo "{$this->name} runCMD joining proc\n";
            yield $this->proc->join();
            echo "{$this->name} runCMD joined proc\n";
            $out = $this->out;
            $this->finish();
            return $out;
        });
    }

    function getStdout() {
        $stream = $this->proc->getStdout();
        $lr = new SafeLineReader($stream);
        while ($this->timeout != null && null !== $line = yield $lr->readLine()) {
            if(trim($line) == '')
                continue;
            $this->out[] = "OUT: $line";
            if(count($this->out) > 10) {
                $this->out[] = "max lines reached";
                $this->restart();
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
        while ($this->timeout != null && null !== $line = yield $lr->readLine()) {
            if(trim($line) == '')
                continue;
            $this->out[] = "ERR: $line";
            if(count($this->out) > 10) {
                $this->out[] = "max lines reached";
                $this->restart();
                break;
            }
            if (strlen(implode(' ', $this->out)) > 4000) {
                break;
            }
        }
    }

    function finish() {
        $this->out = [];

        if($this->timeout != null) {
            \Amp\Loop::cancel($this->timeout);
            $this->timeout = null;
        }

        $this->restart();
    }
}