<?php
namespace codesand;

use Amp\ByteStream\LineReader;
use Amp\Process\Process;

class Container
{
    public bool $busy = false;
    protected ?Process $proc = null;
    public array $out = [];
    public int $maxlines = 10;

    public function __construct(public string $name)
    {
        if(strtolower($this->getStatus()) != "running") {
            echo "Container $name is not running attempting to start it...\n";
            passthru("lxc start $name");
        }
        if(strtolower($this->getStatus()) != "running") {
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
     */
    function rootExec($exec) {
        return \Amp\call(function () use ($exec) {
            echo " {$this->name} root$ $exec\n";
            $out = yield $this->asyncExec("lxc exec {$this->name} -T -n -- $exec");
            return implode("\n", $out);
        });
    }

    /**
     * Execute command as our user on container
     */
    function userExec($exec) {
        return \Amp\call(function () use ($exec) {
            echo " {$this->name} user$ $exec\n";
            $out = yield $this->asyncExec("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- $exec");
            return implode("\n", $out);
        });
    }

    function asyncExec($cmd) {
        return \Amp\call(function () use ($cmd) {
            $p = new Process($cmd);
            yield $p->start();
            $out = [];
            \Amp\asyncCall(function () use (&$out, $p) {
                $stream = $p->getStdout();
                yield from $this->lineSuck($stream, $out);
            });
            \Amp\asyncCall(function () use (&$out, $p) {
                $stream = $p->getStderr();
                yield from $this->lineSuck($stream, $out);
            });
            yield $p->join();
            return $out;
        });
    }

    function lineSuck($stream, &$out) {
        $lr = new LineReader($stream);
        while (null !== $line = yield $lr->readLine()) {
            $out[] = $line;
        }
    }

    function setMaxLines(int $num) {
        $this->maxlines = $num;
    }

    /**
     * Execute command on host
     */
    function hostExec($exec) {
        return \Amp\call(function () use ($exec) {
            echo " {$this->name} host$ $exec\n";
            $out = yield $this->asyncExec($exec);
            return implode("\n", $out);
        });
    }

    protected $restarting = false;
    function restart() {
        if($this->restarting)
            return;

        //restore will stop anything running but without the kill -9 can take very long
        \Amp\asyncCall(function () {
            $this->restarting = true;
            //During testing with forkbombs etc normal kill methods did not work well and took forever
            //An alternative to this could be lxc stop {$this->>name} --timeout 1 --force
            yield $this->rootExec("killall -9 -u codesand");
            echo "restoring {$this->name}\n";
            yield $this->asyncExec("lxc restore {$this->name} default");
            echo "restored {$this->name}\n";
            $this->busy = false;
            $this->restarting = false;
            //sometimes will have Killed or ERR if timed out
            $this->out = [];
        });
    }

    function sendFile($name, $contents)
    {
        return \Amp\call(function() use ($name, $contents) {
            $fname = "running-{$this->name}-{$name}";
            $file = __DIR__ . "/$fname";
            file_put_contents($file, $contents);
            yield $this->hostExec("lxc file push $file {$this->name}/home/codesand/");
            yield $this->rootExec("chown -R codesand:codesand /home/codesand/");
            return $fname;
        });
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
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile("code.php", "<?php\n$code\n");
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- php /home/codesand/$fname ; echo");
        });
    }

    //TODO We follow commands with an echo in case there was output without a newline, would be nice to not do this

    function runBash(string $code)
    {
        $this->busy = true;
        echo "{$this->name} starting bash code run\n";
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile('code.sh', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- /bin/bash /home/codesand/$fname ; echo");
        });
    }

    function runFish(string $code)
    {
        $this->busy = true;
        echo "{$this->name} starting fish code run\n";
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile('code.fish', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- /usr/bin/fish /home/codesand/$fname ; echo");
        });
    }

    function runPy3(string $code)
    {
        $this->busy = true;
        echo "{$this->name} starting python code run\n";
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile('code.py', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- python3 /home/codesand/$fname ; echo");
        });
    }

    function runPy2(string $code)
    {
        $this->busy = true;
        echo "{$this->name} starting python code run\n";
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile('code.py', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- python2 /home/codesand/$fname ; echo");
        });
    }

    function runPerl(string $code)
    {
        $this->busy = true;
        echo "{$this->name} starting perl code run\n";
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile('code.pl', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- perl /home/codesand/$fname ; echo");
        });
    }

    function runTcl(string $code)
    {
        $this->busy = true;
        echo "{$this->name} starting tcl code run\n";
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile('code.tcl', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- tcl /home/codesand/$fname ; echo");
        });
    }

    function runJava(string $code)
    {
        $this->busy = true;
        echo "{$this->name} starting java code run\n";
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile('code.java', "class code { $code }");
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- bash -c \"javac /home/codesand/$fname && java -cp /home/codesand/ code\" ; echo");
        });
    }

    function runTcc(string $code, string $flags)
    {
        $this->busy = true;
        echo "{$this->name} starting tcc code run\n";
        return \Amp\call(function () use ($code, $flags) {
            $fname = yield $this->sendFile('code.c', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- tcc -run $flags /home/codesand/$fname ; echo");
        });
    }

    function runGcc(string $code, string $flags, string $flagsb)
    {
        $this->busy = true;
        echo "{$this->name} starting gcc code run\n";
        return \Amp\call(function () use ($code, $flags, $flagsb) {
            $fname = yield $this->sendFile('code.c', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- bash -c \"gcc $flags /home/codesand/$fname $flagsb && ./a.out \"; echo");
        });
    }

    function runGpp(string $code, string $flags)
    {
        $this->busy = true;
        echo "{$this->name} starting g++ code run\n";
        return \Amp\call(function () use ($code, $flags) {
            // TODO need to handle #include files g++ doesnt let them all be on one line
            $fname = yield $this->sendFile('code.cpp', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- bash -c \"g++ $flags /home/codesand/$fname && ./a.out \"; echo");
        });
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
            if(count($this->out) > $this->maxlines) {
                $this->out[] = "max lines reached";
                break;
            }
            if (strlen(implode(' ', $this->out)) > 40000) {
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
            if(count($this->out) > $this->maxlines) {
                $this->out[] = "max lines reached";
                break;
            }
            if (strlen(implode(' ', $this->out)) > 40000) {
                break;
            }
        }
    }

    function finish() {
        $this->out = [];
        $this->restart();
    }
}