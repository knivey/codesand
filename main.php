#!/usr/bin/env php
<?php
require __DIR__ . "/vendor/autoload.php";

use Amp\ByteStream\ResourceOutputStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;
use Symfony\Component\Yaml\Yaml;

use codesand\Container;
use codesand\SafeLineReader;

$config = Yaml::parseFile('config.yaml');
//For now putting keys in file, might make a reload call, or switch to a database later
$keys = Yaml::parseFile('keys.yaml');

function validKey($key) {
    global $keys;
    return in_array($key, $keys);
}

$contList = file(__DIR__ . "/container.list", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if(empty($contList)) {
    die("No containers listed");
}

/* @var $containers Container[] */
$containers = [];
foreach($contList as $name) {
    $containers[] = new Container($name);
}

/**
 * Gets next non-busy container or returns false if none
 */
function getContainer() {
    global $containers;
    foreach ($containers as $c) {
        if(!$c->busy)
            return $c;
    }
    return false;
}

Amp\Loop::run(function () {
    global $config;
    $servers = [];
    foreach($config['listen'] as $listen) {
        $servers[] = Socket\Server::listen($listen);
    }

    $logHandler = new StreamHandler(new ResourceOutputStream(\STDOUT));
    $logHandler->setFormatter(new ConsoleFormatter);
    $logger = new Logger('server');
    $logger->pushHandler($logHandler);

    $router = new Router;
    $router->stack(new \Amp\Http\Server\Middleware\CallableMiddleware(function (Request $request, RequestHandler $requestHandler) {
        if(!validKey($request->getHeader("key"))) {
            return new Response(Status::UNAUTHORIZED, ['content-type' => 'text/plain'], 'You need a valid key.');
        }
        return $requestHandler->handleRequest($request);
    }));

    $router->addRoute('GET', '/', new CallableRequestHandler(function () {
        return new Response(Status::OK, ['content-type' => 'text/plain'], 'Hello, world!');
    }));
    $router->addRoute('GET', '/{name}', new CallableRequestHandler(function (Request $request) {
        $args = $request->getAttribute(Router::class);
        return new Response(Status::OK, ['content-type' => 'text/plain'], "Hello, {$args['name']}!");
    }));

    $router->addRoute('POST', '/run/{runner}', new CallableRequestHandler(function (Request $request) {
        $code = yield $request->getBody()->buffer();
        if(!$cont = getContainer()) {
            return new Response(Status::SERVICE_UNAVAILABLE, ['content-type' => 'text/plain'],
                'All containers are busy try later');
        }
        parse_str($request->getUri()->getQuery(), $v);
        $cont->setMaxLines(($v['maxlines'] ?? 10));
        $args = $request->getAttribute(Router::class);
        if(!isset($args['runner'])) { // todo not sure if needed
            return new Response(Status::BAD_REQUEST, [
                "content-type" => "text/plain; charset=utf-8"
            ], "Must specify a runner");
        }
        //TODO some system of detecting if these are installed to run
        switch ($args['runner']) {
            case 'php':
                $reply = yield $cont->runPHP($code);
                break;
            case 'bash':
                $reply = yield $cont->runBash($code);
                break;
            case 'fish':
                $reply = yield $cont->runFish($code);
                break;
            case 'python3':
                $reply = yield $cont->runPy3($code);
                break;
            case 'python2':
                $reply = yield $cont->runPy2($code);
                break;
            case 'tcc':
                $reply = yield $cont->runTcc($code);
                break;
            default:
                return new Response(Status::BAD_REQUEST, [
                    "content-type" => "text/plain; charset=utf-8"
                ], "Not a valid runner: {$args['runner']}");
        }
        $reply = json_encode($reply);
        return new Response(Status::OK, ['content-type' => 'text/plain'], $reply);
    }));

    $server = new Server($servers, $router, $logger);
    yield $server->start();

    // Stop the server when SIGINT is received (this is technically optional, but it is best to call Server::stop()).
    Amp\Loop::onSignal(SIGINT, function (string $watcherId) use ($server) {
        Amp\Loop::cancel($watcherId);
        yield $server->stop();
    });
});

