<?php
namespace codesand;

use Amp\ByteStream\LineReader;
use Amp\Process\Process;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

class Container //implements LoggerAwareInterface
{
    public bool $busy = false;
    protected ?Process $proc = null;
    public array $out = [];
    public int $maxlines = 10;
    private ?\Amp\Deferred $stopEarlyDeferred;

    public function __construct(
        public string $name,
        protected LoggerInterface $log)
    {
        if(strtolower($this->getStatus()) != "running") {
            $this->log->notice("Container $name is not running attempting to start it...");
            passthru("lxc start $name");
        }
        if(strtolower($this->getStatus()) != "running") {
            die("Container $name still is not running?? status: {$this->getStatus()}\n");
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

    public function setLogger(\Psr\Log\LoggerInterface $logger)
    {
        $this->log = $logger;
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
            $this->log->info("root$ $exec");
            $out = yield $this->asyncExec("lxc exec {$this->name} -T -n -- $exec");
            return implode("\n", $out);
        });
    }

    /**
     * Execute command as our user on container
     */
    function userExec($exec) {
        return \Amp\call(function () use ($exec) {
            $this->log->info("user$ $exec");
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
            $this->log->info("host$ $exec");
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
            // Trying not to send SIGKILL to the actual lxc exec command here
            // testing has shown that the above killall /should/ make the lxc exec exit
            // but for some reason \Amp\Process doesn't realize it
            if($this->proc->isRunning()) {
                try {
                    //echo yield $this->rootExec("ps aux");
                    yield \Amp\Promise\timeout($this->proc->join(), 3000);
                } catch (\Amp\TimeoutException $e) {
                    $this->log->info("proc still running, doing proc->kill()");
                    try {
                        $this->proc->kill();
                    } catch (\Exception $e) {
                        $this->log->notice("Exception with kill(): {$e->getMessage()}");
                    }
                } catch (\Exception $e) {
                    $this->log->notice("Exception while killing proc: {$e->getMessage()}");
                }
            }
            $this->log->info("restoring");
            yield $this->asyncExec("lxc restore {$this->name} default");
            $this->log->info("restored");
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
            //this becomes very slow https://github.com/lxc/lxd/issues/3317
            //yield $this->hostExec("lxc file push $file {$this->name}/home/codesand/");
            yield $this->hostExec("tar cf - $fname | lxc exec codesand -- tar xf -");
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
        $this->log->info("starting php code run");
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile("code.php", "<?php\n$code\n");
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- php /home/codesand/$fname ; echo");
        });
    }

    //TODO We follow commands with an echo in case there was output without a newline, would be nice to not do this

    function runBash(string $code)
    {
        $this->busy = true;
        $this->log->info("starting bash code run");
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile('code.sh', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- /bin/bash -l /home/codesand/$fname ; echo");
        });
    }

    function runFish(string $code)
    {
        $this->busy = true;
        $this->log->info("starting fish code run");
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile('code.fish', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- /usr/bin/fish -l /home/codesand/$fname ; echo");
        });
    }

    function runPy3(string $code)
    {
        $this->busy = true;
        $this->log->info("starting python code run");
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile('code.py', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- python3 /home/codesand/$fname ; echo");
        });
    }

    function runPy2(string $code)
    {
        $this->busy = true;
        $this->log->info("starting python code run");
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile('code.py', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- python2 /home/codesand/$fname ; echo");
        });
    }

    function runPerl(string $code)
    {
        $this->busy = true;
        $this->log->info("starting perl code run");
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile('code.pl', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- perl /home/codesand/$fname ; echo");
        });
    }

    function runTcl(string $code)
    {
        $this->busy = true;
        $this->log->info("starting tcl code run");
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile('code.tcl', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- tclsh /home/codesand/$fname ; echo");
        });
    }

    function runJava(string $code)
    {
        $this->busy = true;
        $this->log->info("starting java code run");
        return \Amp\call(function () use ($code) {
            $fname = yield $this->sendFile('code.java', "class code { $code }");
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- bash -c \"javac /home/codesand/$fname && java -cp /home/codesand/ code\" ; echo");
        });
    }

    function runTcc(string $code, string $flags)
    {
        $this->busy = true;
        $this->log->info("starting tcc code run");
        return \Amp\call(function () use ($code, $flags) {
            $fname = yield $this->sendFile('code.c', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- tcc -run $flags /home/codesand/$fname ; echo");
        });
    }

    function runGcc(string $code, string $flags, string $flagsb)
    {
        $this->busy = true;
        $this->log->info("starting gcc code run");
        return \Amp\call(function () use ($code, $flags, $flagsb) {
            $fname = yield $this->sendFile('code.c', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- bash -c \"gcc $flags /home/codesand/$fname $flagsb && ./a.out \"; echo");
        });
    }

    function runGpp(string $code, string $flags)
    {
        $this->busy = true;
        $this->log->info("starting g++ code run");
        return \Amp\call(function () use ($code, $flags) {
            // TODO need to handle #include files g++ doesnt let them all be on one line
            $fname = yield $this->sendFile('code.cpp', $code);
            return $this->runCMD("lxc exec {$this->name} --user 1000 --group 1000 -T --cwd /home/codesand -n -- bash -c \"g++ $flags -O0 /home/codesand/$fname && ./a.out \"; echo", 10000);
        });
    }

    /**
     * Runs cmd on container asyncly capturing output for channel
     * @param $cmd
     */
    function runCMD($cmd, $timeout = 5000) {
        return \Amp\call(function () use ($cmd, $timeout) {
            $this->busy = true;
            $this->log->info("launching Process", compact('cmd'));
            try {
                $this->proc = new Process($cmd);
                yield $this->proc->start();
                \Amp\asyncCall([$this, 'getStdout']);
                \Amp\asyncCall([$this, 'getStderr']);
                $this->log->info("runCMD joining proc");
                try {
                    $this->stopEarlyDeferred = new \Amp\Deferred();
                    yield \Amp\Promise\timeout(\Amp\Promise\first([$this->proc->join(), $this->stopEarlyDeferred->promise()]), $timeout);
                    $this->log->info("runCMD joined proc (or maxlines)");
                    if(json_encode($this->out) === false) {
                        $this->out = [json_last_error_msg()];
                    }
                } catch (\Amp\TimeoutException $e) {
                    if(json_encode($this->out) === false) {
                        $this->out = [json_last_error_msg()];
                    }
                    $this->out[] = "timeout reached";
                    $this->log->info("runCMD timed out");
                }
            } catch (\Amp\Process\ProcessException $e) {
                $this->out[] = "Exception: " . $e->getMessage();
                $this->log->info("ProcessException", compact('e'));
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
                $this->log->info("max lines reached");
                $this->stopEarlyDeferred->resolve();
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
                $this->log->info("max lines reached");
                $this->stopEarlyDeferred->resolve();
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