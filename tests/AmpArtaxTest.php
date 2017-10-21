<?php

namespace Docker\Tests;

use Amp\Artax\DefaultClient;
use Amp\Artax\HttpSocketPool;
use function Amp\call;
use Amp\Loop;
use function Amp\Promise\wait;
use Docker\API\V1_33\Resource\ContainerResource;
use Docker\Docker;
use Docker\Http\UnixFileSocketPool;
use GuzzleHttp\Psr7\Uri;
use Http\Adapter\Artax\Client;
use Http\Client\Common\Plugin\AddHostPlugin;
use Http\Client\Common\PluginClient;

class AmpArtaxTest extends \PHPUnit\Framework\TestCase
{
    /** @var Docker */
    private $docker;

    public function setUp()
    {
        $httpSocketPool = new HttpSocketPool(new UnixFileSocketPool('/var/run/docker.sock'));
        $artaxClient = new DefaultClient(null, $httpSocketPool);

        $httpClient = new PluginClient(new Client($artaxClient), [
            new AddHostPlugin(new Uri('http://localhost')),
        ]);

        $this->docker = Docker::create(TestCase::getVersion(), $httpClient);
    }

    public function testAsync()
    {
        Loop::run(function() {
            wait(call(function () {
                $response = yield $this->docker->container()->containerList(['all' => true], ContainerResource::FETCH_PROMISE);
                var_dump($response->getBody()->getContents());
            }));
        });
    }
}